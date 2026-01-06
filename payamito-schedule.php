<?php
/**
 * Plugin Name: زمان‌بندی پیامک پیامیتو (Payamito Scheduler)
 * Description: افزونه جانبی برای ارسال زمان‌بندی شده پیامک‌های ووکامرس با پترن (خط خدماتی).
 * Version: 1.0.0
 * Author: Payamito User
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit; // امنیت: جلوگیری از دسترسی مستقیم
}

// بررسی فعال بودن ووکامرس (برای جلوگیری از خطا)
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

if (!class_exists('Payamito_Custom_Scheduler')) {

    class Payamito_Custom_Scheduler {

        // --- تنظیمات پنل پیامک ---
        // لطفاً نام کاربری و رمز عبور پنل پیامکی خود را اینجا وارد کنید
        private $username = 'USER';
        private $password = 'PASS';

        public function __construct() {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'handle_form_submission']);
            
            // هوک تغییر وضعیت سفارش ووکامرس
            add_action('woocommerce_order_status_changed', [$this, 'schedule_sms_on_status_change'], 10, 4);
            
            // اکشن اختصاصی برای اجرای کران جاب
            add_action('payamito_execute_scheduled_sms', [$this, 'execute_scheduled_sms'], 10, 3);
        }

        public function add_admin_menu() {
            add_menu_page(
                'زمان‌بندی پیامک',
                'زمان‌بندی پیامک',
                'manage_options',
                'payamito-scheduler',
                [$this, 'render_settings_page'],
                'dashicons-clock',
                50
            );
        }

        public function render_settings_page() {
            $rules = get_option('payamito_schedule_rules', []);
            ?>
            <div class="wrap">
                <h1>تنظیمات زمان‌بندی و تست پیامک (پیامیتو)</h1>
                
                <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
                    <h2>🧪 تست ارسال پیامک (پترن)</h2>
                    <p class="description">از این بخش برای اطمینان از صحت نام کاربری، رمز عبور و کد پترن استفاده کنید.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('payamito_test_sms', 'payamito_test_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label>شماره موبایل:</label></th>
                                <td><input type="text" name="test_mobile" class="regular-text" placeholder="مثال: 09120000000" required></td>
                            </tr>
                            <tr>
                                <th><label>کد پترن (BodyId):</label></th>
                                <td><input type="text" name="test_pattern" class="regular-text" placeholder="مثال: 12345" required></td>
                            </tr>
                            <tr>
                                <th><label>متغیرها (JSON):</label></th>
                                <td>
                                    <input type="text" name="test_args" class="large-text" placeholder='{"0":"علی", "1":"1025"}'>
                                    <p class="description">
                                        طبق مستندات، متغیرها باید به ترتیب ارسال شوند. می‌توانید از فرمت JSON استفاده کنید.<br>
                                        مثال: <code>{"name":"reza"}</code> یا اگر کلید مهم نیست <code>["reza", "123"]</code>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" name="submit_test_sms" class="button button-primary">ارسال پیامک تست</button>
                    </form>
                </div>

                <hr>

                <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
                    <h2>📅 قوانین زمان‌بندی خودکار</h2>
                    <p class="description">در اینجا مشخص کنید که چند وقت پس از تغییر وضعیت سفارش، چه پیامکی ارسال شود.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('payamito_save_rules', 'payamito_rules_nonce'); ?>
                        
                        <div id="rules-container">
                            <?php if (!empty($rules)) : foreach ($rules as $index => $rule) : ?>
                                <div class="rule-row" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 10px; background: #fff;">
                                    <strong>اگر سفارش:</strong>
                                    <select name="rules[<?php echo $index; ?>][status]">
                                        <?php foreach (wc_get_order_statuses() as $slug => $label) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($rule['status'], $slug); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <strong>شد،</strong>

                                    <strong>بعد از:</strong>
                                    <input type="number" name="rules[<?php echo $index; ?>][delay_val]" value="<?php echo esc_attr($rule['delay_val']); ?>" style="width: 60px;">
                                    <select name="rules[<?php echo $index; ?>][delay_unit]">
                                        <option value="minutes" <?php selected($rule['delay_unit'], 'minutes'); ?>>دقیقه</option>
                                        <option value="hours" <?php selected($rule['delay_unit'], 'hours'); ?>>ساعت</option>
                                        <option value="days" <?php selected($rule['delay_unit'], 'days'); ?>>روز</option>
                                    </select>

                                    <hr style="margin: 10px 0; border: 0; border-top: 1px solid #eee;">

                                    <strong>ارسال پترن با کد:</strong>
                                    <input type="text" name="rules[<?php echo $index; ?>][pattern]" value="<?php echo esc_attr($rule['pattern']); ?>" placeholder="کد پترن" style="width: 100px;">

                                    <div style="margin-top: 10px;">
                                        <strong>مقادیر متغیرها (به ترتیب):</strong><br>
                                        <textarea name="rules[<?php echo $index; ?>][vars]" style="width: 100%; height: 50px;" placeholder="name:{billing_first_name};order:{order_id}"><?php echo esc_textarea($rule['vars']); ?></textarea>
                                        <p class="description">
                                            فرمت: <code>key:value;key2:value2</code><br>
                                            شورت‌کدها: <code>{billing_first_name}</code>, <code>{billing_last_name}</code>, <code>{order_id}</code>, <code>{order_total}</code>, <code>{billing_phone}</code><br>
                                            <span style="color:red">نکته مهم:</span> ترتیب نوشتن متغیرها در اینجا باید دقیقاً با ترتیب متغیرهای تعریف شده در پترن پنل پیامک یکی باشد.
                                        </p>
                                    </div>
                                    <button type="button" class="button remove-row" style="color: #a00; border-color: #a00; margin-top:5px;">حذف این قانون</button>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <button type="button" id="add-rule" class="button button-secondary">➕ افزودن قانون جدید</button>
                        <br><br>
                        <button type="submit" name="save_rules" class="button button-primary button-hero">ذخیره تنظیمات</button>
                    </form>
                </div>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    // افزودن سطر جدید
                    $('#add-rule').click(function() {
                        var index = $('.rule-row').length;
                        var template = `
                            <div class="rule-row" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 10px; background: #fff;">
                                <strong>اگر سفارش:</strong>
                                <select name="rules[${index}][status]">
                                    <?php foreach (wc_get_order_statuses() as $slug => $label) echo "<option value='$slug'>$label</option>"; ?>
                                </select>
                                <strong>شد، بعد از:</strong>
                                <input type="number" name="rules[${index}][delay_val]" value="0" style="width: 60px;">
                                <select name="rules[${index}][delay_unit]">
                                    <option value="minutes">دقیقه</option>
                                    <option value="hours">ساعت</option>
                                    <option value="days">روز</option>
                                </select>
                                <hr style="margin: 10px 0; border: 0; border-top: 1px solid #eee;">
                                <strong>کد پترن:</strong>
                                <input type="text" name="rules[${index}][pattern]" placeholder="کد پترن" style="width: 100px;">
                                <div style="margin-top: 10px;">
                                    <strong>مقادیر متغیرها:</strong><br>
                                    <textarea name="rules[${index}][vars]" style="width: 100%; height: 50px;" placeholder="name:{billing_first_name};order:{order_id}"></textarea>
                                </div>
                                <button type="button" class="button remove-row" style="color: #a00; border-color: #a00; margin-top:5px;">حذف این قانون</button>
                            </div>
                        `;
                        $('#rules-container').append(template);
                    });

                    // حذف سطر
                    $(document).on('click', '.remove-row', function() {
                        if(confirm('آیا مطمئن هستید؟')) {
                            $(this).closest('.rule-row').remove();
                        }
                    });

                    // اعتبارسنجی (Validation) هنگام ارسال فرم
                    // فرمی که شامل دکمه save_rules است را انتخاب می‌کنیم
                    $('button[name="save_rules"]').closest('form').on('submit', function(e) {
                        var isValid = true;
                        
                        $('#rules-container .rule-row').each(function(index) {
                            var pattern = $(this).find('input[name*="[pattern]"]').val().trim();
                            var varsField = $(this).find('textarea[name*="[vars]"]');
                            var varsVal = varsField.val().trim();

                            // اگر پترن وارد شده باشد ولی متغیرها فرمت اشتباه داشته باشند
                            if (pattern.length > 0 && varsVal.length > 0) {
                                // جدا کردن جفت‌ها با ;
                                var pairs = varsVal.split(';');
                                
                                for (var i = 0; i < pairs.length; i++) {
                                    var pair = pairs[i].trim();
                                    // اگر جفت خالی نیست و علامت : ندارد، یعنی فرمت غلط است
                                    if (pair.length > 0 && pair.indexOf(':') === -1) {
                                        alert('خطا در قانون شماره ' + (index + 1) + ':\n' +
                                              'متغیر "' + pair + '" فرمت صحیحی ندارد.\n' +
                                              'لطفاً از فرمت key:value استفاده کنید.\n' +
                                              'مثال: name:{billing_first_name}');
                                        
                                        varsField.css('border', '2px solid red').focus();
                                        isValid = false;
                                        return false; // شکستن حلقه each
                                    }
                                }
                            }
                            // حذف استایل خطا اگر اصلاح شده باشد
                            if(isValid) {
                                varsField.css('border', '');
                            }
                        });

                        if (!isValid) {
                            e.preventDefault(); // جلوگیری از ارسال فرم
                        }
                    });
                });
            </script>
            <?php
        }

        // --- پردازش فرم‌ها ---
        public function handle_form_submission() {
            // پردازش فرم تست
            if (isset($_POST['submit_test_sms']) && check_admin_referer('payamito_test_sms', 'payamito_test_nonce')) {
                $mobile = sanitize_text_field($_POST['test_mobile']);
                $pattern = sanitize_text_field($_POST['test_pattern']);
                $args_json = stripslashes($_POST['test_args']);
                $args = json_decode($args_json, true);
                
                if (!$args) $args = []; 

                $result = $this->send_pattern_sms($mobile, $pattern, $args);
                
                // بررسی نتیجه ساده (معمولاً اگر عدد باشد یعنی کد پیگیری است)
                if ($result && !is_soap_fault($result)) {
                    // تبدیل نتیجه به رشته برای نمایش
                    $msg = is_array($result) || is_object($result) ? print_r($result, true) : $result;
                    add_settings_error('payamito_msg', 'payamito_msg', 'درخواست ارسال شد. خروجی وب‌سرویس: ' . $msg, 'success');
                } else {
                    add_settings_error('payamito_msg', 'payamito_msg', 'خطا در ارسال. لطفاً تنظیمات را چک کنید.', 'error');
                }
            }

            // پردازش ذخیره قوانین
            if (isset($_POST['save_rules']) && check_admin_referer('payamito_save_rules', 'payamito_rules_nonce')) {
                $rules = isset($_POST['rules']) ? $_POST['rules'] : [];
                $clean_rules = [];
                foreach ($rules as $r) {
                    if (!empty($r['pattern'])) {
                        // پاکسازی ورودی‌ها
                        $r['pattern'] = sanitize_text_field($r['pattern']);
                        $r['vars'] = sanitize_textarea_field($r['vars']);
                        $r['delay_val'] = intval($r['delay_val']);
                        $clean_rules[] = $r;
                    }
                }
                update_option('payamito_schedule_rules', $clean_rules);
                add_settings_error('payamito_msg', 'payamito_msg', 'قوانین با موفقیت ذخیره شدند.', 'success');
            }
        }

        // --- هوک‌های منطقی ---

        public function schedule_sms_on_status_change($order_id, $from_status, $to_status, $order) {
            $rules = get_option('payamito_schedule_rules', []);
            $new_status_prefixed = 'wc-' . $to_status; // تبدیل status به فرمت wc-completed

            foreach ($rules as $rule) {
                // مقایسه وضعیت
                if ($rule['status'] === $new_status_prefixed) {
                    
                    $delay_val = intval($rule['delay_val']);
                    $delay_unit = $rule['delay_unit'];
                    $delay_seconds = 0;

                    switch ($delay_unit) {
                        case 'minutes': $delay_seconds = $delay_val * 60; break;
                        case 'hours':   $delay_seconds = $delay_val * 3600; break;
                        case 'days':    $delay_seconds = $delay_val * 86400; break;
                    }

                    $run_time = time() + $delay_seconds;
                    
                    // زمان‌بندی در WP-Cron
                    // آرگومان‌ها باید داخل یک آرایه باشند
                    wp_schedule_single_event($run_time, 'payamito_execute_scheduled_sms', [
                        $order_id,
                        $rule['pattern'],
                        $rule['vars']
                    ]);
                }
            }
        }

        public function execute_scheduled_sms($order_id, $pattern_code, $vars_str) {
            $order = wc_get_order($order_id);
            if (!$order) return;

            $phone = $order->get_billing_phone();
            if (empty($phone)) return;
            
            // پردازش متغیرها: تبدیل رشته key:value;... به آرایه
            $pairs = explode(';', $vars_str);
            $sms_args = [];

            foreach ($pairs as $pair) {
                // اگر کاربر چیزی ننوشته باشد، رد کن
                if (trim($pair) === '') continue;

                $parts = explode(':', $pair, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value_template = trim($parts[1]);

                    // جایگذاری مقادیر داینامیک
                    $value = str_replace(
                        ['{billing_first_name}', '{billing_last_name}', '{order_id}', '{order_total}', '{billing_phone}'],
                        [
                            $order->get_billing_first_name(),
                            $order->get_billing_last_name(),
                            $order->get_id(),
                            $order->get_total(),
                            $order->get_billing_phone()
                        ],
                        $value_template
                    );
                    $sms_args[$key] = $value;
                }
            }

            // ارسال نهایی
            $this->send_pattern_sms($phone, $pattern_code, $sms_args);
        }

        // --- وب‌سرویس ---
        
        // تابع اصلاح شده طبق داکیومنت PDF (SendByBaseNumber)
        private function send_pattern_sms($mobile, $bodyId, $args_array) {
            try {
                $client = new SoapClient("http://api.payamak-panel.com/post/Send.asmx?wsdl");
                
                // طبق داکیومنت: آرایه مقادیر باید ارسال شود.
                // تبدیل به آرایه اندیس‌دار ساده برای حفظ ترتیب
                $text_array = array_values($args_array); 
                
                $params = array(
                    'username' => $this->username,
                    'password' => $this->password,
                    'text'     => $text_array, // فرمت: String[]
                    'to'       => $mobile,
                    'bodyId'   => intval($bodyId)
                );
                
                $result = $client->SendByBaseNumber($params); 
                return $result;

            } catch (Exception $e) {
                return 'Error: ' . $e->getMessage();
            }
        }
    }

    new Payamito_Custom_Scheduler();
}
?>