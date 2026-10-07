<?php
defined('C5_EXECUTE') or die("Access Denied.");
/**
 * @var array $overview
 * @var CommunityStoreCredit\Service\PayrexxCheckout $payrexx
 * @var bool $onlinePayment
 * @var Concrete\Core\Localization\Service\Date $dateHelper
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var string $tokenName
 */
use Concrete\Core\Support\Facade\Url;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

$qty = static function ($q) { return rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.'); };
$itemsTable = static function (array $items) use ($qty) { ?>
    <table class="table table-sm mb-2">
        <tbody>
        <?php foreach ($items as $item) { ?>
            <tr>
                <td><?= $item['qty'] != 1 ? h($qty($item['qty'])) . ' &times; ' : '' ?><?= h($item['name']) ?><?php if ($item['options']) { ?> <small class="text-muted"><?= h(implode(', ', $item['options'])) ?></small><?php } ?></td>
                <td class="text-end text-nowrap"><?= Price::format($item['subtotal']) ?></td>
            </tr>
        <?php } ?>
        <?php if (!$items) { ?><tr><td class="text-muted"><?= t('No items yet.') ?></td><td></td></tr><?php } ?>
        </tbody>
    </table>
<?php };
$payButton = static function ($order) use ($onlinePayment, $payrexx, $token, $tokenName) {
    if (!$onlinePayment || !$payrexx->canPay($order)) {
        return;
    } ?>
    <form method="post" action="<?= Url::to('/account/credit/pay', $order->getOrderID()) ?>" class="d-inline">
        <?php $token->output($tokenName) ?>
        <button type="submit" class="btn btn-primary"><?= t('Pay %s now', Price::format($order->getTotal())) ?></button>
    </form>
<?php };
$stateBadge = ['unpaid' => ['bg-danger', t('Unpaid')], 'paid' => ['bg-success', t('Paid')], 'refunded' => ['bg-warning text-dark', t('Refunded')], 'cancelled' => ['bg-secondary', t('Cancelled')]];
?>
<div class="store-credit-account">
    <?php if (!empty($error)) { ?><div class="alert alert-danger"><?= h($error) ?></div><?php } ?>
    <?php if (!empty($message)) { ?><div class="alert alert-info"><?= h($message) ?></div><?php } ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card card-body"><div class="text-muted small"><?= t('Store credit') ?></div><div class="fs-4 fw-semibold <?= $overview['balance'] > 0 ? 'text-success' : '' ?>"><?= Price::format($overview['balance']) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><div class="text-muted small"><?= t('Open orders') ?></div><div class="fs-4 fw-semibold <?= $overview['debt'] > 0 ? 'text-danger' : '' ?>"><?= Price::format($overview['debt']) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><div class="text-muted small"><?= t('Net') ?></div><div class="fs-4 fw-semibold <?= $overview['net'] < 0 ? 'text-danger' : ($overview['net'] > 0 ? 'text-success' : '') ?>"><?= Price::format($overview['net']) ?></div></div></div>
    </div>

    <?php if ($overview['tabs']) { ?>
        <h3><?= t('Event tabs') ?></h3>
        <p class="text-muted"><?= t('Your standing orders at events: tickets and everything you put on your tab. An open tab is settled at the event checkout or online.') ?></p>
        <?php foreach ($overview['tabs'] as $tab) {
            $event = $tab['event'];
            $order = $tab['order'];
            $settlementNames = ['cash' => t('cash'), 'card' => t('card'), 'online' => t('online'), 'waived' => t('waived')]; ?>
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong><?= h($event->getName()) ?></strong>
                        <?php if ($event->getStartsAt()) { ?><span class="text-muted small"> &middot; <?= h($event->isAllDay() ? $dateHelper->formatDate($event->getStartsAt(), true) : $dateHelper->formatDateTime($event->getStartsAt())) ?></span><?php } ?>
                        <span class="text-muted small"> &middot; <?= t('Order #%s', $order->getOrderID()) ?></span>
                    </div>
                    <?php if ($tab['settled']) { ?>
                        <span class="badge bg-success"><?= t('Settled (%s)', $settlementNames[$tab['settlement']] ?? $tab['settlement']) ?></span>
                    <?php } elseif ($order->getPaid()) { ?>
                        <span class="badge bg-success"><?= t('Paid') ?></span>
                    <?php } else { ?>
                        <span class="badge bg-info text-dark"><?= t('Tab open') ?></span>
                    <?php } ?>
                </div>
                <div class="card-body">
                    <?php $itemsTable($tab['describe']['items']); ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="fw-semibold"><?= t('Total') ?>: <?= Price::format($order->getTotal()) ?></div>
                        <?php if ($tab['open']) { ?>
                            <div>
                                <?php $payButton($order); ?>
                                <?php if (!$onlinePayment || !$payrexx->canPay($order)) { ?><span class="text-muted small"><?= t('Pay at the event checkout.') ?></span><?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    <?php } ?>

    <h3><?= t('Open orders') ?></h3>
    <?php if (!$overview['open']) { ?>
        <p class="text-muted"><?= t('You have no open orders.') ?></p>
    <?php } ?>
    <?php foreach ($overview['open'] as $row) {
        $order = $row['order']; ?>
        <div class="card mb-3 border-danger-subtle">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong><?= t('Order #%s', $order->getOrderID()) ?></strong>
                    <span class="text-muted small"> &middot; <?= $dateHelper->formatDateTime($order->getOrderDate()) ?></span>
                    <?php if ($order->getPaymentMethodName()) { ?><span class="text-muted small"> &middot; <?= h($order->getPaymentMethodName()) ?></span><?php } ?>
                    <?php if ($row['status']) { ?><span class="text-muted small"> &middot; <?= h($row['status']) ?></span><?php } ?>
                </div>
                <span class="badge bg-danger"><?= t('Unpaid') ?></span>
            </div>
            <div class="card-body">
                <?php $itemsTable($row['items']); ?>
                <?php if ($order->getNotes()) { ?><p class="small text-muted mb-2"><?= nl2br(h($order->getNotes())) ?></p><?php } ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="fw-semibold"><?= t('Total') ?>: <?= Price::format($order->getTotal()) ?></div>
                    <div>
                        <?php $payButton($order); ?>
                        <?php if (!$onlinePayment) { ?><span class="text-muted small"><?= t('Online payment is not available at the moment.') ?></span><?php } ?>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if ($overview['paid']) { ?>
        <h3 class="mt-4"><?= t('Paid orders') ?></h3>
        <div class="accordion mb-4" id="csc-paid-orders">
            <?php foreach ($overview['paid'] as $i => $row) {
                $order = $row['order']; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#csc-paid-<?= $order->getOrderID() ?>">
                            <span class="me-auto"><?= t('Order #%s', $order->getOrderID()) ?> <span class="text-muted small">&middot; <?= $dateHelper->formatDate($order->getOrderDate()) ?><?= $order->getPaymentMethodName() ? ' &middot; ' . h($order->getPaymentMethodName()) : '' ?></span></span>
                            <span class="badge <?= $stateBadge[$row['state']][0] ?> me-2"><?= $stateBadge[$row['state']][1] ?></span>
                            <span class="fw-semibold me-3"><?= Price::format($order->getTotal()) ?></span>
                        </button>
                    </h2>
                    <div id="csc-paid-<?= $order->getOrderID() ?>" class="accordion-collapse collapse" data-bs-parent="#csc-paid-orders">
                        <div class="accordion-body">
                            <?php $itemsTable($row['items']); ?>
                            <div class="small text-muted"><?= t('Paid on %s', $dateHelper->formatDateTime($order->getPaid())) ?><?= $row['status'] ? ' &middot; ' . h($row['status']) : '' ?></div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <h3 class="mt-4"><?= t('Store credit history') ?></h3>
    <?php if (empty($entries)) { ?>
        <p class="text-muted"><?= t('No entries yet.') ?></p>
    <?php } else { ?>
        <table class="table table-striped">
            <thead>
            <tr><th><?= t('Date') ?></th><th><?= t('Comment') ?></th><th><?= t('Order') ?></th><th class="text-end"><?= t('Amount') ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry) { ?>
                <tr>
                    <td class="text-nowrap"><?= $dateHelper->formatDateTime($entry->getCreatedAt()) ?></td>
                    <td><?= h($entry->getComment()) ?></td>
                    <td><?= $entry->getOrderID() ? t('Order #%s', $entry->getOrderID()) : '' ?></td>
                    <td class="text-end <?= $entry->getAmount() < 0 ? 'text-danger' : 'text-success' ?>"><?= Price::format($entry->getAmount()) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
