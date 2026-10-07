<?php
namespace CommunityStoreCredit\Service;

use CommunityStoreCredit\Entity\CreditEntry;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Database;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The store credit ledger: balances, histories and the one place that writes entries.
 */
class CreditService
{
    const SOURCE_CHECKOUT = 'checkout';
    const SOURCE_REFUND = 'refund';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_MIGRATION = 'migration';

    /**
     * @param \Concrete\Core\User\User|\Concrete\Core\User\UserInfo|int $user
     * @return int
     */
    public static function userID($user)
    {
        if (is_object($user) && method_exists($user, 'getUserID')) {
            return (int) $user->getUserID();
        }
        return is_numeric($user) ? (int) $user : 0;
    }

    /**
     * @return float
     */
    public function getBalance($user)
    {
        $uID = self::userID($user);
        if (!$uID) {
            return 0.0;
        }
        $sum = Database::connection()->fetchColumn('SELECT COALESCE(SUM(amount), 0) FROM csCreditEntries WHERE uID = ?', [$uID]);
        return round((float) $sum, 2);
    }

    /**
     * @return CreditEntry[] newest first
     */
    public function getHistory($user, $limit = 100, $offset = 0)
    {
        $uID = self::userID($user);
        if (!$uID) {
            return [];
        }
        return $this->em()->getRepository(CreditEntry::class)
            ->findBy(['uID' => $uID], ['createdAt' => 'desc', 'id' => 'desc'], $limit ? (int) $limit : null, (int) $offset);
    }

    /**
     * Balances of every customer with entries, highest first.
     *
     * @return array [['uID' => int, 'balance' => float, 'entries' => int, 'lastEntry' => string], ...]
     */
    public function getBalances($keywords = '')
    {
        $db = Database::connection();
        $sql = 'SELECT e.uID, ROUND(SUM(e.amount), 2) AS balance, COUNT(*) AS entries, MAX(e.createdAt) AS lastEntry, u.uName, u.uEmail'
            . ' FROM csCreditEntries e LEFT JOIN Users u ON u.uID = e.uID';
        $params = [];
        if ($keywords !== '') {
            $sql .= ' WHERE u.uName LIKE ? OR u.uEmail LIKE ? OR e.uID = ?';
            $params = ['%' . $keywords . '%', '%' . $keywords . '%', (int) $keywords];
        }
        $sql .= ' GROUP BY e.uID, u.uName, u.uEmail ORDER BY balance DESC, u.uName ASC';
        return $db->fetchAll($sql, $params);
    }

    /**
     * @return CreditEntry|null
     */
    public function findByReference($source, $externalRef)
    {
        return $this->em()->getRepository(CreditEntry::class)->findOneBy(['source' => (string) $source, 'externalRef' => (string) $externalRef]);
    }

    /**
     * @return CreditEntry|null
     */
    public function findByOrder($orderID)
    {
        return $this->em()->getRepository(CreditEntry::class)->findOneBy(['orderID' => (int) $orderID, 'source' => self::SOURCE_CHECKOUT]);
    }

    /**
     * Books an entry. Idempotent: an entry that already exists for source + externalRef is returned unchanged.
     *
     * @param string|float $amount positive adds credit, negative spends it
     * @return CreditEntry
     * @throws \InvalidArgumentException
     * @throws InsufficientCreditException when spending more than the balance
     */
    public function add($user, $amount, $comment, $source, $externalRef, $createdByUID = null, $orderID = null)
    {
        $uID = self::userID($user);
        if (!$uID) {
            throw new \InvalidArgumentException('Store credit: an entry needs a user.');
        }
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('Store credit: the amount must be numeric.');
        }
        if ($source === '' || $externalRef === '') {
            throw new \InvalidArgumentException('Store credit: an entry needs a source and a reference.');
        }
        $existing = $this->findByReference($source, $externalRef);
        if ($existing) {
            return $existing;
        }
        $em = $this->em();
        $db = Database::connection();
        try {
            return $em->transactional(function () use ($em, $db, $uID, $amount, $comment, $source, $externalRef, $createdByUID, $orderID) {
                if ((float) $amount < 0) {
                    // serialise spending per user so two checkouts cannot both pass the balance check
                    $db->executeQuery('SELECT id FROM csCreditEntries WHERE uID = ? FOR UPDATE', [$uID]);
                    $balance = $this->getBalance($uID);
                    if (round($balance + (float) $amount, 2) < 0) {
                        throw new InsufficientCreditException($balance, -(float) $amount);
                    }
                }
                $entry = new CreditEntry($uID, $amount, $comment, $source, $externalRef, $createdByUID, $orderID);
                $em->persist($entry);
                $em->flush();
                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            Application::getFacadeApplication()->make('log')->warning(sprintf('Store credit: refused duplicate entry %s:%s for user %d.', $source, $externalRef, $uID));
            throw $e;
        }
    }

    /**
     * Spends credit for a checkout.
     */
    public function charge($user, $amount, $comment, $externalRef, $createdByUID = null)
    {
        return $this->add($user, -abs((float) $amount), $comment, self::SOURCE_CHECKOUT, $externalRef, $createdByUID);
    }

    public function attachOrder(CreditEntry $entry, $orderID)
    {
        $entry->setOrderID($orderID);
        $this->em()->persist($entry);
        $this->em()->flush();
    }

    /**
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    public function em()
    {
        return Database::connection()->getEntityManager();
    }
}
