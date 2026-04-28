<?php
if (!defined('ABSPATH')) exit;

class Payamito_Api {

    private $username;
    private $password;
    private $endpoint = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }

    public function send_pattern_sms($mobile, $body_id, array $args) {
        try {
            $client = new SoapClient($this->endpoint);
            $result = $client->SendByBaseNumber([
                'username' => $this->username,
                'password' => $this->password,
                'text'     => array_values($args),
                'to'       => $mobile,
                'bodyId'   => intval($body_id),
            ]);
            return $result;
        } catch (Exception $e) {
            error_log('[Payamito] SOAP error: ' . $e->getMessage());
            return null;
        }
    }
}
