<?php
namespace Concrete\Package\CommunityStoreCredit\Controller\SinglePage\Account;

use CommunityStoreCredit\Service\AccountOverview;
use CommunityStoreCredit\Service\PayrexxCheckout;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Controller\AccountPageController;
use Concrete\Core\User\User;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;

/**
 * "Credit & Orders": the customer's store credit, standing event tabs, open orders with online payment, and the
 * paid orders.
 */
class Credit extends AccountPageController
{
    const TOKEN = 'csc_account';

    public function view()
    {
        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            return $this->replace('/login');
        }
        $uID = (int) $user->getUserID();
        $overview = $this->app->make(AccountOverview::class)->build($uID);
        $payrexx = $this->app->make(PayrexxCheckout::class);

        $failed = (int) $this->request->query->get('failed', 0);
        $cancelled = (int) $this->request->query->get('cancelled', 0);
        if ($failed > 0) {
            $this->set('error', t('The payment for order #%s was not completed. You can try again.', $failed));
        } elseif ($cancelled > 0) {
            $this->set('message', t('The payment for order #%s was cancelled.', $cancelled));
        }

        $this->set('overview', $overview);
        $this->set('balance', $overview['balance']);
        $this->set('entries', $overview['entries']);
        $this->set('payrexx', $payrexx);
        $this->set('onlinePayment', $payrexx->isAvailable());
        $this->set('dateHelper', $this->app->make('date'));
        $this->set('token', $this->app->make('token'));
        $this->set('tokenName', self::TOKEN);
        $this->set('pageTitle', t('Credit & Orders'));
    }

    /**
     * POST: sends the customer to the Payrexx page for one of their open orders.
     */
    public function pay($oID = null)
    {
        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            return $this->replace('/login');
        }
        if (!$this->request->isMethod('POST') || !$this->app->make('token')->validate(self::TOKEN)) {
            $this->flash('error', $this->app->make('token')->getErrorMessage());

            return $this->buildRedirect($this->action(''));
        }
        try {
            $order = $this->requireOwnOrder($user, $oID);
            $link = $this->app->make(PayrexxCheckout::class)->getPaymentLink($order, $user->getUserInfoObject());
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($this->action(''));
        }

        return $this->buildRedirect($link);
    }

    /**
     * GET: back from Payrexx after a payment.
     */
    public function paid($oID = null)
    {
        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            return $this->replace('/login');
        }
        try {
            $order = $this->requireOwnOrder($user, $oID);
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($this->action(''));
        }
        switch ($this->app->make(PayrexxCheckout::class)->confirmReturn($order)) {
            case 'paid':
            case 'already_paid':
                $this->flash('success', t('Thank you, order #%s is paid.', $order->getOrderID()));
                break;
            case 'pending':
                $this->flash('message', t('The payment for order #%s has not been confirmed yet. The order is updated as soon as the payment provider confirms it.', $order->getOrderID()));
                break;
            default:
                $this->flash('message', t('Order #%s will be marked paid as soon as the payment provider confirms the payment.', $order->getOrderID()));
        }

        return $this->buildRedirect($this->action(''));
    }

    /**
     * @throws UserMessageException
     */
    protected function requireOwnOrder(User $user, $oID): Order
    {
        $order = (int) $oID > 0 ? Order::getByID((int) $oID) : null;
        if (!$order || (int) $order->getCustomerID() !== (int) $user->getUserID()) {
            throw new UserMessageException(t('Unknown order.'));
        }

        return $order;
    }
}
