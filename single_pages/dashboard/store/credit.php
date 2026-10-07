<?php defined('C5_EXECUTE') or die("Access Denied.");
$form = Core::make('helper/form'); ?>
<div class="ccm-dashboard-content-inner">
    <form method="get" action="<?= $controller->action('') ?>" class="form-inline mb-3">
        <?= $form->search('keywords', $keywords, ['placeholder' => t('Name, e-mail or user id'), 'class' => 'form-control me-2']) ?>
        <button type="submit" class="btn btn-secondary"><?= t('Search') ?></button>
    </form>
    <?php if (empty($balances)): ?>
        <p class="text-muted"><?= t('No store credit entries yet.') ?></p>
    <?php else: ?>
        <table class="table table-striped">
            <thead>
            <tr>
                <th><?= t('Customer') ?></th>
                <th><?= t('E-Mail') ?></th>
                <th class="text-end"><?= t('Balance') ?></th>
                <th class="text-end"><?= t('Entries') ?></th>
                <th><?= t('Last entry') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($balances as $row): ?>
                <tr>
                    <td><?= h($row['uName'] ?: t('Deleted user #%s', $row['uID'])) ?></td>
                    <td><?= h($row['uEmail']) ?></td>
                    <td class="text-end <?= $row['balance'] < 0 ? 'text-danger' : '' ?>"><strong><?= number_format((float) $row['balance'], 2) ?></strong></td>
                    <td class="text-end"><?= (int) $row['entries'] ?></td>
                    <td><?= h($row['lastEntry']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-secondary" href="<?= $controller->action('history', $row['uID']) ?>"><?= t('History / Adjust') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
