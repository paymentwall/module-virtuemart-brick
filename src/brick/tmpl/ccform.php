<br/>
<span class="vmpayment_cardinfo" >
    <?php echo vmText::_('VMPAYMENT_BRICK_INFORMATION'); ?>:
    <table border="0" cellspacing="0" cellpadding="2" width="100%">
        <tr valign="top">
            <td nowrap width="10%" align="right">
                <label for="creditcardtype"><?php echo vmText::_('VMPAYMENT_BRICK_CCTYPE'); ?>: </label>
            </td>
            <td>
                <?php echo $viewData['credit_card_list']; ?>
            </td>
        </tr>
        <tr valign="top">
            <td nowrap width="10%" align="right">
                <label for="cc_type"><?php echo vmText::_('VMPAYMENT_BRICK_CCNUM'); ?>: </label>
            </td>
            <td>
                <input type="text" class="inputbox" id="cc_number_<?php echo $viewData['payment_id']; ?>"
                       name="cc_number_<?php echo $viewData['payment_id']; ?>"
                       autocomplete="off" value="<?php echo $viewData['cc_number']; ?>"/>
            </td>
        </tr>
        <tr valign="top">
            <td nowrap width="10%" align="right">
                <label for="cc_cvv"><?php echo vmText::_('VMPAYMENT_BRICK_CVV'); ?>: </label>
            </td>
            <td>
                <input type="text" class="inputbox" id="cc_cvv_<?php echo $viewData['payment_id']; ?>"
                       name="cc_cvv_<?php echo $viewData['payment_id']; ?>" maxlength="4" size="5"
                       autocomplete="off" value="<?php echo $viewData['cc_cvv']; ?>"/>
                <br/>
            </td>
        </tr>

        <tr>
            <td nowrap width="10%" align="right"><?php echo vmText::_('VMPAYMENT_BRICK_EXDATE'); ?>: </td>
            <td>
                <?php echo $viewData['list_month']; ?> / <?php echo $viewData['list_year']; ?>
            </td>
        </tr>
    </table>
</span>
<script src="https://api.paymentwall.com/brick/brick.1.3.js"></script>
<script type="text/javascript">
    jQuery(document).ready(function () {
        var publicKey = "<?php echo $viewData["public_key"]; ?>";
        var $form = jQuery("#checkoutForm");

        var brick = new Brick({
            public_key: publicKey,
            form: {formatter: true}
        }, "custom");

        $form.submit(function (e) {
            if (!jQuery("#payment_id_<?php echo $viewData['payment_id']; ?>").prop("checked")) {
                $form.get(0).submit();
                return false;
            }
            e.preventDefault();
            brick.tokenizeCard({
                card_number: jQuery("#cc_number_<?php echo $viewData['payment_id']; ?>").val(),
                card_expiration_month: jQuery("#cc_expire_month_<?php echo $viewData['payment_id']; ?>").val(),
                card_expiration_year: jQuery("#cc_expire_year_<?php echo $viewData['payment_id']; ?>").val(),
                card_cvv: jQuery("#cc_cvv_<?php echo $viewData['payment_id']; ?>").val()
            }, function (response) {
                if (response.type == "Error") {
                    // handle errors
                    jQuery(".alert-message").html(function () {
                        if (typeof response.error == "string") {
                            return "<li>" + response.error + "</li>";
                        } else {
                            return "<li>" + response.error.join("</li><li>") + "</li>";
                        }
                    });
                    jQuery("#checkoutFormSubmit").removeClass("vm-button").addClass("vm-button-correct").removeAttr("disabled");
                    jQuery(".vmLoadingDiv").hide();
                } else {
                    jQuery("<input>").attr({
                        type: "hidden",
                        name: "hiddenFingerprint",
                        value: Brick.getFingerprint(),
                    }).appendTo($form);
                    jQuery("<input>").attr({
                        type: "hidden",
                        name: "hiddenToken",
                        value: response.token,
                    }).appendTo($form);

                    jQuery("#payment-errors").hide();
                    $form.get(0).submit();
                }
            });
            return false;
        });
    });
</script>
<br/>