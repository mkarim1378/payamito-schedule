/* global jQuery, payamitoData */
jQuery(document).ready(function ($) {

    function buildStatusOptions() {
        var html = '';
        Object.keys(payamitoData.statuses).forEach(function (slug) {
            html += '<option value="' + slug + '">' + payamitoData.statuses[slug] + '</option>';
        });
        return html;
    }

    function buildRuleRow(index) {
        return (
            '<div class="rule-row" style="border:1px solid #ccc;padding:15px;margin-bottom:10px;background:#fff;">' +
                '<strong>اگر سفارش:</strong> ' +
                '<select name="rules[' + index + '][status]">' + buildStatusOptions() + '</select> ' +
                '<strong>شد، بعد از:</strong> ' +
                '<input type="number" name="rules[' + index + '][delay_val]" value="0" style="width:60px;"> ' +
                '<select name="rules[' + index + '][delay_unit]">' +
                    '<option value="minutes">دقیقه</option>' +
                    '<option value="hours">ساعت</option>' +
                    '<option value="days">روز</option>' +
                '</select>' +
                '<hr style="margin:10px 0;border:0;border-top:1px solid #eee;">' +
                '<strong>کد پترن:</strong> ' +
                '<input type="text" name="rules[' + index + '][pattern]" placeholder="کد پترن" style="width:100px;">' +
                '<div style="margin-top:10px;">' +
                    '<strong>مقادیر متغیرها:</strong><br>' +
                    '<textarea name="rules[' + index + '][vars]" style="width:100%;height:50px;" ' +
                        'placeholder="name:{billing_first_name};order:{order_id}"></textarea>' +
                '</div>' +
                '<button type="button" class="button remove-row" ' +
                    'style="color:#a00;border-color:#a00;margin-top:5px;">حذف این قانون</button>' +
            '</div>'
        );
    }

    $('#add-rule').on('click', function () {
        var index = Date.now();
        $('#rules-container').append(buildRuleRow(index));
    });

    $(document).on('click', '.remove-row', function () {
        if (confirm(payamitoData.confirmDelete)) {
            $(this).closest('.rule-row').remove();
        }
    });

    $('button[name="save_rules"]').closest('form').on('submit', function (e) {
        var valid = true;

        $('#rules-container .rule-row').each(function (index) {
            var pattern   = $(this).find('input[name*="[pattern]"]').val().trim();
            var varsField = $(this).find('textarea[name*="[vars]"]');
            var varsVal   = varsField.val().trim();

            if (pattern.length > 0 && varsVal.length > 0) {
                var pairs = varsVal.split(';');
                for (var i = 0; i < pairs.length; i++) {
                    var pair = pairs[i].trim();
                    if (pair.length > 0 && pair.indexOf(':') === -1) {
                        alert(
                            'خطا در قانون شماره ' + (index + 1) + ':\n' +
                            'متغیر "' + pair + '" فرمت صحیحی ندارد.\n' +
                            'از فرمت key:value استفاده کنید.'
                        );
                        varsField.css('border', '2px solid red').focus();
                        valid = false;
                        return false;
                    }
                }
            }

            if (valid) {
                varsField.css('border', '');
            }
        });

        if (!valid) {
            e.preventDefault();
        }
    });
});
