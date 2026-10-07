<?php
namespace CommunityStoreCredit\Service;

class InsufficientCreditException extends \RuntimeException
{
    protected $balance;
    protected $requested;

    public function __construct($balance, $requested)
    {
        $this->balance = (float) $balance;
        $this->requested = (float) $requested;
        parent::__construct(sprintf('Store credit: balance %.2f does not cover %.2f.', $this->balance, $this->requested));
    }

    public function getBalance()
    {
        return $this->balance;
    }

    public function getRequested()
    {
        return $this->requested;
    }
}
