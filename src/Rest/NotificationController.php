<?php

namespace VdPaymentGateway\Rest;

use VdPaymentGateway\PaymentService;
use VdPaymentGateway\Logger;
use VdPaymentGateway\Plugin;

class NotificationController
{
    const NAMESPACE = 'vd-pg/v1';

    public function register()
    {
        register_rest_route(self::NAMESPACE, '/notify/(?P<gateway>[a-z0-9_-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // Endpoint publik untuk server gateway; keaslian dicek lewat signature.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $request)
    {
        $gateway_id = sanitize_key((string) $request->get_param('gateway'));
        $log = [
            'ip' => $this->log_ip($request),
            'headers' => $this->log_headers($request),
            'body' => json_decode((string) $request->get_body(), true) ?: ($request->get_body_params() ?: mb_substr((string) $request->get_body(), 0, 1000)),
        ];

        $gateway = Plugin::instance()->registry()->get($gateway_id);
        if (!$gateway || !$gateway->is_active()) {
            Logger::log('notify.' . $gateway_id, $log + ['result' => 'gateway tidak aktif', 'http' => 404]);

            return new \WP_REST_Response(['message' => 'Gateway tidak aktif'], 404);
        }

        $normalized = $gateway->parse_notification($request);
        if (is_wp_error($normalized)) {
            return $this->error_response($normalized, $gateway_id, $log);
        }
        $log['normalized'] = array_diff_key($normalized, ['raw' => 1]);

        $result = (new PaymentService())->apply_status($gateway->id(), $normalized);
        if (is_wp_error($result)) {
            return $this->error_response($result, $gateway_id, $log);
        }

        Logger::log('notify.' . $gateway_id, $log + ['result' => 'OK', 'transaction_status' => is_object($result) ? $result->status : '', 'http' => 200]);

        return new \WP_REST_Response(['message' => 'OK'], 200);
    }

    /** Hanya dicatat untuk analisis; tidak dipakai untuk menerima/menolak notifikasi. */
    private function log_ip(\WP_REST_Request $request)
    {
        if (!Logger::enabled()) {
            return [];
        }
        $ip = ['remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : ''];
        foreach (['x_forwarded_for', 'x_real_ip', 'cf_connecting_ip'] as $name) {
            $value = $request->get_header($name);
            if ($value !== null && $value !== '') {
                $ip[$name] = sanitize_text_field($value);
            }
        }

        return $ip;
    }

    private function log_headers(\WP_REST_Request $request)
    {
        if (!Logger::enabled()) {
            return [];
        }
        $keep = ['client_id', 'request_id', 'request_timestamp', 'signature', 'content_type', 'user_agent'];
        $headers = [];
        foreach ($keep as $name) {
            $value = $request->get_header($name);
            if ($value !== null && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function error_response(\WP_Error $error, $gateway_id = '', array $log = [])
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        Logger::log('notify.' . $gateway_id, $log + ['result' => $error->get_error_code() . ': ' . $error->get_error_message(), 'http' => $status]);

        return new \WP_REST_Response(['message' => $error->get_error_message()], $status);
    }
}
