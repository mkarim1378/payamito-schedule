<?php
if (!defined('ABSPATH')) exit;

class Payamito_Admin {

    private array $page_hooks = [];

    public function __construct() {
        add_action('admin_menu',                        [$this, 'add_menu']);
        add_action('admin_init',                        [$this, 'handle_submission']);
        add_action('admin_notices',                     [$this, 'test_mode_notice']);
        add_action('admin_enqueue_scripts',             [$this, 'enqueue_scripts']);
        add_action('add_meta_boxes',                    [$this, 'register_meta_box']);
        add_action('admin_post_payamito_resend_sms',      [$this, 'handle_resend']);
        add_action('admin_post_payamito_cancel_sms',      [$this, 'handle_cancel_sms']);
        add_action('admin_post_payamito_send_now',        [$this, 'handle_send_now']);
        add_action('admin_post_payamito_cancel_all_sms',  [$this, 'handle_cancel_all_sms']);
    }

    // -------------------------------------------------------------------------
    // Menu & Scripts
    // -------------------------------------------------------------------------

    public function register_meta_box(): void {
        $screen = function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'payamito_sms_status',
            'پیامک‌های پیامیتو',
            [$this, 'render_order_meta_box'],
            $screen,
            'side',
            'default'
        );
    }

    public function render_order_meta_box($post_or_order): void {
        $order_id = $post_or_order instanceof WC_Abstract_Order
            ? $post_or_order->get_id()
            : (int) $post_or_order->ID;

        $order     = $post_or_order instanceof WC_Abstract_Order ? $post_or_order : wc_get_order($order_id);
        $scheduled = $this->get_pending_actions_for_order($order_id);
        $entries   = Payamito_Logger::get_by_order($order_id);

        if (empty($scheduled) && empty($entries)) {
            echo '<p style="color:#999;font-size:12px;">هیچ پیامکی برای این سفارش ثبت یا برنامه‌ریزی نشده.</p>';
            return;
        }

        // ── پیامک‌های در صف ──────────────────────────────────────────
        if (!empty($scheduled)) :
            echo '<p style="font-weight:600;margin:0 0 6px;font-size:12px;color:#555;">⏳ برنامه‌ریزی شده:</p>';
            if (count($scheduled) > 1) :
                $cancel_all_url = wp_nonce_url(
                    admin_url('admin-post.php?action=payamito_cancel_all_sms&order_id=' . (int) $order_id),
                    'payamito_cancel_all_sms',
                    'payamito_cancel_all_nonce'
                ); ?>
                <a href="<?php echo esc_url($cancel_all_url); ?>"
                   class="button button-small"
                   style="color:#a00;border-color:#a00;width:100%;display:block;text-align:center;box-sizing:border-box;margin-bottom:8px;"
                   onclick="return confirm('همه پیامک‌های زمان‌بندی‌شده لغو شوند؟')">⊘ لغو همه پیامک‌ها (<?php echo count($scheduled); ?>)</a>
            <?php endif;
            foreach ($scheduled as $action_id => $action) :
                $args           = $action->get_args();
                $trigger_status = $args['trigger_status'] ?? '';

                // اگه وضعیت سفارش از زمان schedule شدن عوض شده (مثلاً افزونه غیرفعال بوده)،
                // action رو همین‌جا cancel کن و نشانش نده — در تاریخچه ثبت می‌شه
                if ($trigger_status !== '' && $order instanceof WC_Abstract_Order && $order->get_status() !== $trigger_status) {
                    $phone     = $order->get_billing_phone();
                    $st        = $args['send_type'] ?? 'pattern';
                    try { $phone = Payamito_Scheduler::normalize_phone($phone); } catch (\InvalidArgumentException $e) {}
                    Payamito_Logger::insert([
                        'order_id'     => $order_id,
                        'mobile'       => $phone,
                        'pattern'      => $st === 'text' ? 'text' : ($args['pattern_code'] ?? ''),
                        'vars'         => $st === 'text' ? ($args['text_body'] ?? '') : ($args['vars_str'] ?? ''),
                        'status'       => 'cancelled',
                        'response'     => 'وضعیت سفارش در زمان غیرفعال بودن افزونه تغییر کرد — پیامک لغو شد',
                        'attempt'      => (int) ($args['attempt'] ?? 1),
                        'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
                    ]);
                    try { ActionScheduler_Store::instance()->cancel_action($action_id); } catch (\Throwable $e) {}
                    continue;
                }

                $send_type = $args['send_type'] ?? 'pattern';
                $is_text   = $send_type === 'text';
                $label     = $is_text ? 'متن ثابت' : ('پترن ' . ($args['pattern_code'] ?? ''));
                $attempt   = (int) ($args['attempt'] ?? 1);
                $date      = $action->get_schedule()->get_date();
                $time_str  = $date ? self::jalali_from_utc($date) : '—';
                $raw_text     = $is_text ? ($args['text_body'] ?? '') : '';
                $display_text = ($raw_text && $order instanceof WC_Abstract_Order)
                    ? str_replace(
                        array_keys(Payamito_Scheduler::build_placeholders($order)),
                        array_values(Payamito_Scheduler::build_placeholders($order)),
                        $raw_text
                    )
                    : $raw_text;
                ?>
                <div style="border:1px solid #b3d9ff;border-radius:4px;padding:8px;margin-bottom:8px;font-size:12px;background:#f0f7ff;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <strong><?php echo esc_html($label); ?></strong>
                        <span style="color:#0969da">⏱ در صف</span>
                    </div>
                    <?php if ($display_text) : ?>
                        <div style="color:#333;margin-bottom:4px;line-height:1.5;white-space:pre-wrap;"><?php echo esc_html($display_text); ?></div>
                    <?php endif; ?>
                    <div style="color:#555;">🕐 ارسال در: <?php echo esc_html($time_str); ?></div>
                    <?php if ($attempt > 1) : ?>
                        <div style="color:#999;margin-top:2px;">تلاش مجدد #<?php echo $attempt; ?></div>
                    <?php endif; ?>
                    <?php
                    $send_now_url = wp_nonce_url(
                        admin_url('admin-post.php?' . http_build_query([
                            'action'              => 'payamito_send_now',
                            'action_id'           => (int) $action_id,
                            'order_id'            => (int) ($args['order_id'] ?? 0),
                            'pattern_code'        => $args['pattern_code']        ?? '',
                            'vars_str'            => $args['vars_str']            ?? '',
                            'send_type'           => $args['send_type']           ?? 'pattern',
                            'text_body'           => $args['text_body']           ?? '',
                            'coupon_enabled'      => (int)   ($args['coupon_enabled']      ?? 0),
                            'coupon_amount'       => (float) ($args['coupon_amount']       ?? 0),
                            'coupon_type'         => $args['coupon_type']         ?? 'percent',
                            'coupon_expiry_hours' => (int)   ($args['coupon_expiry_hours'] ?? 24),
                            'coupon_mode'         => $args['coupon_mode']         ?? 'code',
                        ])),
                        'payamito_send_now',
                        'payamito_send_now_nonce'
                    );
                    $cancel_url = wp_nonce_url(
                        admin_url('admin-post.php?action=payamito_cancel_sms&action_id=' . (int) $action_id),
                        'payamito_cancel_sms',
                        'payamito_cancel_nonce'
                    );
                    ?>
                    <div style="display:flex;gap:6px;margin-top:6px;">
                        <a href="<?php echo esc_url($send_now_url); ?>"
                           class="button button-small button-primary"
                           onclick="return confirm('پیامک همین الان ارسال شود؟')">⚡ ارسال فوری</a>
                        <a href="<?php echo esc_url($cancel_url); ?>"
                           class="button button-small"
                           style="color:#a00;border-color:#a00;"
                           onclick="return confirm('آیا از لغو این پیامک مطمئن هستید؟')">⊘ لغو</a>
                    </div>
                </div>
            <?php endforeach;
        endif;

        // ── تاریخچه ارسال ────────────────────────────────────────────
        if (!empty($entries)) :
            if (!empty($scheduled)) {
                echo '<p style="font-weight:600;margin:8px 0 6px;font-size:12px;color:#555;">📋 تاریخچه:</p>';
            }

            $status_map = [
                'sent'       => '<span style="color:#2ea44f">✓ ارسال‌شده</span>',
                'failed'     => '<span style="color:#cf222e">✗ ناموفق</span>',
                'cancelled'  => '<span style="color:#999">⊘ لغوشده</span>',
                'superseded' => '<span style="color:#6e40c9">🔄 جایگزین شد</span>',
            ];
            $bg_map = [
                'sent'       => 'background:#f0fff4;border-color:#b7f0c8;',
                'failed'     => 'background:#fff5f5;border-color:#ffc1c1;',
                'cancelled'  => 'background:#fafafa;border-color:#ddd;',
                'superseded' => 'background:#f5f0ff;border-color:#c9b8f0;',
            ];

            foreach ($entries as $entry) :
                $masked   = strlen($entry['mobile']) > 6
                    ? substr($entry['mobile'], 0, 4) . '****' . substr($entry['mobile'], -3)
                    : $entry['mobile'];
                $is_text  = $entry['pattern'] === 'text';
                $bg_style = $bg_map[$entry['status']] ?? 'background:#fff;border-color:#ddd;';
                ?>
                <div style="border:1px solid;border-radius:4px;padding:8px;margin-bottom:8px;font-size:12px;<?php echo $bg_style; ?>">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <strong><?php echo $is_text ? 'متن ثابت' : ('پترن: ' . esc_html($entry['pattern'])); ?></strong>
                        <?php echo $status_map[$entry['status']] ?? esc_html($entry['status']); ?>
                    </div>
                    <?php if ($is_text && !empty($entry['vars'])) :
                        $display_entry_text = ($order instanceof WC_Abstract_Order)
                            ? str_replace(
                                array_keys(Payamito_Scheduler::build_placeholders($order)),
                                array_values(Payamito_Scheduler::build_placeholders($order)),
                                $entry['vars']
                            )
                            : $entry['vars'];
                    ?>
                        <div style="color:#333;margin-bottom:4px;line-height:1.5;white-space:pre-wrap;"><?php echo esc_html($display_entry_text); ?></div>
                    <?php endif; ?>
                    <div style="color:#666;">📱 <?php echo esc_html($masked); ?></div>
                    <div style="color:#666;">🕐 <?php echo esc_html(self::jalali($entry['scheduled_at'])); ?></div>
                    <?php if ($entry['sent_at']) : ?>
                        <div style="color:#2ea44f;">✅ <?php echo esc_html(self::jalali($entry['sent_at'])); ?></div>
                    <?php endif; ?>
                    <?php if ($entry['status'] === 'superseded' && !empty($entry['response'])) : ?>
                        <div style="color:#6e40c9;margin-top:2px;font-size:11px;">وضعیت جدید سفارش: <?php echo esc_html($entry['response']); ?></div>
                    <?php endif; ?>
                    <?php if ($entry['status'] === 'failed') :
                        $resend_url = wp_nonce_url(
                            admin_url('admin-post.php?action=payamito_resend_sms&log_id=' . (int) $entry['id']),
                            'payamito_resend_sms',
                            'payamito_resend_nonce'
                        ); ?>
                        <a href="<?php echo esc_url($resend_url); ?>"
                           class="button button-small"
                           style="margin-top:6px;display:inline-block;">🔄 ارسال مجدد</a>
                    <?php endif; ?>
                </div>
            <?php endforeach;
        endif;
    }

    private static function jalali(string $mysql_dt, bool $with_time = true): string {
        $tz = wp_timezone();
        $dt = new \DateTime($mysql_dt, $tz);
        [$gy, $gm, $gd] = [(int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j')];

        $g  = $gy - 1600;
        $gm = $gm - 1;
        $gd = $gd - 1;
        $g_d_no = 365 * $g + (int)(($g + 3) / 4) - (int)(($g + 99) / 100) + (int)(($g + 399) / 400);
        $gm_d   = [31,28,31,30,31,30,31,31,30,31,30,31];
        for ($i = 0; $i < $gm; $i++) $g_d_no += $gm_d[$i];
        if ($gm > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) $g_d_no++;
        $g_d_no += $gd;

        $j_d_no = $g_d_no - 79;
        $j_np   = (int) ($j_d_no / 12053);
        $j_d_no %= 12053;
        $jy     = 979 + 33 * $j_np + 4 * (int) ($j_d_no / 1461);
        $j_d_no %= 1461;
        if ($j_d_no >= 366) {
            $jy    += (int) (($j_d_no - 1) / 365);
            $j_d_no = ($j_d_no - 1) % 365;
        }
        $jm_d = [31,31,31,31,31,31,30,30,30,30,30,29];
        $jm   = 0;
        for (; $jm < 11 && $j_d_no >= $jm_d[$jm]; $jm++) $j_d_no -= $jm_d[$jm];
        $jm_names = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        $result = ($j_d_no + 1) . ' ' . $jm_names[$jm] . ' ' . $jy;
        if ($with_time) $result .= ' ساعت ' . $dt->format('H:i');
        return $result;
    }

    private static function jalali_from_utc(\DateTimeInterface $utc_dt, bool $with_time = true): string {
        $dt = \DateTime::createFromInterface($utc_dt);
        $dt->setTimezone(wp_timezone());
        return self::jalali($dt->format('Y-m-d H:i:s'), $with_time);
    }

    private function get_pending_actions_for_order(int $order_id): array {
        if (!function_exists('as_get_scheduled_actions')) return [];

        $actions = as_get_scheduled_actions([
            'hook'     => 'payamito_execute_scheduled_sms',
            'status'   => 'pending',
            'group'    => 'payamito-sms',
            'per_page' => 100,
        ]);

        return array_filter(
            $actions,
            fn($action) => (int) ($action->get_args()['order_id'] ?? 0) === $order_id
        );
    }

    public function handle_cancel_sms(): void {
        check_admin_referer('payamito_cancel_sms', 'payamito_cancel_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

        $action_id = (int) ($_REQUEST['action_id'] ?? 0);
        $back      = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');

        if ($action_id) {
            try {
                $store  = ActionScheduler_Store::instance();
                $action = $store->fetch_action($action_id);
                if ($action) {
                    $args      = $action->get_args();
                    $order_id  = (int) ($args['order_id'] ?? 0);
                    $order     = $order_id ? wc_get_order($order_id) : null;
                    $mobile    = '';
                    if ($order instanceof WC_Abstract_Order) {
                        try {
                            $mobile = Payamito_Scheduler::normalize_phone($order->get_billing_phone());
                        } catch (\InvalidArgumentException $e) {
                            $mobile = $order->get_billing_phone();
                        }
                    }
                    $send_type = $args['send_type'] ?? 'pattern';
                    Payamito_Logger::insert([
                        'order_id'     => $order_id,
                        'mobile'       => $mobile,
                        'pattern'      => $send_type === 'text' ? 'text' : ($args['pattern_code'] ?? ''),
                        'vars'         => $send_type === 'text' ? ($args['text_body'] ?? '') : ($args['vars_str'] ?? ''),
                        'status'       => 'cancelled',
                        'response'     => null,
                        'attempt'      => (int) ($args['attempt'] ?? 1),
                        'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
                        'sent_at'      => null,
                    ]);
                }
                $store->cancel_action($action_id);
            } catch (\Throwable $e) {
                error_log('[Payamito] Cancel action failed: ' . $e->getMessage());
            }
        }

        wp_safe_redirect($back);
        exit;
    }

    public function handle_cancel_all_sms(): void {
        check_admin_referer('payamito_cancel_all_sms', 'payamito_cancel_all_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

        $order_id = (int) ($_REQUEST['order_id'] ?? 0);
        $back     = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');

        if (!$order_id || !function_exists('as_get_scheduled_actions')) {
            wp_safe_redirect($back);
            exit;
        }

        $order   = wc_get_order($order_id);
        $mobile  = '';
        if ($order instanceof WC_Abstract_Order) {
            try {
                $mobile = Payamito_Scheduler::normalize_phone($order->get_billing_phone());
            } catch (\InvalidArgumentException $e) {
                $mobile = $order->get_billing_phone();
            }
        }

        $actions = as_get_scheduled_actions([
            'hook'     => 'payamito_execute_scheduled_sms',
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'group'    => 'payamito-sms',
            'per_page' => 100,
        ]);

        $store = ActionScheduler_Store::instance();
        foreach ($actions as $action_id => $action) {
            $args = $action->get_args();
            if ((int) ($args['order_id'] ?? 0) !== $order_id) continue;

            $send_type = $args['send_type'] ?? 'pattern';
            Payamito_Logger::insert([
                'order_id'     => $order_id,
                'mobile'       => $mobile,
                'pattern'      => $send_type === 'text' ? 'text' : ($args['pattern_code'] ?? ''),
                'vars'         => $send_type === 'text' ? ($args['text_body'] ?? '') : ($args['vars_str'] ?? ''),
                'status'       => 'cancelled',
                'response'     => null,
                'attempt'      => (int) ($args['attempt'] ?? 1),
                'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
                'sent_at'      => null,
            ]);

            try {
                $store->cancel_action($action_id);
            } catch (\Throwable $e) {
                error_log('[Payamito] Cancel all - failed for action ' . $action_id . ': ' . $e->getMessage());
            }
        }

        wp_safe_redirect($back);
        exit;
    }

    public function handle_send_now(): void {
        check_admin_referer('payamito_send_now', 'payamito_send_now_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

        $back                = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
        $action_id           = (int)    ($_REQUEST['action_id']           ?? 0);
        $order_id            = (int)    ($_REQUEST['order_id']            ?? 0);
        $send_type           = sanitize_text_field($_REQUEST['send_type']           ?? 'pattern');
        $text_body           = sanitize_textarea_field($_REQUEST['text_body']       ?? '');
        $pat_code            = sanitize_text_field($_REQUEST['pattern_code']        ?? '');
        $vars_str            = sanitize_textarea_field($_REQUEST['vars_str']        ?? '');
        $coupon_enabled      = (int)    ($_REQUEST['coupon_enabled']      ?? 0);
        $coupon_amount       = max(0, (float) ($_REQUEST['coupon_amount'] ?? 0));
        $coupon_type         = in_array($_REQUEST['coupon_type'] ?? '', ['percent', 'fixed'], true) ? $_REQUEST['coupon_type'] : 'percent';
        $coupon_expiry_hours = max(1, (int) ($_REQUEST['coupon_expiry_hours'] ?? 24));
        $coupon_mode         = in_array($_REQUEST['coupon_mode'] ?? '', ['code', 'payment'], true) ? $_REQUEST['coupon_mode'] : 'code';

        if (!$order_id) {
            wp_safe_redirect($back);
            exit;
        }

        if ($action_id) {
            try {
                ActionScheduler_Store::instance()->cancel_action($action_id);
            } catch (\Throwable $e) {
                error_log('[Payamito] Cancel in send_now failed: ' . $e->getMessage());
            }
        }

        // Pass MAX_ATTEMPTS as the attempt count so execute() skips retry scheduling
        // for instant sends — a failed immediate send should not re-queue automatically.
        do_action('payamito_execute_scheduled_sms', $order_id, $pat_code, $vars_str, null, Payamito_Scheduler::MAX_ATTEMPTS, $send_type, $text_body, $coupon_enabled, $coupon_amount, $coupon_type, $coupon_expiry_hours, $coupon_mode);

        wp_safe_redirect($back);
        exit;
    }

    public function handle_resend(): void {
        check_admin_referer('payamito_resend_sms', 'payamito_resend_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

        $log_id = (int) ($_REQUEST['log_id'] ?? 0);
        $entry  = Payamito_Logger::get_by_id($log_id);
        $back   = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');

        if (!$entry) {
            wp_safe_redirect($back);
            exit;
        }

        $order = wc_get_order((int) $entry['order_id']);
        if (!$order instanceof WC_Abstract_Order) {
            wp_safe_redirect($back);
            exit;
        }

        // resolve vars against current order data
        $placeholders = Payamito_Scheduler::build_placeholders($order);
        $sms_args = [];
        foreach (explode(';', $entry['vars']) as $pair) {
            [$k, $v] = array_pad(explode(':', trim($pair), 2), 2, '');
            if (trim($k) !== '') {
                $sms_args[trim($k)] = str_replace(
                    array_keys($placeholders),
                    array_values($placeholders),
                    trim($v)
                );
            }
        }

        try {
            $mobile = Payamito_Scheduler::normalize_phone($entry['mobile']);
        } catch (\InvalidArgumentException $e) {
            wp_safe_redirect($back);
            exit;
        }

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api($credentials['username'] ?? '', $credentials['password'] ?? '');
        $now         = current_time('mysql');
        $is_text     = $entry['pattern'] === 'text';

        if ($is_text) {
            $resolved_text = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $entry['vars']
            );
            $from          = $credentials['from_number'] ?? '';
            $result        = $api->send_smart_sms($mobile, $resolved_text, $from);
        } else {
            $result = $api->send_pattern_sms($mobile, $entry['pattern'], $sms_args);
        }

        $success = $result !== null;

        Payamito_Logger::update_status(
            $log_id,
            $success ? 'sent' : 'failed',
            $success ? (is_array($result) ? wp_json_encode($result) : (is_object($result) ? print_r($result, true) : (string) $result)) : null,
            $success ? $now : null
        );

        $masked = strlen($mobile) > 6
            ? substr($mobile, 0, 4) . '****' . substr($mobile, -3)
            : $mobile;
        $label  = $is_text ? 'متن ثابت' : ('پترن ' . $entry['pattern']);
        $order->add_order_note(
            $success
                ? sprintf('[پیامیتو] ارسال مجدد %s به %s موفق بود.', $label, $masked)
                : sprintf('[پیامیتو] ارسال مجدد %s به %s ناموفق بود.', $label, $masked),
            false, false
        );

        wp_safe_redirect($back);
        exit;
    }

    public function test_mode_notice(): void {
        $credentials = get_option('payamito_credentials', []);
        if (empty($credentials['test_mode'])) return;

        $phones = array_filter(array_map('trim', explode("\n", $credentials['test_phones'] ?? '')));
        $list   = !empty($phones) ? implode('، ', $phones) : '(بدون شماره تست — همه پیامک‌ها ارسال می‌شوند)';
        ?>
        <div class="notice notice-warning" style="border-right:4px solid #b45309;">
            <p><strong>⚠️ حالت تست پیامیتو فعال است</strong> — پیامک‌ها به جای مشتریان، به این شماره‌ها ارسال می‌شوند: <code><?php echo esc_html($list); ?></code>
            &nbsp;|&nbsp; <a href="<?php echo esc_url(admin_url('admin.php?page=payamito-scheduler')); ?>">غیرفعال کردن</a></p>
        </div>
        <?php
    }

    public function add_menu(): void {
        $this->page_hooks[] = add_menu_page(
            'زمان‌بندی پیامک',
            'زمان‌بندی پیامک',
            'manage_options',
            'payamito-scheduler',
            [$this, 'render_settings_page'],
            'dashicons-clock',
            50
        );

        add_submenu_page(
            'payamito-scheduler',
            'تنظیمات — زمان‌بندی پیامک',
            '⚙️ تنظیمات',
            'manage_options',
            'payamito-scheduler',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'payamito-scheduler',
            'تاریخچه ارسال — زمان‌بندی پیامک',
            '📋 تاریخچه ارسال',
            'manage_options',
            'payamito-scheduler-log',
            [$this, 'render_log_page']
        );

        add_submenu_page(
            'payamito-scheduler',
            'آمار — زمان‌بندی پیامک',
            '📊 آمار',
            'manage_options',
            'payamito-scheduler-stats',
            [$this, 'render_stats_page']
        );
    }

    public function enqueue_scripts(string $hook): void {
        if (!in_array($hook, $this->page_hooks, true)) return;

        wp_enqueue_script(
            'payamito-admin',
            PAYAMITO_SCHEDULE_URL . 'assets/js/admin.js',
            ['jquery', 'selectWoo'],
            PAYAMITO_SCHEDULE_VERSION,
            true
        );

        wp_localize_script('payamito-admin', 'payamitoData', [
            'statuses'            => wc_get_order_statuses(),
            'confirmDelete'       => 'آیا مطمئن هستید؟',
            'ajaxurl'             => admin_url('admin-ajax.php'),
            'searchProductsNonce' => wp_create_nonce('search-products'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Page Rendering
    // -------------------------------------------------------------------------

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;

        settings_errors('payamito_msg');

        $rules       = get_option('payamito_schedule_rules', []);
        $credentials = get_option('payamito_credentials', ['username' => '', 'password' => '', 'from_number' => '', 'prevent_cancellation' => 0, 'log_retention_days' => 90]);
        $statuses    = wc_get_order_statuses();
        ?>
        <div class="wrap">
            <h1>زمان‌بندی پیامک (پیامیتو)</h1>
            <?php
            $this->render_credentials_section($credentials);
            $this->render_test_section();
            $this->render_rules_section($rules, $statuses);
            ?>
        </div>
        <?php
    }

    public function render_log_page(): void {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>تاریخچه ارسال پیامک</h1>
            <?php $this->render_log_tab(); ?>
        </div>
        <?php
    }

    public function render_stats_page(): void {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>آمار ارسال پیامک</h1>
            <?php $this->render_stats_tab(); ?>
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
                <input type="hidden" name="page" value="payamito-scheduler-log">
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
                    ['label' => 'کل ارسال‌ها',   'value' => number_format($stats['total']),                       'color' => '#0969da'],
                    ['label' => 'موفق',            'value' => number_format($stats['by_status']['sent']),           'color' => '#2ea44f'],
                    ['label' => 'ناموفق',          'value' => number_format($stats['by_status']['failed']),         'color' => '#cf222e'],
                    ['label' => 'جایگزین‌شده',    'value' => number_format($stats['by_status']['superseded']),     'color' => '#6e40c9'],
                    ['label' => 'نرخ موفقیت',      'value' => $rate . '%',                                         'color' => $rate_color],
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
                        <th><label>شماره فرستنده (خط اختصاصی):</label></th>
                        <td>
                            <input type="text" name="credentials[from_number]" class="regular-text" placeholder="مثال: 10008000" value="<?php echo esc_attr($credentials['from_number'] ?? ''); ?>">
                            <p class="description">برای ارسال پیامک با متن ثابت (SmartSMS) استفاده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>جلوگیری از لغو سفارش:</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="credentials[prevent_cancellation]" value="1" <?php checked(!empty($credentials['prevent_cancellation'])); ?>>
                                هیچ سفارشی به وضعیت «لغو شده» نرود و به «در انتظار پرداخت» برگردد
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>نگهداری لاگ (روز):</label></th>
                        <td>
                            <input type="number" name="credentials[log_retention_days]" min="1" style="width:80px;"
                                   value="<?php echo esc_attr($credentials['log_retention_days'] ?? 90); ?>">
                            <p class="description">لاگ‌های قدیمی‌تر از این تعداد روز به صورت خودکار حذف می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>حالت تست:</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="credentials[test_mode]" value="1" <?php checked(!empty($credentials['test_mode'])); ?>>
                                فعال — پیامک‌ها فقط به شماره‌های تست ارسال شوند
                            </label>
                            <p class="description" style="color:#b45309;">⚠️ وقتی فعال است، هیچ مشتری‌ای پیامک دریافت نمی‌کند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>شماره‌های تست:</label></th>
                        <td>
                            <textarea name="credentials[test_phones]" rows="3" class="regular-text" placeholder="09120000000&#10;09130000000"><?php echo esc_textarea($credentials['test_phones'] ?? ''); ?></textarea>
                            <p class="description">یک شماره در هر خط. در حالت تست، همه پیامک‌ها به این شماره‌ها redirect می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>حذف داده‌ها:</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="credentials[delete_data_on_uninstall]" value="1" <?php checked(!empty($credentials['delete_data_on_uninstall'])); ?>>
                                هنگام حذف افزونه، تمام داده‌ها پاکسازی شوند
                            </label>
                            <p class="description" style="color:#cf222e;">⚠️ در صورت فعال بودن، با حذف افزونه تمام قوانین، لاگ‌ها، کوپن‌ها و تنظیمات به‌طور دائمی حذف می‌شوند.</p>
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
        $send_type = $rule['send_type'] ?? 'pattern';
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
            <strong>نوع ارسال:</strong>
            <select name="rules[<?php echo $index; ?>][send_type]" class="send-type-select">
                <option value="text"    <?php selected($send_type, 'text'); ?>>متن ثابت (SmartSMS)</option>
                <option value="pattern" <?php selected($send_type, 'pattern'); ?>>پترن (خط خدماتی)</option>
            </select>
            <div class="pattern-fields" style="margin-top:10px;<?php echo $send_type === 'text' ? 'display:none;' : ''; ?>">
                <strong>کد پترن:</strong>
                <input type="text" name="rules[<?php echo $index; ?>][pattern]" value="<?php echo esc_attr($rule['pattern'] ?? ''); ?>" placeholder="کد پترن" style="width:100px;">
                <div style="margin-top:10px;">
                    <strong>مقادیر متغیرها (به ترتیب):</strong><br>
                    <textarea name="rules[<?php echo $index; ?>][vars]" style="width:100%;height:50px;" placeholder="name:{billing_first_name};order:{order_id}"><?php echo esc_textarea($rule['vars'] ?? ''); ?></textarea>
                    <p class="description">
                        فرمت: <code>key:value;key2:value2</code> —
                        شورت‌کدها: <code>{billing_first_name}</code>, <code>{billing_last_name}</code>,
                        <code>{order_id}</code>, <code>{order_total}</code>, <code>{billing_phone}</code>,
                        <code>{product_names}</code>, <code>{product_links}</code>, <code>{payment_link}</code>,
                        <code>{coupon_code}</code>
                    </p>
                </div>
            </div>
            <div class="text-fields" style="margin-top:10px;<?php echo $send_type !== 'text' ? 'display:none;' : ''; ?>">
                <strong>متن پیامک:</strong><br>
                <textarea name="rules[<?php echo $index; ?>][text_body]" style="width:100%;height:160px;" placeholder="سفارش شما #{order_id} ثبت شد. با تشکر، {billing_first_name} عزیز."><?php echo esc_textarea($rule['text_body'] ?? ''); ?></textarea>
                <p class="description">
                    شورت‌کدها: <code>{billing_first_name}</code>, <code>{billing_last_name}</code>,
                    <code>{order_id}</code>, <code>{order_total}</code>, <code>{billing_phone}</code>,
                    <code>{product_names}</code>, <code>{product_links}</code>, <code>{payment_link}</code>,
                    <code>{coupon_code}</code>
                </p>
            </div>
            <div class="coupon-section" style="margin-top:10px;border-top:1px solid #eee;padding-top:10px;">
                <label>
                    <input type="checkbox" name="rules[<?php echo $index; ?>][coupon_enabled]" value="1" class="coupon-toggle"
                        <?php checked(!empty($rule['coupon_enabled'])); ?>>
                    <strong>ارسال کد تخفیف اتوماتیک</strong>
                </label>
                <div class="coupon-fields" style="margin-top:10px;<?php echo empty($rule['coupon_enabled']) ? 'display:none;' : ''; ?>">
                    <table style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="padding:4px 8px 4px 0;width:130px;"><label>مقدار تخفیف:</label></td>
                            <td>
                                <input type="number" name="rules[<?php echo $index; ?>][coupon_amount]" value="<?php echo esc_attr($rule['coupon_amount'] ?? 0); ?>" min="0" step="any" style="width:80px;">
                                <select name="rules[<?php echo $index; ?>][coupon_type]">
                                    <option value="percent" <?php selected($rule['coupon_type'] ?? 'percent', 'percent'); ?>>درصد (%)</option>
                                    <option value="fixed"   <?php selected($rule['coupon_type'] ?? '',        'fixed'); ?>>مبلغ ثابت (تومان)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;"><label>انقضا (ساعت):</label></td>
                            <td><input type="number" name="rules[<?php echo $index; ?>][coupon_expiry_hours]" value="<?php echo esc_attr($rule['coupon_expiry_hours'] ?? 24); ?>" min="1" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 8px 4px 0;vertical-align:top;padding-top:8px;"><label>حالت کد تخفیف:</label></td>
                            <td style="padding-top:8px;">
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="radio" name="rules[<?php echo $index; ?>][coupon_mode]" value="code" <?php checked($rule['coupon_mode'] ?? 'code', 'code'); ?>>
                                    کد در متن پیامک — از شورت‌کد <code>{coupon_code}</code> استفاده کنید
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="rules[<?php echo $index; ?>][coupon_mode]" value="payment" <?php checked($rule['coupon_mode'] ?? '', 'payment'); ?>>
                                    اعمال روی سفارش (لینک پرداخت به‌روزرسانی می‌شود)
                                </label>
                                <p class="description" style="margin-top:4px;">کد: <code>carno{order_id}</code> — یکبار مصرف، فقط برای ایمیل مشتری</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php
            $filter_mode = $rule['product_filter_mode'] ?? 'none';
            $filter_ids  = $rule['product_filter_ids']  ?? [];
            $selected_products = [];
            foreach ($filter_ids as $pid) {
                $product = wc_get_product((int) $pid);
                if ($product) {
                    $selected_products[] = ['id' => (int) $pid, 'name' => $product->get_name()];
                }
            }
            ?>
            <div class="product-filter-section" style="margin-top:10px;border-top:1px solid #eee;padding-top:10px;">
                <strong>فیلتر محصول:</strong>
                <label style="margin-right:12px;">
                    <input type="radio" name="rules[<?php echo $index; ?>][product_filter_mode]" value="none" class="product-filter-mode" <?php checked($filter_mode, 'none'); ?>>
                    بدون فیلتر
                </label>
                <label style="margin-right:12px;">
                    <input type="radio" name="rules[<?php echo $index; ?>][product_filter_mode]" value="blacklist" class="product-filter-mode" <?php checked($filter_mode, 'blacklist'); ?>>
                    بلک لیست — ارسال نشود
                </label>
                <label>
                    <input type="radio" name="rules[<?php echo $index; ?>][product_filter_mode]" value="whitelist" class="product-filter-mode" <?php checked($filter_mode, 'whitelist'); ?>>
                    وایت لیست — فقط ارسال شود
                </label>
                <div class="product-filter-fields" style="margin-top:8px;<?php echo $filter_mode === 'none' ? 'display:none;' : ''; ?>">
                    <select name="rules[<?php echo $index; ?>][product_filter_ids][]" class="product-filter-select" multiple="multiple" style="width:100%;">
                        <?php foreach ($selected_products as $p) : ?>
                            <option value="<?php echo esc_attr($p['id']); ?>" selected="selected"><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:4px;">محصولاتی را انتخاب کنید که این قانون درباره آن‌ها اجرا نشود (بلک لیست) یا فقط برای آن‌ها اجرا شود (وایت لیست).</p>
                </div>
            </div>
            <button type="button" class="button remove-row" style="color:#a00;border-color:#a00;margin-top:10px;">حذف این قانون</button>
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
            'username'              => sanitize_text_field($raw['username']     ?? ''),
            'password'              => sanitize_text_field($raw['password']     ?? ''),
            'from_number'           => sanitize_text_field($raw['from_number']  ?? ''),
            'prevent_cancellation'  => !empty($raw['prevent_cancellation']) ? 1 : 0,
            'log_retention_days'    => max(1, intval($raw['log_retention_days'] ?? 90)),
            'test_mode'                => !empty($raw['test_mode']) ? 1 : 0,
            'test_phones'              => sanitize_textarea_field($raw['test_phones'] ?? ''),
            'delete_data_on_uninstall' => !empty($raw['delete_data_on_uninstall']) ? 1 : 0,
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
            fn($r) => ($r['send_type'] === 'text' && !empty($r['text_body']))
                   || ($r['send_type'] !== 'text' && !empty($r['pattern']))
        ));

        update_option('payamito_schedule_rules', $rules);
        Payamito_Scheduler::cleanup_stale_actions($rules);
        add_settings_error('payamito_msg', 'payamito_msg', 'قوانین با موفقیت ذخیره شدند.', 'success');
    }

    private function sanitize_rule(array $r): array {
        return [
            'status'              => sanitize_text_field($r['status']                          ?? ''),
            'delay_val'           => max(0, (int) ($r['delay_val']                             ?? 0)),
            'delay_unit'          => sanitize_text_field($r['delay_unit']                      ?? 'minutes'),
            'send_type'           => in_array($r['send_type'] ?? '', ['pattern', 'text'], true) ? $r['send_type'] : 'pattern',
            'pattern'             => sanitize_text_field($r['pattern']                         ?? ''),
            'vars'                => sanitize_textarea_field($r['vars']                        ?? ''),
            'text_body'           => sanitize_textarea_field($r['text_body']                   ?? ''),
            'coupon_enabled'      => !empty($r['coupon_enabled']) ? 1 : 0,
            'coupon_amount'       => max(0, (float) ($r['coupon_amount']                       ?? 0)),
            'coupon_type'         => in_array($r['coupon_type'] ?? '', ['percent', 'fixed'], true) ? $r['coupon_type'] : 'percent',
            'coupon_expiry_hours' => max(1, (int) ($r['coupon_expiry_hours']                   ?? 24)),
            'coupon_mode'         => in_array($r['coupon_mode'] ?? '', ['code', 'payment'], true) ? $r['coupon_mode'] : 'code',
            'product_filter_mode' => in_array($r['product_filter_mode'] ?? '', ['none', 'blacklist', 'whitelist'], true)
                ? $r['product_filter_mode'] : 'none',
            'product_filter_ids'  => array_values(array_filter(array_map('absint', (array) ($r['product_filter_ids'] ?? [])))),
        ];
    }
}
