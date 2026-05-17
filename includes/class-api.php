<?php
if (!defined('ABSPATH')) exit;

class Payamito_Api {

    private string $endpoint = 'https://api.payamak-panel.com/post/Send.asmx?wsdl';

    public function __construct(
        private string $username,
        private string $password,
    ) {}

    public function send_smart_sms(string $mobile, string $text, string $from): mixed {
        $response = wp_remote_post('https://rest.payamak-panel.com/api/SmartSMS/Send', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'username' => $this->username,
                'password' => $this->password,
                'to'       => $mobile,
                'text'     => $text,
                'from'     => $from,
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('[Payamito] SmartSMS error: ' . $response->get_error_message());
            return null;
        }

        $body       = json_decode(wp_remote_retrieve_body($response), true);
        $ret_status = (int) ($body['RetStatus'] ?? 0);

        if ($ret_status !== 1) {
            error_log('[Payamito] SmartSMS error: RetStatus=' . $ret_status . ' Value=' . ($body['Value'] ?? ''));
            return null;
        }

        return $body;
    }

    public function send_pattern_sms(string $mobile, int|string $body_id, array $args): mixed {
        if (!is_numeric($body_id) || (int) $body_id <= 0) {
            error_log('[Payamito] send_pattern_sms: invalid pattern code "' . $body_id . '"');
            return null;
        }
        try {
            $client = new SoapClient($this->endpoint, [
                'connection_timeout' => 10,
                'cache_wsdl'         => WSDL_CACHE_BOTH,
                'exceptions'         => true,
            ]);
            return $client->SendByBaseNumber([
                'username' => $this->username,
                'password' => $this->password,
                'text'     => array_values($args),
                'to'       => $mobile,
                'bodyId'   => (int) $body_id,
            ]);
        } catch (Exception $e) {
            error_log('[Payamito] SOAP error: ' . $e->getMessage());
            return null;
        }
    }
}
