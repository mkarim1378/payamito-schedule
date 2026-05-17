<?php
if (!defined('ABSPATH')) exit;

class Payamito_Scheduler {

    private static bool $reverting_cancellation = false;

    private const RETRY_DELAYS = [
        1 => 5  * MINUTE_IN_SECONDS,
        2 => 30 * MINUTE_IN_SECONDS,
        3 => 2  * HOUR_IN_SECONDS,
    ];

    private const MAX_ATTEMPTS = 4;

    private const AS_GROUP = 'payamito-sms';

    public function __construct() {
        add_action('woocommerce_order_status_changed',    [$this, 'on_status_change'],      10, 4);
        add_action('woocommerce_order_status_changed',    [$this, 'prevent_cancellation'],  20, 4);
        add_action('woocommerce_new_order',               [$this, 'on_new_order'],          10, 2);
        add_action('payamito_execute_scheduled_sms',      [$this, 'execute'],               10, 14);
        // Cancel scheduled SMS when an order is trashed or permanently deleted
        add_action('wp_trash_post',                       [$this, 'on_order_removed'],      10, 1);
        add_action('before_delete_post',                  [$this, 'on_order_removed'],      10, 1);
        add_action('woocommerce_trash_order',             [self::class, 'cancel_scheduled_for_order'], 10, 1);
        add_action('woocommerce_before_delete_order',     [self::class, 'cancel_scheduled_for_order'], 10, 1);
    }

    public function on_new_order(int $order_id, $order): void {
        if (!$order instanceof WC_Abstract_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order instanceof WC_Abstract_Order) return;
        $this->on_status_change($order_id, '', $order->get_status(), $order);
    }

    public function on_order_removed(int $post_id): void {
        if (get_post_type($post_id) !== 'shop_order') return;
        self::cancel_scheduled_for_order($post_id);
    }

    public static function cancel_scheduled_for_order(int $order_id, string $new_status = ''): void {
        if (!function_exists('as_get_scheduled_actions')) return;
        $actions = as_get_scheduled_actions([
            'hook'     => 'payamito_execute_scheduled_sms',
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'group'    => self::AS_GROUP,
            'per_page' => 100,
        ]);
        if (empty($actions)) return;

        $mobile = '';
        if ($new_status) {
            $order = wc_get_order($order_id);
            if ($order instanceof WC_Abstract_Order) {
                try {
                    $mobile = self::normalize_phone($order->get_billing_phone());
                } catch (\InvalidArgumentException $e) {
                    $mobile = $order->get_billing_phone();
                }
            }
        }

        $store = ActionScheduler_Store::instance();
        foreach ($actions as $action_id => $action) {
            $args = $action->get_args();
            if ((int) ($args['order_id'] ?? 0) !== $order_id) continue;
            if ($new_status) {
                $send_type = $args['send_type'] ?? 'pattern';
                Payamito_Logger::insert([
                    'order_id'     => $order_id,
                    'mobile'       => $mobile,
                    'pattern'      => $send_type === 'text' ? 'text' : ($args['pattern_code'] ?? ''),
                    'vars'         => $send_type === 'text' ? ($args['text_body'] ?? '') : ($args['vars_str'] ?? ''),
                    'status'       => 'superseded',
                    'response'     => $new_status,
                    'attempt'      => (int) ($args['attempt'] ?? 1),
                    'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
                    'sent_at'      => null,
                ]);
            }
            try {
                $store->cancel_action($action_id);
            } catch (Exception $e) {
                error_log('[Payamito] Failed to cancel action ' . $action_id . ': ' . $e->getMessage());
            }
        }
    }

    public function prevent_cancellation(int $order_id, string $from, string $to, $order): void {
        if ($to !== 'cancelled') return;
        $credentials = get_option('payamito_credentials', []);
        if (empty($credentials['prevent_cancellation'])) return;

        self::$reverting_cancellation = true;
        $order->update_status('pending', '[پیامیتو] لغو سفارش بلوکه شد — وضعیت به «در انتظار پرداخت» برگشت.');
        self::$reverting_cancellation = false;
    }

