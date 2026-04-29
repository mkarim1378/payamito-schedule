<?php
if (!defined('ABSPATH')) exit;

class Payamito_Scheduler {

    private const RETRY_DELAYS = [
        1 => 5  * MINUTE_IN_SECONDS,
        2 => 30 * MINUTE_IN_SECONDS,
        3 => 2  * HOUR_IN_SECONDS,
    ];

    private const MAX_ATTEMPTS = 4;

    private const AS_GROUP = 'payamito-sms';

    public function __construct() {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_change'], 10, 4);
        add_action('payamito_execute_scheduled_sms',   [$this, 'execute'],          10, 5);
    }

    public function on_status_change(int $order_id, string $from_status, string $to_status, $order): void {
        $rules           = get_option('payamito_schedule_rules', []);
        $prefixed_status = 'wc-' . $to_status;

        foreach ($rules as $rule) {
            if ($rule['status'] !== $prefixed_status) continue;

            $delay  = $this->to_seconds((int) $rule['delay_val'], $rule['delay_unit']);
            $run_at = time() + $delay;

            $hook_args = [
                'order_id'     => $order_id,
                'pattern_code' => $rule['pattern'],
                'vars_str'     => $rule['vars'],
                'scheduled_at' => date('Y-m-d H:i:s', $run_at),
                'attempt'      => 1,
            ];

            if (as_has_scheduled_action('payamito_execute_scheduled_sms', $hook_args, self::AS_GROUP)) {
                continue;
            }

            as_schedule_single_action($run_at, 'payamito_execute_scheduled_sms', $hook_args, self::AS_GROUP);
        }
    }

    public function execute(int $order_id, string $pattern_code, string $vars_str, ?string $scheduled_at = null, int $attempt = 1): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Abstract_Order) return;

        $phone = $order->get_billing_phone();
        if (empty($phone)) return;

        $credentials = get_option('payamito_credentials', []);
        $api         = new Payamito_Api(
            $credentials['username'] ?? '',
            $credentials['password'] ?? ''
        );

        $sms_args = $this->resolve_vars($vars_str, $order);
        $result   = $api->send_pattern_sms($phone, $pattern_code, $sms_args);
        $now      = current_time('mysql');
        $success  = $result !== null;
        $masked   = $this->mask_phone($phone);

        Payamito_Logger::insert([
            'order_id'     => $order_id,
            'mobile'       => $phone,
            'pattern'      => $pattern_code,
            'vars'         => $vars_str,
            'status'       => $success ? 'sent' : 'failed',
            'response'     => $success
                ? (is_object($result) ? print_r($result, true) : (string) $result)
                : null,
            'attempt'      => $attempt,
            'scheduled_at' => $scheduled_at ?? $now,
            'sent_at'      => $success ? $now : null,
        ]);

        if ($success) {
            $response_str = is_object($result) ? print_r($result, true) : (string) $result;
            $order->add_order_note(
                sprintf('[پیامیتو] پیامک پترن %s به شماره %s ارسال شد. (پاسخ: %s)', $pattern_code, $masked, $response_str),
                false,
                false
            );
            return;
        }

        if ($attempt < self::MAX_ATTEMPTS) {
            $delay         = self::RETRY_DELAYS[$attempt];
            $retry_hook_args = [
                'order_id'     => $order_id,
                'pattern_code' => $pattern_code,
                'vars_str'     => $vars_str,
                'scheduled_at' => $scheduled_at,
                'attempt'      => $attempt + 1,
            ];
            as_schedule_single_action(time() + $delay, 'payamito_execute_scheduled_sms', $retry_hook_args, self::AS_GROUP);
            $order->add_order_note(
                sprintf(
                    '[پیامیتو] خطا در ارسال پترن %s به %s — تلاش %d از %d. ارسال مجدد در %s.',
                    $pattern_code,
                    $masked,
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $this->format_delay($delay)
                ),
                false,
                false
            );
        } else {
            $order->add_order_note(
                sprintf(
                    '[پیامیتو] خطا در ارسال پترن %s به %s — همه %d تلاش ناموفق بود.',
                    $pattern_code,
                    $masked,
                    self::MAX_ATTEMPTS
                ),
                false,
                false
            );
        }
    }

    private function format_delay(int $seconds): string {
        if ($seconds < HOUR_IN_SECONDS) return (int) ($seconds / 60) . ' دقیقه';
        return (int) ($seconds / HOUR_IN_SECONDS) . ' ساعت';
    }

    private function mask_phone(string $phone): string {
        if (strlen($phone) < 7) return $phone;
        return substr($phone, 0, 4) . '****' . substr($phone, -3);
    }

    private function resolve_vars(string $vars_str, WC_Abstract_Order $order): array {
        $placeholders = [
            '{billing_first_name}' => $order->get_billing_first_name(),
            '{billing_last_name}'  => $order->get_billing_last_name(),
            '{order_id}'           => (string) $order->get_id(),
            '{order_total}'        => (string) $order->get_total(),
            '{billing_phone}'      => $order->get_billing_phone(),
        ];

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
