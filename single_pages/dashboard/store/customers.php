<?php
defined('C5_EXECUTE') or die("Access Denied.");
use Concrete\Core\Support\Facade\Url;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$dh = $app->make('helper/date');
$task = $controller->getAction();

if ($task == 'view') {
    $sortLink = function ($column) use ($orderBy, $direction, $keywords, $show) {
        $dir = ($orderBy === $column && $direction === 'desc') ? 'asc' : 'desc';
        return Url::to('/dashboard/store/customers') . '?' . http_build_query(['keywords' => $keywords, 'show' => $show, 'orderBy' => $column, 'direction' => $dir]);
    };
    ?>
    <div class="ccm-dashboard-header-buttons">
        <a href="<?= Url::to('/dashboard/store/customers/export') ?>?<?= http_build_query(['keywords' => $keywords, 'show' => $show, 'orderBy' => $orderBy, 'direction' => $direction]) ?>" class="btn btn-primary"><?= t('Export CSV') ?></a>
    </div>

    <form action="<?= Url::to('/dashboard/store/customers') ?>" method="get">
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <?= $form->label('keywords', t('Customer Search')) ?>
                    <?= $form->text('keywords', h($keywords), ['placeholder' => t('Name, e-mail or customer id')]) ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <?= $form->label('show', t('Show')) ?>
                    <?= $form->select('show', ['all' => t('All customers with debt or credit'), 'debt' => t('Customers with debt'), 'credit' => t('Customers with credit'), 'open' => t('Non-zero net only')], $show) ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <?= $form->label('orderBy', t('Order By')) ?>
                    <?= $form->select('orderBy', ['debt' => t('Debt'), 'credit' => t('Store Credit'), 'net' => t('Net'), 'name' => t('Name'), 'lastOrder' => t('Last paid order'), 'unpaidOrders' => t('Unpaid orders')], $orderBy) ?>
                    <?= $form->hidden('direction', $direction) ?>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label>&nbsp;</label><br>
                    <button type="submit" class="btn btn-default btn-secondary"><?= t('Filter Results') ?></button>
                </div>
            </div>
        </div>
    </form>

    <hr>

    <div class="row mb-4">
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Customers listed') ?></div><h4 class="mb-0"><?= $totals['customers'] ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Debts (%d customers, %d unpaid orders)', $totals['debtors'], $totals['unpaidOrders']) ?></div><h4 class="mb-0 text-danger"><?= Price::format($totals['debt']) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Store credit (%d customers)', $totals['creditors']) ?></div><h4 class="mb-0 text-success"><?= Price::format($totals['credit']) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Net (credit minus debt)') ?></div><h4 class="mb-0 <?= $totals['net'] < 0 ? 'text-danger' : 'text-success' ?>"><?= Price::format($totals['net']) ?></h4></div></div>
    </div>

    <table class="table table-striped">
        <thead>
        <tr>
            <th><a href="<?= $sortLink('name') ?>"><?= t('Customer') ?></a></th>
            <th><?= t('Email') ?></th>
            <th class="text-end"><a href="<?= $sortLink('unpaidOrders') ?>"><?= t('Unpaid Orders') ?></a></th>
            <th class="text-end"><a href="<?= $sortLink('debt') ?>"><?= t('Debt') ?></a></th>
            <th class="text-end"><a href="<?= $sortLink('credit') ?>"><?= t('Store Credit') ?></a></th>
            <th class="text-end"><a href="<?= $sortLink('net') ?>"><?= t('Net') ?></a></th>
            <th><a href="<?= $sortLink('lastOrder') ?>"><?= t('Last paid order') ?></a></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) { ?>
            <tr>
                <td><a href="<?= Url::to('/dashboard/store/customers/detail/' . $row['uID']) ?>"><?= h($row['name']) ?></a><?php if ($row['uID'] > 0 && !$row['active']) { ?> <span class="badge bg-secondary"><?= t('inactive') ?></span><?php } ?></td>
                <td><?= h($row['email']) ?></td>
                <td class="text-end"><?= $row['unpaidOrders'] ?></td>
                <td class="text-end <?= $row['debt'] > 0 ? 'text-danger' : '' ?>"><?= Price::format($row['debt']) ?></td>
                <td class="text-end <?= $row['credit'] > 0 ? 'text-success' : '' ?>"><?= Price::format($row['credit']) ?></td>
                <td class="text-end"><strong class="<?= $row['net'] < 0 ? 'text-danger' : ($row['net'] > 0 ? 'text-success' : '') ?>"><?= Price::format($row['net']) ?></strong></td>
                <td><?= $row['lastOrder'] ? $dh->formatDate(new \DateTime($row['lastOrder'])) : '' ?></td>
            </tr>
        <?php } ?>
        <?php if (empty($rows)) { ?>
            <tr><td colspan="7" class="text-muted"><?= t('No customers match.') ?></td></tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if ($paginator->getTotalPages() > 1) { ?>
        <?= $pagination ?>
    <?php } ?>
<?php } ?>

