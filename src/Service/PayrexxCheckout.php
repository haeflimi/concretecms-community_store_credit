<?php
namespace CommunityStoreCredit\Service;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\User\UserInfo;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

/**
 * Pays an existing unpaid store order through Payrexx (the community_store_payrexx add-on supplies the SDK and
 * the credentials). A gateway is created per order and remembered in the session, the order carries the gateway
 * reference as its transaction reference, so the add-on's webhook can find and complete it. When the customer
 * returns, the gateway status is verified against the Payrexx API before the order is marked paid, so a slow or
 * missing webhook does not leave a paid order open.
 */
class PayrexxCheckout
{
    const REFERENCE_PREFIX = 'csorder_';
    const SESSION_KEY = 'community_store_credit.payrexx';
    /** Payrexx refuses amounts below this */
    const MINIMUM = 1.0;
    /** How long a created gateway link is reused instead of creating a new one */
    const REUSE_SECONDS = 1800;

    /** @var \Payrexx\Payrexx|null */
    protected $client;

    public static function instanceName(): string
    {
        return (string) (Config::get('community_store_payrexx.instanceName') ?: Config::get('payrexx.instance') ?: '');
    }

    public static function secret(): string
    {
        return (string) (Config::get('community_store_payrexx.secret') ?: Config::get('payrexx.apikey') ?: '');
    }

    public static function currency(): string
    {
        return strtoupper((string) (Config::get('community_store_payrexx.currency') ?: Config::get('community_store.currency') ?: 'CHF'));
    }

    /**
     * SDK loaded (the Payrexx add-on is installed) and credentials configured.
     */
    public function isAvailable(): bool
    {
        return class_exists('\Payrexx\Payrexx') && self::instanceName() !== '' && self::secret() !== '';
    }

    /**
     * Whether this order can be paid online: open, not cancelled or refunded, at least the Payrexx minimum.
     */
    public function canPay(Order $order): bool
    {
        return !$order->getPaid() && !$order->getCancelled() && !$order->getRefunded() && (float) $order->getTotal() >= self::MINIMUM;
    }

    /**
     * @param \Payrexx\Payrexx $client replaces the SDK client (tests)
     */
    public function setClient($client): void
    {
        $this->client = $client;
    }

    /**
     * The Payrexx page to send the customer to. Creates the gateway, or reuses the one created a moment ago.
     *
     * @throws UserMessageException
     */
    public function getPaymentLink(Order $order, UserInfo $ui): string
    {
        if (!$this->isAvailable()) {
            throw new UserMessageException(t('Online payment is not available at the moment.'));
        }
        if (!$this->canPay($order)) {
            throw new UserMessageException(t('This order cannot be paid online.'));
        }
        $oID = (int) $order->getOrderID();
        $cents = (int) round((float) $order->getTotal() * 100);
        $cached = $this->remembered($oID);
        if ($cached && $cached['cents'] === $cents && time() - $cached['created'] < self::REUSE_SECONDS) {
            return $cached['link'];
        }

        $reference = self::REFERENCE_PREFIX . $oID . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $gateway = new \Payrexx\Models\Request\Gateway();
        $gateway->setAmount($cents);
        $gateway->setCurrency(self::currency());
        $gateway->setVatRate(null);
        $gateway->setPurpose([t('Order #%s', $oID)]);
        $gateway->setSku('order-' . $oID);
        $gateway->setReferenceId($reference);
        $gateway->setPsp([]);
        $gateway->setSuccessRedirectUrl((string) Url::to('/account/credit/paid', $oID));
        $gateway->setFailedRedirectUrl((string) Url::to('/account/credit', 'failed', $oID));
        $gateway->setCancelRedirectUrl((string) Url::to('/account/credit', 'cancelled', $oID));
        $gateway->setSkipResultPage(false);
        $gateway->addField('email', (string) ($order->getAttribute('email') ?: $ui->getUserEmail()));
        $first = (string) ($order->getAttribute('billing_first_name') ?: $ui->getAttribute('user_firstname') ?: $ui->getAttribute('first_name'));
        $last = (string) ($order->getAttribute('billing_last_name') ?: $ui->getAttribute('user_lastname') ?: $ui->getAttribute('last_name'));
        if ($first !== '') {
            $gateway->addField('forename', $first);
        }
        if ($last !== '') {
            $gateway->addField('surname', $last);
        }

        try {
            $created = $this->client()->create($gateway);
        } catch (\Throwable $e) {
            $this->log()->warning('Community Store Credit: Payrexx gateway for order ' . $oID . ' could not be created: ' . $e->getMessage());
            throw new UserMessageException(t('The payment page could not be created. Please try again later.'));
        }
        $link = $created instanceof \Payrexx\Models\Response\Gateway ? (string) $created->getLink() : '';
        if ($link === '') {
            throw new UserMessageException(t('The payment page could not be created. Please try again later.'));
        }

        $order->setTransactionReference($reference);
        $order->save();
        $this->remember($oID, ['gatewayId' => (int) $created->getId(), 'reference' => $reference, 'link' => $link, 'cents' => $cents, 'created' => time()]);

        return $link;
    }

