<?php
namespace Concrete\Package\CommunityStoreCredit;

use CommunityStoreCredit\Listener\OrderListener;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\NavigationCache;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Support\Facade\Events;

/**
 * Store credit for Community Store: an append-only credit ledger per customer, a "Store Credit" payment method
 * that is offered when the credit covers the whole order and a dashboard page to view and adjust balances. The
 * customer's account page is the one of Community Store Open Orders, which shows the credit too.
 */
class Controller extends Package implements ProviderAggregateInterface
{
    const PAYMENT_METHOD_HANDLE = 'community_store_credit';

    protected $pkgHandle = 'community_store_credit';
    protected $appVersionRequired = '8.4';
    protected $pkgVersion = '1.3.0';
    protected $pkgAutoloaderRegistries = [
        'src/Entity' => '\CommunityStoreCredit\Entity',
        'src/Service' => '\CommunityStoreCredit\Service',
        'src/Listener' => '\CommunityStoreCredit\Listener',
        'src/Report' => '\CommunityStoreCredit\Report',
        // Community Store loads payment methods from Concrete\Package\<Package>\Src\CommunityStore\Payment\Methods\…
        'src/CommunityStore' => '\Concrete\Package\CommunityStoreCredit\Src\CommunityStore',
    ];

    public function getPackageName()
    {
        return t('Community Store Credit');
    }

    public function getPackageDescription()
    {
        return t('Store credit balances for Community Store customers, spendable as a payment method at checkout.');
    }

    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'CommunityStoreCredit\Entity',
        ]);
    }

    public function on_start()
    {
        if (!class_exists('Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderEvent')) {
            return;
        }
        $listener = new OrderListener();
        Events::addListener('on_community_store_payment_complete', [$listener, 'onPaymentComplete']);
        Events::addListener('on_community_store_order_cancelled', [$listener, 'onOrderCancelled']);
    }

    public function install()
    {
        $installed = Package::getInstalledHandles();
        if (!is_array($installed) || !in_array('community_store', $installed, true)) {
            throw new \Exception(t('This package requires that Community Store be installed.'));
        }
        if (!in_array('community_store_open_orders', $installed, true)) {
            throw new \Exception(t('This package requires that Community Store Open Orders be installed.'));
        }
        $pkg = parent::install();
        $this->setUp($pkg);
    }

    public function upgrade()
    {
        $installed = Package::getInstalledHandles();
        if (!is_array($installed) || !in_array('community_store_open_orders', $installed, true)) {
            throw new \Exception(t('This package requires that Community Store Open Orders be installed.'));
        }
        parent::upgrade();
        $this->refreshEntityMetadata();
        $this->setUp($this->getPackageEntity());
    }

    /**
     * The credit ledger table is deliberately kept on uninstall; only the payment method and pages go.
     */
    public function uninstall()
    {
        $pm = \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getByHandle(self::PAYMENT_METHOD_HANDLE);
        if ($pm) {
            $pm->delete();
        }
        $this->nameOrdersPage(t('Orders'));
        parent::uninstall();
    }

    private function setUp($pkg)
    {
        $pm = \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getByHandle(self::PAYMENT_METHOD_HANDLE);
        if (!$pm) {
            \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::add(self::PAYMENT_METHOD_HANDLE, 'Store Credit', $pkg, t('Pay with store credit'), true);
        }
        $page = SinglePage::add('/dashboard/store/credit', $pkg);
        if ($page && !$page->isError()) {
            $page->update(['cName' => t('Store Credit'), 'cDescription' => t('Customer store credit balances')]);
        }
        $this->installCustomersPage($pkg);
        // Up to 1.2.0 the account page /account/credit was ours; it moved to Community Store Open Orders (/account/orders)
        $old = Page::getByPath('/account/credit');
        if ($old && !$old->isError() && (int) $old->getPackageID() === (int) $pkg->getPackageID()) {
            $old->delete();
        }
        $this->nameOrdersPage(t('Credit & Orders'));
    }

    /**
     * The account page of Community Store Open Orders shows the credit while this package is installed.
     */
    private function nameOrdersPage(string $name)
    {
        $page = Page::getByPath('/account/orders');
        if ($page && !$page->isError()) {
            $page->update(['cName' => $name]);
        }
    }

    /**
     * The customers page sits at store level, between Orders and Products. Versions up to 1.1.0 created it under
     * Reports; such a page is moved instead of recreated.
     */
    private function installCustomersPage($pkg)
    {
        $store = Page::getByPath('/dashboard/store');
        $page = Page::getByPath('/dashboard/store/customers');
        if (!$page || $page->isError()) {
            $old = Page::getByPath('/dashboard/store/reports/customers');
            if ($old && !$old->isError() && $store && !$store->isError()) {
                $old->move($store);
                $page = Page::getByID($old->getCollectionID());
            } else {
                $page = SinglePage::add('/dashboard/store/customers', $pkg);
            }
        }
        if (!$page || $page->isError()) {
            return;
        }
        // a moved page keeps its old controller/view path, so the filename is set explicitly
        $page->update(['cName' => t('Customers'), 'cDescription' => t('Debts and store credit per customer'), 'cFilename' => '/dashboard/store/customers.php']);
        $orders = Page::getByPath('/dashboard/store/orders');
        if ($orders && !$orders->isError()) {
            $page->movePageDisplayOrderToSibling($orders, 'after');
        }
        $this->app->make(NavigationCache::class)->clear();
    }

    private function refreshEntityMetadata()
    {
        try {
            $em = $this->app->make('Doctrine\ORM\EntityManagerInterface');
            $cache = $em->getConfiguration()->getMetadataCacheImpl();
            if ($cache) {
                $cache->deleteAll();
            }
            $metadata = array_filter($em->getMetadataFactory()->getAllMetadata(), static function ($class) {
                return strpos($class->getName(), 'CommunityStoreCredit\\') === 0;
            });
            $em->getProxyFactory()->generateProxyClasses($metadata);
        } catch (\Throwable $e) {
            $this->app->make('log')->warning('Community Store Credit: unable to refresh the entity metadata: ' . $e->getMessage());
        }
    }
}
