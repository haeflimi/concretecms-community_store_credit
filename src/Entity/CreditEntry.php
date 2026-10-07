<?php
namespace CommunityStoreCredit\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One movement of a customer's store credit. Append-only: a correction is a new entry with the opposite sign.
 * `source` + `externalRef` identify the business event (a checkout, an order cancellation, a migration, a manual
 * booking); the unique index makes every booking idempotent.
 *
 * @ORM\Entity()
 * @ORM\Table(name="csCreditEntries",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="csc_entry_ref", columns={"source", "externalRef"})},
 *     indexes={@ORM\Index(name="csc_entry_user", columns={"uID"}), @ORM\Index(name="csc_entry_order", columns={"orderID"})}
 * )
 */
class CreditEntry
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\Column(type="integer")
     */
    protected $uID;

    /**
     * Positive = credit added, negative = credit spent.
     *
     * @ORM\Column(type="decimal", precision=12, scale=2)
     */
    protected $amount;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $comment;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $source;

    /**
     * @ORM\Column(type="string", length=190)
     */
    protected $externalRef;

    /**
     * Community Store order this entry paid for or refunds, if any.
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $orderID;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $createdByUID;

    public function __construct($uID, $amount, $comment, $source, $externalRef, $createdByUID = null, $orderID = null)
    {
        $this->uID = (int) $uID;
        $this->amount = self::normalizeAmount($amount);
        $this->comment = $comment === null ? null : (string) $comment;
        $this->source = (string) $source;
        $this->externalRef = (string) $externalRef;
        $this->createdByUID = $createdByUID ? (int) $createdByUID : null;
        $this->orderID = $orderID ? (int) $orderID : null;
        $this->createdAt = new \DateTime('now');
    }

    public static function normalizeAmount($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    public function getID()
    {
        return $this->id;
    }

    public function getUserID()
    {
        return (int) $this->uID;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return (float) $this->amount;
    }

    public function getComment()
    {
        return $this->comment;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getExternalRef()
    {
        return $this->externalRef;
    }

    public function getOrderID()
    {
        return $this->orderID === null ? null : (int) $this->orderID;
    }

    public function setOrderID($orderID)
    {
        $this->orderID = $orderID ? (int) $orderID : null;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function getCreatedByUID()
    {
        return $this->createdByUID === null ? null : (int) $this->createdByUID;
    }
}