    public function on_status_change(int $order_id, string $from_status, string $to_status, $order): void {
        if (self::$reverting_cancellation) return;
        self::cancel_scheduled_for_order($order_id, $to_status);
        $rules           = get_option('payamito_schedule_rules', []);
        $prefixed_status = 'wc-' . $to_status;

        foreach ($rules as $rule) {
            if ($rule['status'] !== $prefixed_status) continue;

            $delay  = $this->to_seconds((int) $rule['delay_val'], $rule['delay_unit']);
            $run_at = time() + $delay;

            $hook_args = [
                'order_id'            => $order_id,
                'pattern_code'        => $rule['pattern']              ?? '',
                'vars_str'            => $rule['vars']                 ?? '',
                'scheduled_at'        => date('Y-m-d H:i:s', $run_at),
                'attempt'             => 1,
                'send_type'           => $rule['send_type']            ?? 'pattern',
                'text_body'           => $rule['text_body']            ?? '',
                'coupon_enabled'      => (int)   ($rule['coupon_enabled']      ?? 0),
                'coupon_amount'       => (float) ($rule['coupon_amount']       ?? 0),
                'coupon_type'         => $rule['coupon_type']                  ?? 'percent',
                'coupon_expiry_hours' => (int)   ($rule['coupon_expiry_hours'] ?? 24),
                'coupon_mode'         => $rule['coupon_mode']                  ?? 'code',
                'trigger_status'      => $to_status,
                'rule_fingerprint'   => self::rule_fingerprint($rule),
            ];

            if (as_has_scheduled_action('payamito_execute_scheduled_sms', $hook_args, self::AS_GROUP)) {
                continue;
            }

            as_schedule_single_action($run_at, 'payamito_execute_scheduled_sms', $hook_args, self::AS_GROUP);
        }
    }

