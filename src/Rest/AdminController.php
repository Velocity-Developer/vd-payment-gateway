<?php

namespace VdPaymentGateway\Rest;

use VdPaymentGateway\Admin\SettingsPage;
use VdPaymentGateway\Logger;
use VdPaymentGateway\PaymentService;
use VdPaymentGateway\Plugin;
use VdPaymentGateway\Transaction\TransactionRepository;

/**
 * REST untuk dashboard admin (vd-pg/v1/admin/...). Hanya untuk manage_options; dengan login cookie,
 * WordPress mewajibkan header X-WP-Nonce (wp_rest). Secret key tidak pernah dikirim ke browser.
 */
class AdminController
{
    const NS = 'vd-pg/v1';
    const PER_PAGE = 50;

    public function register()
    {
        $admin = [$this, 'can_manage'];

        register_rest_route(self::NS, '/admin/settings/(?P<gateway>[a-z0-9_-]+)', [
            ['methods' => 'GET', 'callback' => [$this, 'get_settings'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [$this, 'save_settings'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/admin/transactions', [
            'methods' => 'GET',
            'callback' => [$this, 'transactions'],
            'permission_callback' => $admin,
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'search' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'status' => ['type' => 'string', 'default' => '', 'enum' => array_merge([''], TransactionRepository::STATUSES)],
                'gateway' => ['type' => 'string', 'default' => '', 'pattern' => '^[a-z0-9_-]*$'],
            ],
        ]);
        register_rest_route(self::NS, '/admin/transactions/(?P<id>\d+)/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'sync'],
            'permission_callback' => $admin,
        ]);
        register_rest_route(self::NS, '/admin/log', [
            ['methods' => 'GET', 'callback' => [$this, 'get_log'], 'permission_callback' => $admin],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_log'],
                'permission_callback' => $admin,
                'args' => ['enabled' => ['type' => 'boolean', 'required' => true]],
            ],
            ['methods' => 'DELETE', 'callback' => [$this, 'clear_log'], 'permission_callback' => $admin],
        ]);
    }

    public function can_manage()
    {
        return current_user_can(SettingsPage::CAPABILITY);
    }

    private function gateway(\WP_REST_Request $request)
    {
        $gateway = Plugin::instance()->registry()->get((string) $request['gateway']);

        return $gateway ?: new \WP_Error('vdpg_unknown_gateway', __('Gateway tidak dikenal.', 'vd-payment-gateway'), ['status' => 404]);
    }

    public function get_settings(\WP_REST_Request $request)
    {
        $gateway = $this->gateway($request);
        if (is_wp_error($gateway)) {
            return $gateway;
        }

        return rest_ensure_response(SettingsPage::public_settings($gateway));
    }

    public function save_settings(\WP_REST_Request $request)
    {
        $gateway = $this->gateway($request);
        if (is_wp_error($gateway)) {
            return $gateway;
        }
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = $request->get_body_params();
        }
        $input = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];

        $clean = SettingsPage::sanitize_settings($gateway, $input, true);
        if (is_wp_error($clean)) {
            return $clean;
        }
        update_option($gateway->option_name(), $clean, false);

        return rest_ensure_response(SettingsPage::public_settings($gateway) + [
            'message' => __('Pengaturan disimpan.', 'vd-payment-gateway'),
        ]);
    }

    public function transactions(\WP_REST_Request $request)
    {
        $page = max(1, (int) $request['page']);
        $repo = new TransactionRepository();
        $result = $repo->search([
            'search' => (string) $request['search'],
            'status' => (string) $request['status'],
            'gateway' => (string) $request['gateway'],
        ], self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return rest_ensure_response([
            'rows' => array_map([SettingsPage::class, 'present_transaction'], $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'stats' => SettingsPage::present_stats($repo->stats()),
        ]);
    }

    public function sync(\WP_REST_Request $request)
    {
        $repo = new TransactionRepository();
        $transaction = $repo->find_by_id((int) $request['id']);
        if (!$transaction) {
            return new \WP_Error('vdpg_not_found', __('Transaksi tidak ditemukan.', 'vd-payment-gateway'), ['status' => 404]);
        }

        $result = (new PaymentService())->sync_status($transaction->gateway, $transaction->invoice);
        if (is_wp_error($result)) {
            // Beberapa error layanan memakai status 200 untuk balasan ke gateway; di sini selalu gagal.
            $data = (array) $result->get_error_data();
            $status = isset($data['status']) && (int) $data['status'] >= 400 ? (int) $data['status'] : 502;

            return new \WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
        }

        $row = $repo->find_by_id((int) $transaction->id);

        return rest_ensure_response([
            'row' => SettingsPage::present_transaction($row),
            'stats' => SettingsPage::present_stats($repo->stats()),
            /* translators: 1: invoice, 2: status */
            'message' => sprintf(__('Status %1$s: %2$s', 'vd-payment-gateway'), $row->invoice, SettingsPage::status_meta($row->status)[1]),
        ]);
    }

    private function log_payload($message = '')
    {
        $lines = Logger::tail(200);

        return [
            'enabled' => Logger::enabled(),
            'lines' => $lines,
            'count' => count($lines),
            'message' => $message,
        ];
    }

    public function get_log()
    {
        return rest_ensure_response($this->log_payload());
    }

    public function save_log(\WP_REST_Request $request)
    {
        update_option(Logger::OPTION, $request['enabled'] ? '1' : '0', false);

        return rest_ensure_response($this->log_payload(__('Pengaturan log disimpan.', 'vd-payment-gateway')));
    }

    public function clear_log()
    {
        Logger::clear();

        return rest_ensure_response($this->log_payload(__('Log dihapus.', 'vd-payment-gateway')));
    }
}
