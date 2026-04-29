<?php
if (!defined('ABSPATH')) exit;

class Payamito_Logger {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'payamito_sms_log';
    }

    public static function create_table(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  mobile       VARCHAR(20) NOT NULL,
  pattern      VARCHAR(50) NOT NULL,
  vars         TEXT NOT NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'failed',
  response     TEXT NULL,
  attempt      TINYINT NOT NULL DEFAULT 1,
  scheduled_at DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY status (status),
  KEY sent_at (sent_at)
) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function insert(array $data): int|false {
        global $wpdb;

        $ok = $wpdb->insert(
            self::table(),
            [
                'order_id'     => (int)    $data['order_id'],
                'mobile'       =>           $data['mobile'],
                'pattern'      =>           $data['pattern'],
                'vars'         =>           $data['vars']         ?? '',
                'status'       =>           $data['status'],
                'response'     =>           $data['response']     ?? null,
                'attempt'      => (int)   ($data['attempt']       ?? 1),
                'scheduled_at' =>           $data['scheduled_at'] ?? current_time('mysql'),
                'sent_at'      =>           $data['sent_at']      ?? null,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $ok ? $wpdb->insert_id : false;
    }

    public static function get_rows(array $args = []): array {
        global $wpdb;
        $table  = self::table();
        $where  = 'WHERE 1=1';
        $values = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = $args['status'];
        }

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $page     = max(1, (int) ($args['page']     ?? 1));
        $offset   = ($page - 1) * $per_page;
        $values[] = $per_page;
        $values[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d", $values),
            ARRAY_A
        ) ?: [];
    }

    public static function count(array $args = []): int {
        global $wpdb;
        $table  = self::table();
        $where  = 'WHERE 1=1';
        $values = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = $args['status'];
        }

        $sql = "SELECT COUNT(*) FROM $table $where";

        return (int) ($values
            ? $wpdb->get_var($wpdb->prepare($sql, $values))
            : $wpdb->get_var($sql));
    }

    public static function purge_old(int $days = 90): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `$table` WHERE scheduled_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
