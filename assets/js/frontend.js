(function ($) {
    var $input = $('#payamito-coupon-input');
    var $btn   = $('#payamito-coupon-btn');
    var $msg   = $('#payamito-coupon-msg');
    var i18n   = payamitoFrontend.i18n;

    $btn.on('click', applyClick);
    $input.on('keypress', function (e) {
        if (e.which === 13) applyClick();
    });

    function applyClick() {
        var code = $input.val().trim();
        if (!code) {
            showMsg(i18n.empty, 'error');
            return;
        }

        $btn.prop('disabled', true).text(i18n.applying);
        hideMsg();

        $.post(payamitoFrontend.ajax_url, {
            action:      'payamito_apply_coupon',
            nonce:       payamitoFrontend.nonce,
            order_id:    payamitoFrontend.order_id,
            order_key:   payamitoFrontend.order_key,
            coupon_code: code,
        })
        .done(function (res) {
            if (res.success) {
                showMsg(res.data.message, 'success');
                updateTotals(res.data);
                $input.prop('disabled', true);
                $btn.text(i18n.applied);
            } else {
                showMsg(res.data.message, 'error');
                $btn.prop('disabled', false).text(i18n.apply);
            }
        })
        .fail(function () {
            showMsg(i18n.server_error, 'error');
            $btn.prop('disabled', false).text(i18n.apply);
        });
    }

    function showMsg(text, type) {
        var styles = type === 'success'
            ? 'color:#1a6a38;background:#d4edda;border:1px solid #b7dfca;'
            : 'color:#842029;background:#f8d7da;border:1px solid #f5c2c7;';
        $msg.attr('style', 'margin-top:10px;font-size:13px;padding:8px 12px;border-radius:4px;' + styles).text(text).show();
    }

    function hideMsg() {
        $msg.hide().text('');
    }

    function updateTotals(data) {
        var $tfoot      = $('table.shop_table tfoot');
        var $orderTotal = $tfoot.find('.order-total');

        // remove previous payamito discount row if re-applied somehow
        $tfoot.find('.payamito-discount-row').remove();

        $orderTotal.before(
            '<tr class="payamito-discount-row">' +
            '<th style="color:#1a6a38;">' + data.discount_label + '</th>' +
            '<td><strong style="color:#1a6a38;">- ' + data.discount + '</strong></td>' +
            '</tr>'
        );

        $orderTotal.find('td').html('<strong>' + data.total + '</strong>');
    }
})(jQuery);
