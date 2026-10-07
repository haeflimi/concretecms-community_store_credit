<?php
namespace Concrete\Package\CommunityStoreCredit\Controller\SinglePage\Dashboard\Store;

use CommunityStoreCredit\Service\CreditService;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;

class Credit extends DashboardPageController
{
    public function view()
    {
        $keywords = trim((string) $this->request->query->get('keywords', ''));
        /** @var CreditService $service */
        $service = $this->app->make(CreditService::class);
        $this->set('keywords', $keywords);
        $this->set('balances', $service->getBalances($keywords));
        $this->set('token', $this->token);
    }

    public function history($uID = null)
    {
        $uID = (int) $uID;
        $ui = $uID ? $this->app->make(UserInfoRepository::class)->getByID($uID) : null;
        if (!$ui) {
            $this->flash('error', t('Unknown user.'));
            return $this->buildRedirect($this->action(''));
        }
        /** @var CreditService $service */
        $service = $this->app->make(CreditService::class);
        $this->set('userInfo', $ui);
        $this->set('uID', $uID);
        $this->set('balance', $service->getBalance($uID));
        $this->set('entries', $service->getHistory($uID, 500));
        $this->set('token', $this->token);
        $this->render('/dashboard/store/credit/history');
    }

    public function adjust()
    {
        if (!$this->token->validate('csc_adjust')) {
            $this->flash('error', $this->token->getErrorMessage());
            return $this->buildRedirect($this->action(''));
        }
        $uID = (int) $this->request->request->get('uID');
        $amount = $this->request->request->get('amount');
        $comment = trim((string) $this->request->request->get('comment'));
        $ui = $uID ? $this->app->make(UserInfoRepository::class)->getByID($uID) : null;
        if (!$ui) {
            $this->flash('error', t('Unknown user.'));
            return $this->buildRedirect($this->action(''));
        }
        if (!is_numeric($amount) || abs((float) $amount) < 0.005) {
            $this->flash('error', t('The amount must be a number other than zero.'));
            return $this->buildRedirect($this->action('history', $uID));
        }
        if ($comment === '') {
            $this->flash('error', t('A comment is required.'));
            return $this->buildRedirect($this->action('history', $uID));
        }
        $by = $this->app->make(User::class)->getUserID();
        $bookingId = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $this->request->request->get('bookingId'));
        if ($bookingId === '') {
            $bookingId = uniqid('adj_', true);
        }
        try {
            $this->app->make(CreditService::class)->add($uID, $amount, $comment, CreditService::SOURCE_MANUAL, $bookingId, $by);
            $this->flash('success', t('Booked %s for %s.', number_format((float) $amount, 2), $ui->getUserName()));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->buildRedirect($this->action('history', $uID));
    }
}
