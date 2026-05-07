/* global payamitoData */

function buildStatusOptions() {
    return Object.entries(payamitoData.statuses)
        .map(([slug, label]) => `<option value="${slug}">${label}</option>`)
        .join('');
}

function buildRuleRow(index) {
    return `
        <div class="rule-row" style="border:1px solid #ccc;padding:15px;margin-bottom:10px;background:#fff;">
            <strong>اگر سفارش:</strong>
            <select name="rules[${index}][status]">${buildStatusOptions()}</select>
            <strong> شد، بعد از:</strong>
            <input type="number" name="rules[${index}][delay_val]" value="0" style="width:60px;">
            <select name="rules[${index}][delay_unit]">
                <option value="minutes">دقیقه</option>
                <option value="hours">ساعت</option>
                <option value="days">روز</option>
            </select>
            <hr style="margin:10px 0;border:0;border-top:1px solid #eee;">
            <strong>نوع ارسال:</strong>
            <select name="rules[${index}][send_type]" class="send-type-select">
                <option value="text">متن ثابت (SmartSMS)</option>
                <option value="pattern">پترن (خط خدماتی)</option>
            </select>
            <div class="pattern-fields" style="margin-top:10px;display:none;">
                <strong>کد پترن:</strong>
                <input type="text" name="rules[${index}][pattern]" placeholder="کد پترن" style="width:100px;">
                <div style="margin-top:10px;">
                    <strong>مقادیر متغیرها:</strong><br>
                    <textarea name="rules[${index}][vars]" style="width:100%;height:50px;"
                        placeholder="name:{billing_first_name};order:{order_id}"></textarea>
                </div>
            </div>
            <div class="text-fields" style="margin-top:10px;display:none;">
                <strong>متن پیامک:</strong><br>
                <textarea name="rules[${index}][text_body]" style="width:100%;height:160px;"
                    placeholder="سفارش شما #{order_id} ثبت شد. با تشکر، {billing_first_name} عزیز."></textarea>
                <p class="description">
                    شورت‌کدها: <code>{billing_first_name}</code>, <code>{billing_last_name}</code>,
                    <code>{order_id}</code>, <code>{order_total}</code>, <code>{billing_phone}</code>,
                    <code>{product_names}</code>, <code>{product_links}</code>, <code>{payment_link}</code>,
                    <code>{coupon_code}</code>
                </p>
            </div>
            <div class="coupon-section" style="margin-top:10px;border-top:1px solid #eee;padding-top:10px;">
                <label>
                    <input type="checkbox" name="rules[${index}][coupon_enabled]" value="1" class="coupon-toggle">
                    <strong>ارسال کد تخفیف اتوماتیک</strong>
                </label>
                <div class="coupon-fields" style="margin-top:10px;display:none;">
                    <table style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="padding:4px 8px 4px 0;width:130px;"><label>مقدار تخفیف:</label></td>
                            <td>
                                <input type="number" name="rules[${index}][coupon_amount]" value="0" min="0" step="any" style="width:80px;">
                                <select name="rules[${index}][coupon_type]">
                                    <option value="percent">درصد (%)</option>
                                    <option value="fixed">مبلغ ثابت (تومان)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;"><label>انقضا (ساعت):</label></td>
                            <td><input type="number" name="rules[${index}][coupon_expiry_hours]" value="24" min="1" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;vertical-align:top;padding-top:8px;"><label>حالت کد تخفیف:</label></td>
                            <td style="padding-top:8px;">
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="radio" name="rules[${index}][coupon_mode]" value="code" checked>
                                    کد در متن پیامک — از شورت‌کد <code>{coupon_code}</code> استفاده کنید
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="rules[${index}][coupon_mode]" value="payment">
                                    اعمال روی سفارش (لینک پرداخت به‌روزرسانی می‌شود)
                                </label>
                                <p class="description" style="margin-top:4px;">کد: <code>carno{order_id}</code> — یکبار مصرف، فقط برای ایمیل مشتری</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <button type="button" class="button remove-row"
                style="color:#a00;border-color:#a00;margin-top:10px;">حذف این قانون</button>
        </div>`;
}

function toggleCouponFields(checkbox) {
    var fields = checkbox.closest('.coupon-section').querySelector('.coupon-fields');
    if (fields) fields.style.display = checkbox.checked ? '' : 'none';
}

function toggleSendTypeFields(select) {
    var row       = select.closest('.rule-row');
    var isText    = select.value === 'text';
    row.querySelector('.pattern-fields').style.display = isText ? 'none' : '';
    row.querySelector('.text-fields').style.display    = isText ? '' : 'none';
}

function validateRules(form) {
    var valid = true;

    form.querySelectorAll('#rules-container .rule-row').forEach(function (row, index) {
        if (!valid) return;

        var sendType  = row.querySelector('select[name*="[send_type]"]').value;

        if (sendType === 'text') {
            var textField = row.querySelector('textarea[name*="[text_body]"]');
            if (!textField.value.trim()) {
                alert('خطا در قانون شماره ' + (index + 1) + ':\nمتن پیامک نمی‌تواند خالی باشد.');
                textField.style.border = '2px solid red';
                textField.focus();
                valid = false;
            } else {
                textField.style.border = '';
            }
            return;
        }

        var pattern   = row.querySelector('input[name*="[pattern]"]').value.trim();
        var varsField = row.querySelector('textarea[name*="[vars]"]');
        var varsVal   = varsField.value.trim();

        if (pattern.length > 0 && varsVal.length > 0) {
            var pairs = varsVal.split(';');
            for (var i = 0; i < pairs.length; i++) {
                var pair = pairs[i].trim();
                if (pair.length > 0 && !pair.includes(':')) {
                    alert(
                        'خطا در قانون شماره ' + (index + 1) + ':\n' +
                        'متغیر "' + pair + '" فرمت صحیحی ندارد.\n' +
                        'از فرمت key:value استفاده کنید.'
                    );
                    varsField.style.border = '2px solid red';
                    varsField.focus();
                    valid = false;
                    return;
                }
            }
        }

        if (valid) varsField.style.border = '';
    });

    return valid;
}

document.addEventListener('DOMContentLoaded', function () {

    // init toggles for existing saved rows
    document.querySelectorAll('#rules-container .send-type-select').forEach(toggleSendTypeFields);
    document.querySelectorAll('#rules-container .coupon-toggle').forEach(toggleCouponFields);

    var addBtn = document.getElementById('add-rule');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var container = document.getElementById('rules-container');
            container.insertAdjacentHTML('beforeend', buildRuleRow(Date.now()));
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('send-type-select')) {
            toggleSendTypeFields(e.target);
        }
        if (e.target.classList.contains('coupon-toggle')) {
            toggleCouponFields(e.target);
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            if (confirm(payamitoData.confirmDelete)) {
                e.target.closest('.rule-row').remove();
            }
        }
    });

    var saveBtn = document.querySelector('button[name="save_rules"]');
    if (saveBtn) {
        saveBtn.closest('form').addEventListener('submit', function (e) {
            if (!validateRules(this)) e.preventDefault();
        });
    }
});
