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
            <strong>کد پترن:</strong>
            <input type="text" name="rules[${index}][pattern]" placeholder="کد پترن" style="width:100px;">
            <div style="margin-top:10px;">
                <strong>مقادیر متغیرها:</strong><br>
                <textarea name="rules[${index}][vars]" style="width:100%;height:50px;"
                    placeholder="name:{billing_first_name};order:{order_id}"></textarea>
            </div>
            <button type="button" class="button remove-row"
                style="color:#a00;border-color:#a00;margin-top:5px;">حذف این قانون</button>
        </div>`;
}

function validateRules(form) {
    var valid = true;

    form.querySelectorAll('#rules-container .rule-row').forEach(function (row, index) {
        if (!valid) return;

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

    var addBtn = document.getElementById('add-rule');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var container = document.getElementById('rules-container');
            container.insertAdjacentHTML('beforeend', buildRuleRow(Date.now()));
        });
    }

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