    public function execute(
        int $order_id,
        string $pattern_code,
        string $vars_str,
        ?string $scheduled_at = null,
        int $attempt = 1,
        string $send_type = 'pattern',
        string $text_body = '',
        int $coupon_enabled = 0,
        float $coupon_amount = 0,
        string $coupon_type = 'percent',
        int $coupon_expiry_hours = 24,
        string $coupon_mode = 'code',
        string $trigger_status = '',
        string $rule_fingerprint = ''
    ): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Abstract_Order) return;
        if (in_array($order->get_status(), ['trash'], true)) return;

        // اگه وضعیت سفارش از زمان زمان‌بندی SMS تغییر کرده، این SMS منسوخ شده — ارسال نمیشه
        if ($trigger_status !== '' && $order->get_status() !== $trigger_status) return;

        // اگه قانونی که این SMS رو زمانبندی کرده حذف یا ویرایش شده، ارسال نمیشه
        if ($rule_fingerprint !== '') {
            $valid = array_map([self::class, 'rule_fingerprint'], get_option('payamito_schedule_rules', []));
            if (!in_array($rule_fingerprint, $valid, true)) return;
        }

        // سفارش رایگان: اگه قانون کد تخفیف داره ولی مبلغ سفارش صفره، پیامک ارسال نمیشه
        if ($coupon_enabled && $coupon_amount > 0 && $order->get_total() <= 0) return;

        $phone = $order->get_billing_phone();
        if (empty($phone)) return;

        $now = current_time('mysql');

        try {
            $phone = self::normalize_phone($phone);
        } catch (\InvalidArgumentException $e) {
            Payamito_Logger::insert([
                'order_id'     => $order_id,
                'mobile'       => $order->get_billing_phone(),
                'pattern'      => $send_type === 'text' ? 'text' : $pattern_code,
                'vars'         => $send_type === 'text' ? $text_body : $vars_str,
                'status'       => 'failed',
                'response'     => $e->getMessage(),
                'attempt'      => $attempt,
                'scheduled_at' => $scheduled_at ?? $now,
                'sent_at'      => null,
            ]);
            $label = $send_type === 'text' ? 'متن ثابت' : ('پترن ' . $pattern_code);
            $order->add_order_note(
                sprintf('[پیامیتو] شماره تلفن سفارش نامعتبر است — ارسال %s لغو شد.', $label),
                false, false
            );
            return;
        }

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        );

        // حالت تست: redirect به شماره‌های تست به جای شماره واقعی مشتری
        $test_targets = [];
        $is_test_mode = !empty($credentials['test_mode']);
        if ($is_test_mode) {
            foreach (explode("\n", $credentials['test_phones'] ?? '') as $tp) {
                $tp = trim($tp);
                if ($tp === '') continue;
                try {
                    $test_targets[] = self::normalize_phone($tp);
                } catch (\InvalidArgumentException $e) {
                    // شماره تست نامعتبر — رد می‌شود
                }
            }
        }
        $send_targets = !empty($test_targets) ? $test_targets : [$phone];

        // ── ایجاد کد تخفیف (اگر فعال باشد) ─────────────────────────
        $coupon_code = '';
        if ($coupon_enabled && $coupon_amount > 0) {
            $coupon_code = $this->create_or_get_coupon($order_id, $coupon_amount, $coupon_type, $coupon_expiry_hours, $order);
            if ($coupon_code && $coupon_mode === 'payment') {
                if (!in_array($coupon_code, $order->get_coupon_codes(), true)) {
                    $applied = $order->apply_coupon($coupon_code);
                    if (!is_wp_error($applied)) {
                        $order->calculate_totals();
                        $order->save();
                    }
                }
            }
        }

        if ($send_type === 'text') {
            $resolved_text = $this->resolve_text($text_body, $order);
            if ($coupon_code) {
                $resolved_text = str_replace('{coupon_code}', $coupon_code, $resolved_text);
            }
            $from        = $credentials['from_number'] ?? '';
            $log_pattern = 'text';
            $log_vars    = $text_body;
        } else {
            $sms_args    = $this->resolve_vars($vars_str, $order);
            $log_pattern = $pattern_code;
            $log_vars    = $vars_str;
        }

        $result = null;
        foreach ($send_targets as $target) {
            $r = $send_type === 'text'
                ? $api->send_smart_sms($target, $resolved_text, $from)
                : $api->send_pattern_sms($target, $pattern_code, $sms_args);
            if ($r !== null) $result = $r;
        }

        $success = $result !== null;
        $masked  = $this->mask_phone($phone);

        Payamito_Logger::insert([
            'order_id'     => $order_id,
            'mobile'       => $phone,
            'pattern'      => $log_pattern,
            'vars'         => $log_vars,
            'status'       => $success ? 'sent' : 'failed',
            'response'     => $success
                ? (is_array($result) ? wp_json_encode($result) : (is_object($result) ? print_r($result, true) : (string) $result))
                : null,
            'attempt'      => $attempt,
            'scheduled_at' => $scheduled_at ?? $now,
            'sent_at'      => $success ? $now : null,
        ]);

        if ($success) {
            $response_str = is_array($result) ? wp_json_encode($result) : (is_object($result) ? print_r($result, true) : (string) $result);
            $label        = $send_type === 'text' ? 'متن ثابت' : ('پترن ' . $pattern_code);
            $test_note    = $is_test_mode ? sprintf(' [حالت تست — به %s]', implode('، ', array_map([$this, 'mask_phone'], $send_targets))) : '';
            $order->add_order_note(
                sprintf('[پیامیتو] پیامک %s به شماره %s ارسال شد.%s (پاسخ: %s)', $label, $masked, $test_note, $response_str),
                false,
                false
            );
            return;
        }

        if ($attempt < self::MAX_ATTEMPTS) {
            $delay           = self::RETRY_DELAYS[$attempt];
            $retry_hook_args = [
                'order_id'            => $order_id,
                'pattern_code'        => $pattern_code,
                'vars_str'            => $vars_str,
                'scheduled_at'        => $scheduled_at,
                'attempt'             => $attempt + 1,
                'send_type'           => $send_type,
                'text_body'           => $text_body,
                'coupon_enabled'      => $coupon_enabled,
                'coupon_amount'       => $coupon_amount,
                'coupon_type'         => $coupon_type,
                'coupon_expiry_hours' => $coupon_expiry_hours,
                'coupon_mode'         => $coupon_mode,
                'trigger_status'      => $trigger_status,
                'rule_fingerprint'   => $rule_fingerprint,
            ];
            as_schedule_single_action(time() + $delay, 'payamito_execute_scheduled_sms', $retry_hook_args, self::AS_GROUP);
            $label = $send_type === 'text' ? 'متن ثابت' : ('پترن ' . $pattern_code);
            $order->add_order_note(
                sprintf(
                    '[پیامیتو] خطا در ارسال %s به %s — تلاش %d از %d. ارسال مجدد در %s.',
                    $label,
                    $masked,
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $this->format_delay($delay)
                ),
                false,
                false
            );
        } else {
            $label = $send_type === 'text' ? 'متن ثابت' : ('پترن ' . $pattern_code);
            $order->add_order_note(
                sprintf(
                    '[پیامیتو] خطا در ارسال %s به %s — همه %d تلاش ناموفق بود.',
                    $label,
                    $masked,
                    self::MAX_ATTEMPTS
                ),
                false,
                false
            );
        }
    }

    private function create_or_get_coupon(
        int $order_id,
        float $amount,
        string $type,
        int $expiry_hours,
        WC_Abstract_Order $order
    ): string {
        $code      = 'carno' . $order_id;
        $coupon_id = wc_get_coupon_id_by_code($code);
        $coupon    = $coupon_id ? new WC_Coupon($coupon_id) : new WC_Coupon();

        $coupon->set_code($code);
        $coupon->set_discount_type($type === 'fixed' ? 'fixed_cart' : 'percent');
        $coupon->set_amount($amount);
        $coupon->set_usage_limit(1);
        $coupon->set_individual_use(false);
        $coupon->set_date_expires(time() + $expiry_hours * HOUR_IN_SECONDS);

        $email = $order->get_billing_email();
        if ($email) {
            $coupon->set_email_restrictions([$email]);
        }

        $saved_id = $coupon->save();
        if ($saved_id) {
            update_post_meta($saved_id, '_payamito_coupon', '1');
        }

        return $code;
    }

    public static function rule_fingerprint(array $rule): string {
        return md5(implode('|', [
            $rule['status']              ?? '',
            $rule['delay_val']           ?? 0,
            $rule['delay_unit']          ?? '',
            $rule['send_type']           ?? '',
            $rule['pattern']             ?? '',
            $rule['vars']                ?? '',
            $rule['text_body']           ?? '',
            $rule['coupon_enabled']      ?? 0,
            $rule['coupon_amount']       ?? 0,
            $rule['coupon_type']         ?? '',
            $rule['coupon_expiry_hours'] ?? 0,
            $rule['coupon_mode']         ?? '',
        ]));
    }

    public static function cleanup_stale_actions(array $rules): void {
        if (!function_exists('as_get_scheduled_actions')) return;

        $valid    = array_map([self::class, 'rule_fingerprint'], $rules);
        $store    = ActionScheduler_Store::instance();
        $per_page = 100;
        $page     = 1;

        do {
            $actions = as_get_scheduled_actions([
                'hook'     => 'payamito_execute_scheduled_sms',
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'group'    => self::AS_GROUP,
                'per_page' => $per_page,
                'page'     => $page,
            ]);

            if (empty($actions)) break;

            foreach ($actions as $action_id => $action) {
                $args = $action->get_args();
                $fp   = $args['rule_fingerprint'] ?? null;
                // actionهای بدون fingerprint (ذخیره‌شده قبل از این ورژن) دست‌نخورده می‌مانند
                if ($fp === null || in_array($fp, $valid, true)) continue;
                try {
                    $store->cancel_action($action_id);
                } catch (Exception $e) {
                    error_log('[Payamito] cleanup_stale: failed to cancel action ' . $action_id);
                }
            }

            $page++;
        } while (count($actions) === $per_page);
    }

    private function format_delay(int $seconds): string {
        if ($seconds < HOUR_IN_SECONDS) return (int) ($seconds / 60) . ' دقیقه';
        return (int) ($seconds / HOUR_IN_SECONDS) . ' ساعت';
    }

    public static function normalize_phone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0098')) {
            $phone = '0' . substr($phone, 4);
        } elseif (str_starts_with($phone, '98') && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 2);
        } elseif (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }

        if (strlen($phone) !== 11 || !str_starts_with($phone, '09')) {
            throw new \InvalidArgumentException("invalid_phone: {$phone}");
        }

        return $phone;
    }

    private function mask_phone(string $phone): string {
        if (strlen($phone) < 7) return $phone;
        return substr($phone, 0, 4) . '****' . substr($phone, -3);
    }

    public static function build_placeholders(WC_Abstract_Order $order): array {
        $names = [];
        $links = [];
        foreach ($order->get_items() as $item) {
            $names[] = $item->get_name();
            $product = $item->get_product();
            if ($product) {
                $links[] = get_permalink($product->get_id());
            }
        }

        return [
            '{billing_first_name}' => $order->get_billing_first_name(),
            '{billing_last_name}'  => $order->get_billing_last_name(),
            '{order_id}'           => (string) $order->get_id(),
            '{order_total}'        => (string) $order->get_total(),
            '{billing_phone}'      => $order->get_billing_phone(),
            '{product_names}'      => implode(' و ', $names),
            '{product_links}'      => implode('، ', $links),
            '{payment_link}'       => home_url('/pay/' . $order->get_id()),
        ];
    }

    private function resolve_text(string $text, WC_Abstract_Order $order): string {
        $placeholders = self::build_placeholders($order);
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }

    private function resolve_vars(string $vars_str, WC_Abstract_Order $order): array {
        $placeholders = self::build_placeholders($order);

        $result = [];
        foreach (explode(';', $vars_str) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;

            [$key, $template] = array_pad(explode(':', $pair, 2), 2, '');
            $result[trim($key)] = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                trim($template)
            );
        }
        return $result;
    }

    private function to_seconds(int $val, string $unit): int {
        if ($val <= 0) return 0;
        return match ($unit) {
            'minutes' => $val * 60,
            'hours'   => $val * 3600,
            'days'    => $val * 86400,
            default   => 0,
        };
    }
}