<?php if ($task == 'detail') { ?>
    <div class="ccm-dashboard-header-buttons">
        <?php if ($row['uID'] > 0) { ?>
            <a href="<?= Url::to('/dashboard/store/credit/history/' . $row['uID']) ?>" class="btn btn-secondary"><?= t('Store Credit history / adjust') ?></a>
        <?php } ?>
        <a href="<?= Url::to('/dashboard/store/customers') ?>" class="btn btn-secondary"><?= t('Back to report') ?></a>
    </div>

    <div class="row mb-4">
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Debt (%d unpaid orders)', $row['unpaidOrders']) ?></div><h4 class="mb-0 text-danger"><?= Price::format($row['debt']) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Store credit') ?></div><h4 class="mb-0 text-success"><?= Price::format($row['credit']) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Net') ?></div><h4 class="mb-0 <?= $row['net'] < 0 ? 'text-danger' : 'text-success' ?>"><?= Price::format($row['net']) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Paid orders') ?></div><h4 class="mb-0"><?= $row['paidOrders'] ?> <small class="text-muted"><?= Price::format($row['paidTotal']) ?></small></h4></div></div>
    </div>

    <h4><?= t('Order history') ?> <small class="text-muted"><?= t('%d unpaid, %d paid, %d refunded, %d cancelled, %d payment pending', $orderCounts['unpaid'], $orderCounts['paid'], $orderCounts['refunded'], $orderCounts['cancelled'], $orderCounts['incomplete']) ?></small></h4>
    <?php
    $stateLabels = ['unpaid' => t('Unpaid'), 'paid' => t('Paid'), 'cancelled' => t('Cancelled'), 'refunded' => t('Refunded'), 'incomplete' => t('Payment pending')];
    $stateBadges = ['unpaid' => 'bg-danger', 'paid' => 'bg-success', 'cancelled' => 'bg-secondary', 'refunded' => 'bg-warning text-dark', 'incomplete' => 'bg-info text-dark'];
    ?>
    <table class="table table-striped">
        <thead>
        <tr><th><?= t('Order #') ?></th><th><?= t('Date') ?></th><th><?= t('Payment') ?></th><th><?= t('Status') ?></th><th><?= t('Method') ?></th><th><?= t('Items') ?></th><th class="text-end"><?= t('Amount') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $entry) {
            $order = $entry['order'];
            $state = $entry['state'];
            $names = [];
            foreach ($order->getOrderItems() as $item) {
                $names[] = ((int) $item->getQuantity() > 1 ? (int) $item->getQuantity() . 'x ' : '') . $item->getProductName();
            }
            $stateDate = null;
            if ($state === 'paid') {
                $stateDate = $order->getPaid();
            } elseif ($state === 'refunded') {
                $stateDate = $order->getRefunded();
            } elseif ($state === 'cancelled') {
                $stateDate = $order->getCancelled();
            }
            $muted = in_array($state, ['cancelled', 'refunded'], true);
            ?>
            <tr class="<?= $state === 'unpaid' ? 'table-danger' : ($muted ? 'text-muted' : '') ?>">
                <td><a href="<?= Url::to('/dashboard/store/orders/order/' . $order->getOrderID()) ?>">#<?= $order->getOrderID() ?></a></td>
                <td><?= $dh->formatDateTime($order->getOrderDate()) ?></td>
                <td>
                    <span class="badge <?= $stateBadges[$state] ?>"><?= $stateLabels[$state] ?></span>
                    <?php if ($stateDate) { ?><br><small class="text-muted"><?= $dh->formatDateTime($stateDate) ?></small><?php } ?>
                    <?php if ($state === 'refunded' && $order->getRefundReason()) { ?><br><small class="text-muted"><?= h($order->getRefundReason()) ?></small><?php } ?>
                </td>
                <td><?= h($order->getStatus()) ?></td>
                <td><?= h($order->getPaymentMethodName()) ?></td>
                <td><?= h(implode(', ', $names)) ?></td>
                <td class="text-end <?= $muted ? '' : ($state === 'unpaid' ? 'text-danger' : '') ?>"><?= $muted ? '<s>' . Price::format($order->getTotal()) . '</s>' : Price::format($order->getTotal()) ?></td>
            </tr>
        <?php } ?>
        <?php if (empty($orders)) { ?><tr><td colspan="7" class="text-muted"><?= t('No orders.') ?></td></tr><?php } ?>
        </tbody>
    </table>

    <h4><?= t('Store credit') ?></h4>
    <table class="table table-striped">
        <thead>
        <tr><th><?= t('Date') ?></th><th><?= t('Comment') ?></th><th><?= t('Source') ?></th><th><?= t('Order') ?></th><th class="text-end"><?= t('Amount') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($creditEntries as $entry) { ?>
            <tr>
                <td><?= $dh->formatDateTime($entry->getCreatedAt()) ?></td>
                <td><?= h($entry->getComment()) ?></td>
                <td class="text-muted small"><?= h($entry->getSource()) ?></td>
                <td><?php if ($entry->getOrderID()) { ?><a href="<?= Url::to('/dashboard/store/orders/order/' . $entry->getOrderID()) ?>">#<?= $entry->getOrderID() ?></a><?php } ?></td>
                <td class="text-end <?= $entry->getAmount() < 0 ? 'text-danger' : 'text-success' ?>"><?= Price::format($entry->getAmount()) ?></td>
            </tr>
        <?php } ?>
        <?php if (empty($creditEntries)) { ?><tr><td colspan="5" class="text-muted"><?= t('No store credit entries.') ?></td></tr><?php } ?>
        </tbody>
    </table>
<?php } ?>
