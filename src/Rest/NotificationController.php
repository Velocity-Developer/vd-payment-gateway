<?php

namespace VdPaymentGateway\Rest;

use VdPaymentGateway\PaymentService;
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
        $gateway = Plugin::instance()->registry()->get($request->get_param('gateway'));
        if (!$gateway || !$gateway->is_active()) {
            return new \WP_REST_Response(['message' => 'Gateway tidak aktif'], 404);
        }

        $normalized = $gateway->parse_notification($request);
        if (is_wp_error($normalized)) {
            return $this->error_response($normalized);
        }

        $result = (new PaymentService())->apply_status($gateway->id(), $normalized);
        if (is_wp_error($result)) {
            return $this->error_response($result);
        }

        return new \WP_REST_Response(['message' => 'OK'], 200);
    }

    private function error_response(\WP_Error $error)
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

        return new \WP_REST_Response(['message' => $error->get_error_message()], $status);
    }
}
