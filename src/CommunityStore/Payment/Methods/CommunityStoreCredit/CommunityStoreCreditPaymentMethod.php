<?php
namespace Concrete\Package\CommunityStoreCredit\Src\CommunityStore\Payment\Methods\CommunityStoreCredit;

use CommunityStoreCredit\Service\CreditService;
use CommunityStoreCredit\Service\InsufficientCreditException;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Calculator as StoreCalculator;

/**
 * Pays an order from the customer's store credit. Offered only when the credit covers the whole order
 * (getPaymentMaximum() = the customer's balance). The credit is spent in submitPayment() under a per-checkout
 * nonce, so a repeated submit never charges twice, and the order id is attached once the order exists.
 */
class CommunityStoreCreditPaymentMethod extends StorePaymentMethod
{
    const HANDLE = 'community_store_credit';
    const SESSION_NONCE = 'community_store_credit.checkout_nonce';
    const REFERENCE_PREFIX = 'credit:';

    public function getName()
    {
        return t('Store Credit');
    }

    public function dashboardForm()
    {
        $this->set('form', Application::getFacadeApplication()->make('helper/form'));
    }

    public function save(array $data = [])
    {
    }

    public function validate($args, $e)
    {
        return $e;
    }

    public function checkoutForm()
    {
        $pm = StorePaymentMethod::getByHandle(self::HANDLE);
        $this->set('pmID', $pm ? $pm->getID() : 0);
        $this->set('balance', $this->currentBalance());
        $totals = StoreCalculator::getTotals();
        $this->set('total', isset($totals['total']) ? (float) $totals['total'] : 0.0);
    }

    public function submitPayment()
    {
        $app = Application::getFacadeApplication();
        $user = $app->make(User::class);
        if (!$user->isRegistered()) {
            return ['error' => 1, 'errorMessage' => t('Store credit can only be used by signed in customers.')];
        }
        $totals = StoreCalculator::getTotals();
        $total = round(isset($totals['total']) ? (float) $totals['total'] : 0.0, 2);
        $session = $app->make('session');
        $nonce = $session->get(self::SESSION_NONCE);
        if (!$nonce) {
            $nonce = md5(uniqid('csc', true));
            $session->set(self::SESSION_NONCE, $nonce);
        }
        /** @var CreditService $service */
        $service = $app->make(CreditService::class);
        try {
            $entry = $service->charge($user, $total, t('Checkout'), $nonce, $user->getUserID());
        } catch (InsufficientCreditException $e) {
            return ['error' => 1, 'errorMessage' => t('Your store credit (%s) does not cover this order (%s).', number_format($e->getBalance(), 2), number_format($total, 2))];
        } catch (\Throwable $e) {
            $app->make('log')->error('Store credit: checkout charge failed: ' . $e->getMessage());
            return ['error' => 1, 'errorMessage' => t('Store credit could not be charged, please try again.')];
        }
        if (abs($entry->getAmount() + $total) > 0.005) {
            // the same checkout was already charged with a different total (cart changed in between)
            return ['error' => 1, 'errorMessage' => t('Your cart changed since the last attempt, please review it and try again.')];
        }
        return ['error' => 0, 'transactionReference' => self::REFERENCE_PREFIX . $entry->getID()];
    }

    public function getPaymentMinimum()
    {
        return 0;
    }

    /**
     * The customer's balance: Community Store hides the method when the order total exceeds it.
     */
    public function getPaymentMaximum()
    {
        return $this->currentBalance();
    }

    public function markPaid()
    {
        return true;
    }

    public function isExternal()
    {
        return false;
    }

    public function getPaymentInstructions()
    {
        return '';
    }

    private function currentBalance()
    {
        $app = Application::getFacadeApplication();
        $user = $app->make(User::class);
        if (!$user->isRegistered()) {
            return 0.0;
        }
        return $app->make(CreditService::class)->getBalance($user);
    }
}
