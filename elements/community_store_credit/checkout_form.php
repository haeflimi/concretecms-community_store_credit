<?php defined('C5_EXECUTE') or die(_("Access Denied.")); ?>
<p>
    <?= t('Your store credit: %s', '<strong>' . number_format((float) $balance, 2) . '</strong>') ?><br>
    <?= t('This order (%s) will be deducted from it.', number_format((float) $total, 2)) ?>
</p>
<script>
    $(function () {
        $('div[data-payment-method-id=<?= (int) $pmID ?>] .store-btn-complete-order').click(function () {
            $(this).attr({disabled: true}).val(<?= json_encode(t('Processing...')) ?>);
            $(this).closest('form').submit();
        });
    });
</script>
