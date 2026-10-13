<?php
namespace CommunityStoreCredit\Service;

use CommunityStoreOpenOrders\OpenOrders;

/**
 * The customer's store credit against what they owe on open orders and event tabs (community_store_open_orders).
 */
class AccountOverview
{
    /** @var CreditService */
    protected $credit;

    /** @var OpenOrders */
    protected $orders;

    public function __construct(CreditService $credit, OpenOrders $orders)
    {
        $this->credit = $credit;
        $this->orders = $orders;
    }

    /**
     * Credit, what is owed on open orders and tabs, and the net of both (what the member really has to pay when
     * negative, or has left).
     *
     * @return array{balance: float, debt: float, net: float}
     */
    public function summary(int $uID): array
    {
        $balance = round((float) $this->credit->getBalance($uID), 2);
        $debt = $this->orders->debt($uID);

        return ['balance' => $balance, 'debt' => $debt, 'net' => round($balance - $debt, 2)];
    }
}
