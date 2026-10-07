<?php
namespace CommunityStoreCredit\Service;

use Concrete\Core\Application\Application;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

/**
 * Everything the customer's account page shows: store credit, standing event tabs (when the event manager is
 * installed), other open orders and the recent paid orders.
 */
class AccountOverview
{
    /** @var Application */
    protected $app;

    /** @var CreditService */
    protected $credit;

    public function __construct(Application $app, CreditService $credit)
    {
        $this->app = $app;
        $this->credit = $credit;
    }

    /**
     * @return array{balance: float, entries: array, tabs: array, open: array, paid: array, debt: float, net: float}
     */
    public function build(int $uID, int $historyLimit = 50, int $paidLimit = 25): array
    {
        $tabs = $this->eventTabs($uID);
        $tabOrderIDs = [];
        foreach ($tabs as $tab) {
            if ($tab['order']) {
                $tabOrderIDs[(int) $tab['order']->getOrderID()] = true;
            }
        }

        $open = [];
        $debt = 0.0;
        foreach ($this->orders($uID, 'unpaid') as $order) {
            if (isset($tabOrderIDs[(int) $order->getOrderID()])) {
                continue;
            }
            $open[] = $this->describe($order);
            $debt += (float) $order->getTotal();
        }
        foreach ($tabs as $tab) {
            if ($tab['open'] && $tab['order']) {
                $debt += (float) $tab['order']->getTotal();
            }
        }

        $paid = [];
        foreach ($this->orders($uID, 'paid', $paidLimit) as $order) {
            $paid[] = $this->describe($order);
        }

        $balance = round((float) $this->credit->getBalance($uID), 2);
        $debt = round($debt, 2);

        return [
            'balance' => $balance,
            'entries' => $this->credit->getHistory($uID, $historyLimit),
            'tabs' => $tabs,
            'open' => $open,
            'paid' => $paid,
            'debt' => $debt,
            'net' => round($balance - $debt, 2),
        ];
    }

    /**
     * One row per order: items with quantity and price, totals formatted.
     *
     * @return array{order: Order, items: array<int, array{name: string, qty: float, price: float, subtotal: float}>, itemsTotal: float, status: string, state: string}
     */
    public function describe(Order $order): array
    {
        $items = [];
        $itemsTotal = 0.0;
        foreach ($order->getOrderItems() as $item) {
            $subtotal = (float) $item->getPricePaid() * (float) $item->getQuantity();
            $itemsTotal += $subtotal;
            $options = [];
            foreach ($item->getOrderItemOptions() as $opt) {
                $options[] = $opt->getOrderItemOptionKey() . ': ' . $opt->getOrderItemOptionValue();
            }
            $items[] = ['name' => (string) $item->getProductName(), 'options' => $options, 'qty' => (float) $item->getQuantity(), 'price' => (float) $item->getPricePaid(), 'subtotal' => $subtotal];
        }
        if ($order->getCancelled()) {
            $state = 'cancelled';
        } elseif ($order->getRefunded()) {
            $state = 'refunded';
        } elseif ($order->getPaid()) {
            $state = 'paid';
        } else {
            $state = 'unpaid';
        }

        return ['order' => $order, 'items' => $items, 'itemsTotal' => round($itemsTotal, 2), 'status' => (string) $order->getStatus(), 'state' => $state];
    }

    /**
     * Standing orders: the user's event tabs (open or settled), newest event first. Empty without the event manager.
     *
     * @return array<int, array{event: object, participant: object, order: Order|null, bill: array, open: bool, settled: bool, settlement: string|null, describe: array|null}>
     */
    public function eventTabs(int $uID): array
    {
        if (!class_exists('\CommunityEventManager\Tab\TabService') || !class_exists('\CommunityEventManager\Participation\ParticipationService')) {
            return [];
        }
        $rows = [];
        try {
            $participation = $this->app->make('CommunityEventManager\Participation\ParticipationService');
            $tabService = $this->app->make('CommunityEventManager\Tab\TabService');
            foreach ($participation->getParticipationsOfUser($uID) as $participant) {
                if ($participant->getTabOrderID() === null) {
                    continue;
                }
                $bill = $tabService->getBill($participant);
                if ($bill['order'] === null) {
                    continue;
                }
                $rows[] = [
                    'event' => $participant->getEvent(),
                    'participant' => $participant,
                    'order' => $bill['order'],
                    'bill' => $bill,
                    'open' => (bool) $bill['open'],
                    'settled' => (bool) $bill['settled'],
                    'settlement' => $bill['settlement'],
                    'describe' => $this->describe($bill['order']),
                ];
            }
        } catch (\Throwable $e) {
            $this->app->make('log')->warning('Community Store Credit: event tabs could not be loaded: ' . $e->getMessage());
        }

        return $rows;
    }

    /**
     * @return Order[]
     */
    protected function orders(int $uID, string $paymentStatus, int $limit = 0): array
    {
        $list = new OrderList();
        $list->setCustomerID($uID);
        $list->setPaymentStatus($paymentStatus);
        $list->setCancelled(false);
        if ($limit > 0) {
            $list->setLimit($limit);
        }

        return $list->getResults();
    }

    public static function format($amount): string
    {
        return Price::format($amount);
    }
}
