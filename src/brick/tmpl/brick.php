<div>
    <?php if ($viewData['status'] == 'OK') { ?>
        <div><?php echo vmText::_('VMPAYMENT_BRICK_YOUR_ORDER'); ?>: <b><?php echo $viewData['order_number']; ?></b></div>
        <div><?php echo vmText::_('VMPAYMENT_BRICK_YOUR_TRANSACTION_ID'); ?>: <?php echo $viewData['charge_id']; ?></div>
        <div><?php echo vmText::_('VMPAYMENT_BRICK_AMOUNT'); ?>: <?php echo $viewData['amount']; ?> <?php echo $viewData['currency']; ?></div>
        <div><?php echo vmText::_('VMPAYMENT_BRICK_CREDIT_CARD'); ?>: xxxx-<?php echo $viewData['card']['last4']; ?></div>
    <?php } else { ?>
        <div><?php echo vmText::_('VMPAYMENT_BRICK_ERROR'); ?>: <?php echo $viewData['message']; ?></div>
    <?php } ?>
</div>