    /**
     * Back from Payrexx: verifies the gateway with the API and marks the order paid when it is confirmed.
     *
     * @return string paid | already_paid | pending | unknown
     */
    public function confirmReturn(Order $order): string
    {
        if ($order->getPaid()) {
            $this->forget((int) $order->getOrderID());

            return 'already_paid';
        }
        $remembered = $this->remembered((int) $order->getOrderID());
        if (!$remembered || !$this->isAvailable()) {
            return 'unknown';
        }
        try {
            $request = new \Payrexx\Models\Request\Gateway();
            $request->setId($remembered['gatewayId']);
            $gateway = $this->client()->getOne($request);
        } catch (\Throwable $e) {
            $this->log()->warning('Community Store Credit: Payrexx gateway ' . $remembered['gatewayId'] . ' could not be verified: ' . $e->getMessage());

            return 'unknown';
        }
        $status = $gateway instanceof \Payrexx\Models\Response\Gateway ? (string) $gateway->getStatus() : '';
        if ($status !== 'confirmed') {
            return 'pending';
        }
        // the webhook of the Payrexx add-on may have been faster
        $fresh = Order::getByID((int) $order->getOrderID()) ?: $order;
        if ($fresh->getPaid()) {
            $this->forget((int) $order->getOrderID());

            return 'already_paid';
        }
        if ((string) $fresh->getTransactionReference() !== (string) $remembered['reference']) {
            $fresh->setTransactionReference($remembered['reference']);
        }
        $fresh->completePayment(false);
        $fresh->setExternalPaymentRequested(null);
        $fresh->save();
        $this->forget((int) $order->getOrderID());
        $this->log()->info('Community Store Credit: order ' . $order->getOrderID() . ' paid through Payrexx (' . $remembered['reference'] . ', ' . Price::format($fresh->getTotal()) . ').');

        return 'paid';
    }

    protected function client()
    {
        if ($this->client === null) {
            $this->client = new \Payrexx\Payrexx(self::instanceName(), self::secret());
        }

        return $this->client;
    }

    protected function remembered(int $oID): ?array
    {
        $all = $this->session()->get(self::SESSION_KEY);
        $entry = is_array($all) && isset($all[$oID]) && is_array($all[$oID]) ? $all[$oID] : null;

        return $entry && isset($entry['gatewayId'], $entry['reference'], $entry['link'], $entry['cents'], $entry['created']) ? $entry : null;
    }

    protected function remember(int $oID, array $entry): void
    {
        $all = $this->session()->get(self::SESSION_KEY);
        $all = is_array($all) ? $all : [];
        $all[$oID] = $entry;
        $this->session()->set(self::SESSION_KEY, $all);
    }

    protected function forget(int $oID): void
    {
        $all = $this->session()->get(self::SESSION_KEY);
        if (is_array($all) && isset($all[$oID])) {
            unset($all[$oID]);
            $this->session()->set(self::SESSION_KEY, $all);
        }
    }

    protected function session()
    {
        return \Concrete\Core\Support\Facade\Application::getFacadeApplication()->make('session');
    }

    protected function log()
    {
        return \Concrete\Core\Support\Facade\Application::getFacadeApplication()->make('log');
    }
}
