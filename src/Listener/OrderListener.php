<?php
namespace CommunityStoreCredit\Listener;

use CommunityStoreCredit\Service\CreditService;
use Concrete\Core\Support\Facade\Application;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderEvent;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use Concrete\Package\CommunityStoreCredit\Src\CommunityStore\Payment\Methods\CommunityStoreCredit\CommunityStoreCreditPaymentMethod;

/**
 * Ties checkout charges to their orders and gives the credit back when such an order is cancelled.
 */
class OrderListener
{
    /**
     * Runs on payment completion, when the order already carries its transaction reference.
     */
    public function onPaymentComplete(OrderEvent $event)
    {
        $order = $event->getOrder();
        if (!$this->paidWithCredit($order)) {
            return;
        }
        $app = Application::getFacadeApplication();
        $service = $app->make(CreditService::class);
        $reference = (string) $order->getTransactionReference();
        if (strpos($reference, CommunityStoreCreditPaymentMethod::REFERENCE_PREFIX) !== 0) {
            return;
        }
        $entryID = (int) substr($reference, strlen(CommunityStoreCreditPaymentMethod::REFERENCE_PREFIX));
        $entry = $entryID ? $service->em()->find('CommunityStoreCredit\Entity\CreditEntry', $entryID) : null;
        if ($entry && !$entry->getOrderID()) {
            $service->attachOrder($entry, $order->getOrderID());
        }
        // the checkout is complete: the next checkout gets its own nonce
        $app->make('session')->remove(CommunityStoreCreditPaymentMethod::SESSION_NONCE);
    }

    public function onOrderCancelled(OrderEvent $event)
    {
        $order = $event->getOrder();
        if (!$this->paidWithCredit($order)) {
            return;
        }
        $app = Application::getFacadeApplication();
        $service = $app->make(CreditService::class);
        $charge = $service->findByOrder($order->getOrderID());
        if (!$charge) {
            return;
        }
        try {
            $service->add(
                $charge->getUserID(),
                abs($charge->getAmount()),
                t('Refund for cancelled order #%s', $order->getOrderID()),
                CreditService::SOURCE_REFUND,
                'order:' . $order->getOrderID(),
                null,
                $order->getOrderID()
            );
        } catch (\Throwable $e) {
            $app->make('log')->error('Store credit: refund for cancelled order ' . $order->getOrderID() . ' failed: ' . $e->getMessage());
        }
    }

    private function paidWithCredit($order)
    {
        if (!$order || !$order->getPaymentMethodID()) {
            return false;
        }
        $pm = StorePaymentMethod::getByID($order->getPaymentMethodID());
        return $pm && $pm->getHandle() === CommunityStoreCreditPaymentMethod::HANDLE;
    }
}
