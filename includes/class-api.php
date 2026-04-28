<?php
if (!defined('ABSPATH')) exit;

class Payamito_Api {

    private string $endpoint = 'https://api.payamak-panel.com/post/Send.asmx?wsdl';

    public function __construct(
        private string $username,
        private string $password,
    ) {}

    public function send_pattern_sms(string $mobile, int|string $body_id, array $args): mixed {
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
