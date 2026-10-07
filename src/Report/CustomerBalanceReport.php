<?php
namespace CommunityStoreCredit\Report;

use Concrete\Core\Search\ItemList\ItemList as AbstractItemList;
use Concrete\Core\Search\Pagination\Pagination;
use Concrete\Core\Support\Facade\Database;
use Pagerfanta\Adapter\ArrayAdapter;

/**
 * One row per customer with a financial position: what they owe (unpaid orders), what they hold (store credit)
 * and the resulting net. Customers without debt and without credit are not listed.
 */
class CustomerBalanceReport extends AbstractItemList
{
    const SHOW_ALL = 'all';
    const SHOW_DEBT = 'debt';
    const SHOW_CREDIT = 'credit';
    const SHOW_OPEN = 'open';

    const SORT_COLUMNS = ['debt', 'credit', 'net', 'name', 'lastOrder', 'unpaidOrders'];

    protected $keywords = '';
    protected $show = self::SHOW_ALL;
    protected $sortColumn = 'debt';
    protected $sortDirection = 'desc';
    /** @var array|null */
    protected $rows;

    public function setKeywords($keywords)
    {
        $this->keywords = trim((string) $keywords);
        $this->rows = null;
    }

    public function setShow($show)
    {
        $this->show = in_array($show, [self::SHOW_ALL, self::SHOW_DEBT, self::SHOW_CREDIT, self::SHOW_OPEN], true) ? $show : self::SHOW_ALL;
        $this->rows = null;
    }

    public function setSort($column, $direction = 'desc')
    {
        $this->sortColumn = in_array($column, self::SORT_COLUMNS, true) ? $column : 'debt';
        $this->sortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $this->rows = null;
    }

    public function getShow()
    {
        return $this->show;
    }

    public function getSortColumn()
    {
        return $this->sortColumn;
    }

    public function getSortDirection()
    {
        return $this->sortDirection;
    }

    /**
     * @return array[] the filtered and sorted rows
     */
    public function getRows()
    {
        if ($this->rows === null) {
            $this->rows = $this->load();
        }
        return $this->rows;
    }

    /**
     * Sums over the filtered rows.
     */
    public function getTotals()
    {
        $totals = ['customers' => 0, 'debtors' => 0, 'creditors' => 0, 'debt' => 0.0, 'credit' => 0.0, 'net' => 0.0, 'unpaidOrders' => 0];
        foreach ($this->getRows() as $row) {
            $totals['customers']++;
            $totals['debt'] += $row['debt'];
            $totals['credit'] += $row['credit'];
            $totals['net'] += $row['net'];
            $totals['unpaidOrders'] += $row['unpaidOrders'];
            if ($row['debt'] > 0.005) {
                $totals['debtors']++;
            }
            if ($row['credit'] > 0.005) {
                $totals['creditors']++;
            }
        }
        return $totals;
    }

    /**
     * One customer's row, or null when they have neither debt nor credit.
     */
    public static function forCustomer($uID)
    {
        $report = new self();
        foreach ($report->queryRows((int) $uID) as $row) {
            return $row;
        }
        return null;
    }

