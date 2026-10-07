<?php defined('C5_EXECUTE') or die("Access Denied.");
$form = Core::make('helper/form'); ?>
<div class="ccm-dashboard-content-inner">
    <p><a href="<?= $controller->action('') ?>">&laquo; <?= t('All balances') ?></a></p>
    <h3><?= h($userInfo->getUserName()) ?> <small class="text-muted"><?= h($userInfo->getUserEmail()) ?></small></h3>
    <p><?= t('Balance') ?>: <strong class="<?= $balance < 0 ? 'text-danger' : '' ?>"><?= number_format($balance, 2) ?></strong></p>

    <form method="post" action="<?= $controller->action('adjust') ?>" class="card card-body mb-4">
        <?= $token->output('csc_adjust') ?>
        <input type="hidden" name="uID" value="<?= (int) $uID ?>">
        <input type="hidden" name="bookingId" value="<?= h(uniqid('adj_', true)) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <?= $form->label('amount', t('Amount (+ adds, - deducts)')) ?>
                <?= $form->number('amount', '', ['step' => '0.01', 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-6">
                <?= $form->label('comment', t('Comment')) ?>
                <?= $form->text('comment', '', ['class' => 'form-control']) ?>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary" onclick="this.disabled=true;this.form.submit();"><?= t('Book') ?></button>
            </div>
        </div>
    </form>

    <table class="table table-striped">
        <thead>
        <tr>
            <th><?= t('Date') ?></th>
            <th><?= t('Comment') ?></th>
            <th><?= t('Source') ?></th>
            <th><?= t('Order') ?></th>
            <th class="text-end"><?= t('Amount') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= $entry->getCreatedAt()->format('d.m.Y H:i') ?></td>
                <td><?= h($entry->getComment()) ?></td>
                <td class="text-muted small"><?= h($entry->getSource()) ?>:<?= h($entry->getExternalRef()) ?></td>
                <td><?php if ($entry->getOrderID()): ?><a href="<?= URL::to('/dashboard/store/orders/order', $entry->getOrderID()) ?>">#<?= $entry->getOrderID() ?></a><?php endif; ?></td>
                <td class="text-end <?= $entry->getAmount() < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($entry->getAmount(), 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
