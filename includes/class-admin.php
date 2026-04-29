<?php
if (!defined('ABSPATH')) exit;

class Payamito_Admin {

    public function __construct() {
        add_action('admin_menu',             [$this, 'add_menu']);
        add_action('admin_init',             [$this, 'handle_submission']);
        add_action('admin_enqueue_scripts',  [$this, 'enqueue_scripts']);
    }

    // -------------------------------------------------------------------------
    // Menu & Scripts
    // -------------------------------------------------------------------------

    public function add_menu(): void {
        add_menu_page(
            'زمان‌بندی پیامک',
            'زمان‌بندی پیامک',
            'manage_options',
            'payamito-scheduler',
            [$this, 'render_page'],
            'dashicons-clock',
            50
        );
    }

    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'toplevel_page_payamito-scheduler') return;

        wp_enqueue_script(
            'payamito-admin',
            PAYAMITO_SCHEDULE_URL . 'assets/js/admin.js',
            ['jquery'],
            PAYAMITO_SCHEDULE_VERSION,
            true
        );

        wp_localize_script('payamito-admin', 'payamitoData', [
            'statuses'      => wc_get_order_statuses(),
            'confirmDelete' => 'آیا مطمئن هستید؟',
        ]);
    }

    // -------------------------------------------------------------------------
    // Page Rendering
    // -------------------------------------------------------------------------

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;

        settings_errors('payamito_msg');

        $tab      = sanitize_key($_GET['tab'] ?? 'settings');
        $base_url = admin_url('admin.php?page=payamito-scheduler');
        ?>
        <div class="wrap">
            <h1>زمان‌بندی پیامک (پیامیتو)</h1>
            <nav class="nav-tab-wrapper" style="margin-bottom:0;">
                <a href="<?php echo esc_url($base_url); ?>"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ تنظیمات
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'log', $base_url)); ?>"
                   class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">
                    📋 تاریخچه ارسال
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'stats', $base_url)); ?>"
                   class="nav-tab <?php echo $tab === 'stats' ? 'nav-tab-active' : ''; ?>">
                    📊 آمار
                </a>
            </nav>
            <?php if ($tab === 'log') : ?>
                <?php $this->render_log_tab(); ?>
            <?php elseif ($tab === 'stats') : ?>
                <?php $this->render_stats_tab(); ?>
            <?php else : ?>
                <?php
                $rules       = get_option('payamito_schedule_rules', []);
                $credentials = get_option('payamito_credentials', ['username' => '', 'password' => '', 'log_retention_days' => 90]);
                $statuses    = wc_get_order_statuses();
                $this->render_credentials_section($credentials);
                $this->render_test_section();
                $this->render_rules_section($rules, $statuses);
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_log_tab(): void {
        $table = new Payamito_Log_List_Table();
        $table->prepare_items();
        ?>
        <div style="margin-top:20px;">
            <?php $table->views(); ?>
            <form method="get">
                <input type="hidden" name="page" value="payamito-scheduler">
                <input type="hidden" name="tab"  value="log">
                <?php if (!empty($_GET['status_filter'])) : ?>
                    <input type="hidden" name="status_filter" value="<?php echo esc_attr($_GET['status_filter']); ?>">
                <?php endif; ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    private function render_stats_tab(): void {
        $stats  = Payamito_Logger::get_stats();
        $daily  = $stats['daily'];
        $max    = max(1, max(array_map(fn($d) => $d['sent'] + $d['failed'], $daily)));
        $rate   = $stats['success_rate'];
        $rate_color = $rate >= 80 ? '#2ea44f' : ($rate >= 50 ? '#e36209' : '#cf222e');
        ?>
        <div style="margin-top:24px;">

            <?php /* ── کارت‌های خلاصه ── */ ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;">
                <?php
                $cards = [
                    ['label' => 'کل ارسال‌ها',   'value' => number_format($stats['total']),              'color' => '#0969da'],
                    ['label' => 'موفق',            'value' => number_format($stats['by_status']['sent']),  'color' => '#2ea44f'],
                    ['label' => 'ناموفق',          'value' => number_format($stats['by_status']['failed']),'color' => '#cf222e'],
                    ['label' => 'نرخ موفقیت',      'value' => $rate . '%',                                'color' => $rate_color],
                ];
                foreach ($cards as $card) : ?>
                    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 28px;min-width:140px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:<?php echo $card['color']; ?>">
                            <?php echo esc_html($card['value']); ?>
                        </div>
                        <div style="color:#666;margin-top:4px;font-size:13px;"><?php echo esc_html($card['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php /* ── نمودار ۳۰ روز اخیر ── */ ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:28px;">
                <h3 style="margin:0 0 16px;">ارسال‌های ۳۰ روز اخیر</h3>
                <div style="display:flex;align-items:flex-end;height:120px;gap:3px;border-bottom:1px solid #eee;padding-bottom:4px;">
                    <?php foreach ($daily as $d) :
                        $h_sent   = $max > 0 ? round($d['sent']   / $max * 110) : 0;
                        $h_failed = $max > 0 ? round($d['failed'] / $max * 110) : 0;
                        $title    = esc_attr($d['day'] . " | موفق: {$d['sent']} | ناموفق: {$d['failed']}");
                    ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:1px;" title="<?php echo $title; ?>">
                            <?php if ($h_failed > 0) : ?>
                                <div style="width:100%;height:<?php echo $h_failed; ?>px;background:#cf222e;border-radius:2px 2px 0 0;"></div>
                            <?php endif; ?>
                            <?php if ($h_sent > 0) : ?>
                                <div style="width:100%;height:<?php echo $h_sent; ?>px;background:#2ea44f;border-radius:2px 2px 0 0;"></div>
                            <?php endif; ?>
                            <?php if ($h_sent === 0 && $h_failed === 0) : ?>
                                <div style="width:100%;height:2px;background:#eee;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#999;margin-top:4px;">
                    <span><?php echo esc_html($daily[0]['day']); ?></span>
                    <span style="display:flex;gap:12px;">
                        <span><span style="color:#2ea44f">■</span> موفق</span>
                        <span><span style="color:#cf222e">■</span> ناموفق</span>
                    </span>
                    <span><?php echo esc_html($daily[29]['day']); ?></span>
                </div>
            </div>

            <?php /* ── پرکاربردترین پترن‌ها ── */ ?>
            <?php if (!empty($stats['top_patterns'])) : ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 16px;">پرکاربردترین پترن‌ها</h3>
                <table class="widefat striped" style="width:auto;min-width:400px;">
                    <thead>
                        <tr>
                            <th>کد پترن</th>
                            <th>کل ارسال</th>
                            <th>موفق</th>
                            <th>نرخ موفقیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['top_patterns'] as $p) :
                            $p_rate = $p['total'] > 0 ? round($p['sent_count'] / $p['total'] * 100) : 0;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html($p['pattern']); ?></code></td>
                                <td><?php echo (int) $p['total']; ?></td>
                                <td><?php echo (int) $p['sent_count']; ?></td>
                                <td><?php echo $p_rate; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <p style="color:#999;font-size:12px;margin-top:12px;">
                آمار هر یک ساعت یک‌بار به‌روز می‌شود.
            </p>
        </div>
        <?php
    }

    private function render_credentials_section(array $credentials): void {
        ?>
        <div class="card" style="max-width:100%;margin-top:20px;padding:20px;">
            <h2>🔐 تنظیمات پنل پیامک</h2>
            <form method="post" action="">
                <?php wp_nonce_field('payamito_save_credentials', 'payamito_credentials_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>نام کاربری:</label></th>
                        <td><input type="text" name="credentials[username]" class="regular-text" value="<?php echo esc_attr($credentials['username']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>رمز عبور / توکن:</label></th>
                        <td><input type="password" name="credentials[password]" class="regular-text" autocomplete="current-password" value="<?php echo esc_attr($credentials['password']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>نگهداری لاگ (روز):</label></th>
                        <td>
                            <input type="number" name="credentials[log_retention_days]" min="1" style="width:80px;"
                                   value="<?php echo esc_attr($credentials['log_retention_days'] ?? 90); ?>">
                            <p class="description">لاگ‌های قدیمی‌تر از این تعداد روز به صورت خودکار حذف می‌شوند.</p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="save_credentials" class="button button-primary">ذخیره اطلاعات</button>
            </form>
        </div>
        <hr>
        <?php
    }

    private function render_test_section(): void {
        ?>
        <div class="card" style="max-width:100%;margin-top:20px;padding:20px;">
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
                                مثال: <code>{"name":"reza"}</code> یا <code>["reza", "123"]</code>
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="submit_test_sms" class="button button-primary">ارسال پیامک تست</button>
            </form>
        </div>
        <hr>
        <?php
    }

    private function render_rules_section(array $rules, array $statuses): void {
        ?>
        <div class="card" style="max-width:100%;margin-top:20px;padding:20px;">
            <h2>📅 قوانین زمان‌بندی خودکار</h2>
            <p class="description">مشخص کنید چند وقت پس از تغییر وضعیت سفارش، چه پیامکی ارسال شود.</p>
            <form method="post" action="">
                <?php wp_nonce_field('payamito_save_rules', 'payamito_rules_nonce'); ?>
                <div id="rules-container">
                    <?php foreach ($rules as $index => $rule) : ?>
                        <?php $this->render_rule_row($index, $rule, $statuses); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="add-rule" class="button button-secondary">➕ افزودن قانون جدید</button>
                <br><br>
                <button type="submit" name="save_rules" class="button button-primary button-hero">ذخیره تنظیمات</button>
            </form>
        </div>
        <?php
    }

    private function render_rule_row(int $index, array $rule, array $statuses): void {
        ?>
        <div class="rule-row" style="border:1px solid #ccc;padding:15px;margin-bottom:10px;background:#fff;">
            <strong>اگر سفارش:</strong>
            <select name="rules[<?php echo $index; ?>][status]">
                <?php foreach ($statuses as $slug => $label) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($rule['status'] ?? '', $slug); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <strong>شد، بعد از:</strong>
            <input type="number" name="rules[<?php echo $index; ?>][delay_val]" value="<?php echo esc_attr($rule['delay_val'] ?? 0); ?>" min="0" style="width:60px;">
            <select name="rules[<?php echo $index; ?>][delay_unit]">
                <option value="minutes" <?php selected($rule['delay_unit'] ?? '', 'minutes'); ?>>دقیقه</option>
                <option value="hours"   <?php selected($rule['delay_unit'] ?? '', 'hours'); ?>>ساعت</option>
                <option value="days"    <?php selected($rule['delay_unit'] ?? '', 'days'); ?>>روز</option>
            </select>
            <hr style="margin:10px 0;border:0;border-top:1px solid #eee;">
            <strong>کد پترن:</strong>
            <input type="text" name="rules[<?php echo $index; ?>][pattern]" value="<?php echo esc_attr($rule['pattern'] ?? ''); ?>" placeholder="کد پترن" style="width:100px;">
            <div style="margin-top:10px;">
                <strong>مقادیر متغیرها (به ترتیب):</strong><br>
                <textarea name="rules[<?php echo $index; ?>][vars]" style="width:100%;height:50px;" placeholder="name:{billing_first_name};order:{order_id}"><?php echo esc_textarea($rule['vars'] ?? ''); ?></textarea>
                <p class="description">
                    فرمت: <code>key:value;key2:value2</code> —
                    شورت‌کدها: <code>{billing_first_name}</code>, <code>{billing_last_name}</code>,
                    <code>{order_id}</code>, <code>{order_total}</code>, <code>{billing_phone}</code>
                </p>
            </div>
            <button type="button" class="button remove-row" style="color:#a00;border-color:#a00;margin-top:5px;">حذف این قانون</button>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form Handling
    // -------------------------------------------------------------------------

    public function handle_submission(): void {
        if (!current_user_can('manage_options')) return;

        $this->handle_credentials();
        $this->handle_test_sms();
        $this->handle_save_rules();
    }

    private function handle_credentials(): void {
        if (!isset($_POST['save_credentials'])) return;
        check_admin_referer('payamito_save_credentials', 'payamito_credentials_nonce');

        $raw = $_POST['credentials'] ?? [];
        update_option('payamito_credentials', [
            'username'           => sanitize_text_field($raw['username'] ?? ''),
            'password'           => sanitize_text_field($raw['password'] ?? ''),
            'log_retention_days' => max(1, intval($raw['log_retention_days'] ?? 90)),
        ]);
        add_settings_error('payamito_msg', 'payamito_msg', 'اطلاعات پنل با موفقیت ذخیره شد.', 'success');
    }

    private function handle_test_sms(): void {
        if (!isset($_POST['submit_test_sms'])) return;
        check_admin_referer('payamito_test_sms', 'payamito_test_nonce');

        $mobile  = sanitize_text_field($_POST['test_mobile'] ?? '');
        $pattern = sanitize_text_field($_POST['test_pattern'] ?? '');
        $decoded = json_decode(stripslashes($_POST['test_args'] ?? ''), true);
        $args    = is_array($decoded) ? $decoded : [];

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        );
        $result = $api->send_pattern_sms($mobile, $pattern, $args);

        if ($result !== null) {
            $msg = is_array($result) || is_object($result) ? print_r($result, true) : (string) $result;
            add_settings_error('payamito_msg', 'payamito_msg', 'درخواست ارسال شد. خروجی: ' . esc_html($msg), 'success');
        } else {
            add_settings_error('payamito_msg', 'payamito_msg', 'خطا در ارسال. لطفاً اطلاعات پنل و کد پترن را بررسی کنید.', 'error');
        }
    }

    private function handle_save_rules(): void {
        if (!isset($_POST['save_rules'])) return;
        check_admin_referer('payamito_save_rules', 'payamito_rules_nonce');

        $rules = array_values(array_filter(
            array_map([$this, 'sanitize_rule'], $_POST['rules'] ?? []),
            fn($r) => !empty($r['pattern'])
        ));

        update_option('payamito_schedule_rules', $rules);
        add_settings_error('payamito_msg', 'payamito_msg', 'قوانین با موفقیت ذخیره شدند.', 'success');
    }

    private function sanitize_rule(array $r): array {
        return [
            'status'     => sanitize_text_field($r['status']     ?? ''),
            'delay_val'  => max(0, (int) ($r['delay_val']        ?? 0)),
            'delay_unit' => sanitize_text_field($r['delay_unit'] ?? 'minutes'),
            'pattern'    => sanitize_text_field($r['pattern']    ?? ''),
            'vars'       => sanitize_textarea_field($r['vars']   ?? ''),
        ];
    }
}
