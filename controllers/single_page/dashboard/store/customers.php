<?php
namespace Concrete\Package\CommunityStoreCredit\Controller\SinglePage\Dashboard\Store;

use CommunityStoreCredit\Report\CustomerBalanceReport;
use CommunityStoreCredit\Service\CreditService;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Search\Pagination\PaginationFactory;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Report\CsvReportExporter;

/**
 * Customers report: debts (unpaid orders), store credit and the resulting net per customer.
 */
class Customers extends DashboardPageController
{
    public function view()
    {
        $report = $this->buildReport();
        $report->setItemsPerPage(25);

        $factory = new PaginationFactory($this->app->make(Request::class));
        $paginator = $factory->createPaginationObject($report);

        $this->set('report', $report);
        $this->set('rows', $paginator->getCurrentPageResults());
        $this->set('totals', $report->getTotals());
        $this->set('pagination', $paginator->renderDefaultView());
        $this->set('paginator', $paginator);
        $this->set('keywords', $this->request->query->get('keywords', ''));
        $this->set('show', $report->getShow());
        $this->set('orderBy', $report->getSortColumn());
        $this->set('direction', $report->getSortDirection());
        $this->set('pageTitle', t('Customers'));
    }

    public function detail($uID = null)
    {
        $uID = (int) $uID;
        $row = CustomerBalanceReport::forCustomer($uID);
        $ui = $uID > 0 ? $this->app->make(UserInfoRepository::class)->getByID($uID) : null;
        if ($uID > 0 && !$ui) {
            $this->flash('error', t('Unknown customer.'));
            return $this->buildRedirect($this->action(''));
        }
        if (!$row) {
            $row = ['uID' => $uID, 'name' => $ui ? $ui->getUserName() : t('Guests / no account'), 'email' => $ui ? $ui->getUserEmail() : '', 'debt' => 0.0, 'credit' => 0.0, 'net' => 0.0, 'unpaidOrders' => 0, 'paidOrders' => 0, 'paidTotal' => 0.0];
        }

        // complete order history: unpaid, paid, cancelled, refunded and pending external payments
        $orders = new OrderList();
        $orders->setCustomerID($uID);
        $history = [];
        $counts = ['unpaid' => 0, 'paid' => 0, 'cancelled' => 0, 'refunded' => 0, 'incomplete' => 0];
        foreach ($orders->getResults() as $order) {
            $state = $this->orderState($order);
            $counts[$state]++;
            $history[] = ['order' => $order, 'state' => $state];
        }

        $this->set('row', $row);
        $this->set('userInfo', $ui);
        $this->set('orders', $history);
        $this->set('orderCounts', $counts);
        $this->set('creditEntries', $uID > 0 ? $this->app->make(CreditService::class)->getHistory($uID, 200) : []);
        $this->set('pageTitle', t('Balance of %s', $row['name']));
    }

    public function export()
    {
        $report = $this->buildReport();
        $header = [t('Customer Id'), t('Customer'), t('Email'), t('Unpaid Orders'), t('Debt'), t('Store Credit'), t('Net'), t('Paid Orders'), t('Paid Total'), t('Last Paid Order'), t('Oldest Unpaid Order')];
        $rows = [];
        foreach ($report->getRows() as $r) {
            $rows[] = [$r['uID'], $r['name'], $r['email'], $r['unpaidOrders'], number_format($r['debt'], 2, '.', ''), number_format($r['credit'], 2, '.', ''), number_format($r['net'], 2, '.', ''), $r['paidOrders'], number_format($r['paidTotal'], 2, '.', ''), (string) $r['lastOrder'], (string) $r['oldestUnpaid']];
        }
        $this->app->make(CsvReportExporter::class, [
            'filename' => t('customer_balances') . '_' . date('Y-m-d'),
            'header' => $header,
            'rows' => $rows,
        ])->getCsv();
    }

    /**
     * Payment state of an order, with the same precedence the store's order page uses.
     *
     * @return string unpaid|paid|cancelled|refunded|incomplete
     */
    protected function orderState(Order $order)
    {
        if ($order->getCancelled()) {
            return 'cancelled';
        }
        if ($order->getRefunded()) {
            return 'refunded';
        }
        if ($order->getPaid()) {
            return 'paid';
        }
        if ($order->getExternalPaymentRequested()) {
            return 'incomplete';
        }
        return 'unpaid';
    }

    protected function buildReport()
    {
        $report = new CustomerBalanceReport();
        $report->setKeywords($this->request->query->get('keywords', ''));
        $report->setShow($this->request->query->get('show', CustomerBalanceReport::SHOW_ALL));
        $report->setSort($this->request->query->get('orderBy', 'debt'), $this->request->query->get('direction', 'desc'));
        return $report;
    }
}