    protected function load()
    {
        $rows = $this->queryRows();
        $keywords = mb_strtolower($this->keywords);
        $show = $this->show;
        $rows = array_values(array_filter($rows, function ($row) use ($keywords, $show) {
            if ($keywords !== '' && strpos(mb_strtolower($row['name'] . ' ' . $row['email'] . ' ' . $row['uID']), $keywords) === false) {
                return false;
            }
            switch ($show) {
                case self::SHOW_DEBT:
                    return $row['debt'] > 0.005;
                case self::SHOW_CREDIT:
                    return $row['credit'] > 0.005;
                case self::SHOW_OPEN:
                    return abs($row['net']) > 0.005;
            }
            return true;
        }));
        $column = $this->sortColumn;
        $dir = $this->sortDirection === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($column, $dir) {
            if ($column === 'name') {
                $cmp = strcasecmp($a['name'], $b['name']);
            } elseif ($column === 'lastOrder') {
                $cmp = strcmp((string) $a['lastOrder'], (string) $b['lastOrder']);
            } else {
                $cmp = $a[$column] <=> $b[$column];
            }
            if ($cmp === 0) {
                $cmp = strcasecmp($a['name'], $b['name']);
            }
            return $cmp * $dir;
        });
        return $rows;
    }

    /**
     * @param int|null $onlyUID
     * @return array[]
     */
    protected function queryRows($onlyUID = null)
    {
        $db = Database::connection();
        $unpaid = 'oPaid IS NULL AND oCancelled IS NULL AND oRefunded IS NULL AND externalPaymentRequested IS NULL AND oTotal > 0';
        $sql = 'SELECT u.uID, u.uName, u.uEmail, u.uIsActive,'
            . ' COALESCE(d.debt, 0) AS debt, COALESCE(d.unpaidOrders, 0) AS unpaidOrders, d.oldestUnpaid,'
            . ' COALESCE(c.credit, 0) AS credit, COALESCE(c.creditEntries, 0) AS creditEntries, c.lastCredit,'
            . ' COALESCE(p.paidOrders, 0) AS paidOrders, COALESCE(p.paidTotal, 0) AS paidTotal, p.lastOrder'
            . ' FROM Users u'
            . ' LEFT JOIN (SELECT cID, SUM(oTotal) AS debt, COUNT(*) AS unpaidOrders, MIN(oDate) AS oldestUnpaid FROM CommunityStoreOrders WHERE ' . $unpaid . ' GROUP BY cID) d ON d.cID = u.uID'
            . ' LEFT JOIN (SELECT uID, SUM(amount) AS credit, COUNT(*) AS creditEntries, MAX(createdAt) AS lastCredit FROM csCreditEntries GROUP BY uID) c ON c.uID = u.uID'
            . ' LEFT JOIN (SELECT cID, COUNT(*) AS paidOrders, SUM(oTotal) AS paidTotal, MAX(oDate) AS lastOrder FROM CommunityStoreOrders WHERE oPaid IS NOT NULL AND oCancelled IS NULL AND oRefunded IS NULL GROUP BY cID) p ON p.cID = u.uID'
            . ' WHERE (d.cID IS NOT NULL OR c.uID IS NOT NULL)';
        $params = [];
        if ($onlyUID !== null) {
            $sql .= ' AND u.uID = ?';
            $params[] = $onlyUID;
        }
        $rows = [];
        foreach ($db->fetchAll($sql, $params) as $r) {
            $rows[] = $this->row((int) $r['uID'], (string) $r['uName'], (string) $r['uEmail'], (bool) $r['uIsActive'], $r);
        }
        if ($onlyUID === null || $onlyUID === 0) {
            // unpaid orders without a customer account (guests, deleted users)
            $g = $db->fetchAssoc('SELECT SUM(oTotal) AS debt, COUNT(*) AS unpaidOrders, MIN(oDate) AS oldestUnpaid FROM CommunityStoreOrders WHERE cID = 0 AND ' . $unpaid);
            if ($g && (float) $g['debt'] > 0.005) {
                $rows[] = $this->row(0, t('Guests / no account'), '', false, $g + ['credit' => 0, 'creditEntries' => 0, 'lastCredit' => null, 'paidOrders' => 0, 'paidTotal' => 0, 'lastOrder' => null]);
            }
        }
        return $rows;
    }

    protected function row($uID, $name, $email, $active, array $r)
    {
        $debt = round((float) $r['debt'], 2);
        $credit = round((float) $r['credit'], 2);
        return [
            'uID' => $uID,
            'name' => $name,
            'email' => $email,
            'active' => $active,
            'debt' => $debt,
            'unpaidOrders' => (int) $r['unpaidOrders'],
            'oldestUnpaid' => $r['oldestUnpaid'],
            'credit' => $credit,
            'creditEntries' => (int) $r['creditEntries'],
            'lastCredit' => $r['lastCredit'],
            'paidOrders' => (int) $r['paidOrders'],
            'paidTotal' => round((float) $r['paidTotal'], 2),
            'lastOrder' => $r['lastOrder'],
            'net' => round($credit - $debt, 2),
        ];
    }

    // ---- ItemList plumbing (array backed, like the store's product report)

    public function createQuery()
    {
    }

    public function executeSortBy($column, $direction = 'asc')
    {
    }

    public function executeGetResults()
    {
        return $this->getRows();
    }

    protected function createPaginationObject()
    {
        return new Pagination($this, new ArrayAdapter($this->getRows()));
    }

    public function getTotalResults()
    {
        return count($this->getRows());
    }

    public function getResult($queryRow)
    {
        return $queryRow;
    }

    public function debugStart()
    {
    }

    public function debugStop()
    {
    }
}
