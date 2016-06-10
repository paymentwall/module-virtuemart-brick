<script src="https://api.paymentwall.com/brick/brick.1.3.js"></script>
<div id="payment-form-container"></div>
<script>
    var brick = new Brick({
        public_key: '<?php echo $viewData["public_key"]; ?>',
        amount: <?php echo $viewData["order_total"]; ?>,
        currency: '<?php echo $viewData["currency_code"]; ?>',
        container: 'payment-form-container',
        action: '<?php echo $viewData["action_url"];?>',
        form: {
            merchant: '<?php echo $viewData["order_name"]; ?>',
            product: 'Order #<?php echo $viewData["order_number"]; ?>',
            pay_button: 'Pay',
            zip: true
        }
    });

    brick.showPaymentForm(function (data) {
            if (data.success != 1) {
                jQuery("#err-container").html(data.error.message);
            } else {
                jQuery("#err-container").css("color", "#6B9B20");
                jQuery("#err-container").html("Order has been paid successfully !");

                window.location.href = "<?php echo $viewData["base_url"]; ?>";
            }
            jQuery("#err-container").show();
        },
        function (errors) {
            // handle errors
        }
    )
    ;
</script>
<style>
    .brick-input-l, .brick-input-s {
        height: 30px !important;
        padding-left: 28px !important;
    }
    .brick-iw-email:before, .brick-iw-cvv:before, .brick-iw-exp:before, .brick-iw-cc:before {
        margin: 4px 0 0 9px;
    }
    .brick-cvv-icon {
        top:2px;
    }
</style>