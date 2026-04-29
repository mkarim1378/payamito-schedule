<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Payamito_Log_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'id'           => '#',
            'order_id'     => 'سفارش',
            'mobile'       => 'شماره',
            'pattern'      => 'پترن',
            'status'       => 'وضعیت',
            'attempt'      => 'تلاش',
            'scheduled_at' => 'زمان‌بندی‌شده',
            'sent_at'      => 'زمان ارسال',
        ];
    }

    protected function get_views(): array {
        $current  = sanitize_key($_GET['status_filter'] ?? '');
        $base_url = admin_url('admin.php?page=payamito-scheduler&tab=log');

        $statuses = [
            ''          => 'همه',
            'sent'      => 'ارسال‌شده',
            'failed'    => 'ناموفق',
            'cancelled' => 'لغوشده',
        ];

        $views = [];
        foreach ($statuses as $key => $label) {
            $count  = Payamito_Logger::count($key ? ['status' => $key] : []);
            $href   = $key ? add_query_arg('status_filter', $key, $base_url) : $base_url;
            $class  = $current === $key ? ' class="current"' : '';
            $views[$key] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($href), $class, esc_html($label), $count
            );
        }

        return $views;
    }

    public function prepare_items(): void {
        $per_page = 20;
        $page     = $this->get_pagenum();
        $status   = sanitize_key($_GET['status_filter'] ?? '');

        $query_args = ['per_page' => $per_page, 'page' => $page];
        if ($status) $query_args['status'] = $status;

        $this->items = Payamito_Logger::get_rows($query_args);
        $total       = Payamito_Logger::count($status ? ['status' => $status] : []);

        $this->set_pagination_args(['total_items' => $total, 'per_page' => $per_page]);
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    public function column_default($item, $column_name): string {
        return esc_html($item[$column_name] ?? '—');
    }

    public function column_id($item): string {
        return '#' . (int) $item['id'];
    }

    public function column_order_id($item): string {
        $id  = (int) $item['order_id'];
        $url = admin_url("post.php?post={$id}&action=edit");
        return '<a href="' . esc_url($url) . '">#' . $id . '</a>';
    }

    public function column_mobile($item): string {
        $phone = $item['mobile'];
        if (strlen($phone) > 6) {
            $phone = substr($phone, 0, 4) . '***' . substr($phone, -3);
        }
        return esc_html($phone);
    }

    public function column_status($item): string {
        $map = [
            'sent'      => '<span style="color:#2ea44f;font-weight:bold">✓ ارسال‌شده</span>',
            'failed'    => '<span style="color:#cf222e;font-weight:bold">✗ ناموفق</span>',
            'cancelled' => '<span style="color:#6e7781">⊘ لغوشده</span>',
        ];
        return $map[$item['status']] ?? esc_html($item['status']);
    }

    public function column_sent_at($item): string {
        return $item['sent_at'] ? esc_html($item['sent_at']) : '—';
    }

    public function no_items(): void {
        echo 'هیچ رکوردی یافت نشد.';
    }
}
