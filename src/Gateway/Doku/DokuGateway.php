<?php

namespace VdPaymentGateway\Gateway\Doku;

use VdPaymentGateway\Gateway\GatewayInterface;
use VdPaymentGateway\Logger;

/**
 * DOKU Checkout (Non-SNAP).
 * https://developers.doku.com/accept-payments/doku-checkout/integration-guide/backend-integration
 */
class DokuGateway implements GatewayInterface
{
    const OPTION = 'vdpg_doku';
    const PATH_PAYMENT = '/checkout/v1/payment';
    const PATH_STATUS = '/orders/v1/status/';

    public function id()
    {
        return 'doku';
    }

    public function label()
    {
        return 'DOKU';
    }

    public function option_name()
    {
        return self::OPTION;
    }

    public function options()
    {
        $options = get_option(self::OPTION, []);

        return wp_parse_args(is_array($options) ? $options : [], [
            'enabled' => '0',
            'mode' => 'sandbox',
            'client_id' => '',
            'secret_key' => '',
            'payment_due' => 60,
        ]);
    }

    public function is_active()
    {
        $options = $this->options();

        return $options['enabled'] === '1' && $options['client_id'] !== '' && $options['secret_key'] !== '';
    }

    public function base_url()
    {
        return $this->options()['mode'] === 'production' ? 'https://api.doku.com' : 'https://api-sandbox.doku.com';
    }

    public function notification_url()
    {
        return rest_url('vd-pg/v1/notify/doku');
    }

    public function settings_fields()
    {
        return [
            'enabled' => ['type' => 'checkbox', 'label' => __('Aktifkan DOKU', 'vd-payment-gateway')],
            'mode' => [
                'type' => 'select',
                'label' => __('Mode', 'vd-payment-gateway'),
                'choices' => ['sandbox' => 'Sandbox', 'production' => 'Production'],
            ],
            'client_id' => [
                'type' => 'text',
                'label' => 'Client ID',
                'hint' => __('DOKU Back Office > Integration > API Keys.', 'vd-payment-gateway'),
            ],
            'secret_key' => [
                'type' => 'secret',
                'label' => 'Secret Key',
                'hint' => __('Kosongkan bila tidak ingin mengubah Secret Key yang tersimpan.', 'vd-payment-gateway'),
            ],
            'payment_due' => [
                'type' => 'number',
                'label' => __('Batas bayar (menit)', 'vd-payment-gateway'),
                'hint' => __('Bawaan 60 menit.', 'vd-payment-gateway'),
            ],
        ];
    }

    public function create_payment(array $args)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_doku_inactive', __('Gateway DOKU belum aktif atau kredensial belum diisi.', 'vd-payment-gateway'));
        }

        $invoice = trim((string) ($args['invoice'] ?? ''));
        $amount = (int) round((float) ($args['amount'] ?? 0));

        if ($invoice === '' || strlen($invoice) > 64) {
            return new \WP_Error('vdpg_invalid_invoice', __('Nomor invoice wajib diisi, maksimal 64 karakter.', 'vd-payment-gateway'));
        }
        if ($amount <= 0) {
            return new \WP_Error('vdpg_invalid_amount', __('Jumlah pembayaran harus lebih dari 0.', 'vd-payment-gateway'));
        }

        $body = $this->build_payment_body($invoice, $amount, $args);
        $result = $this->request('POST', self::PATH_PAYMENT, $body);
        if (is_wp_error($result)) {
            return $result;
        }

        $payment = $result['response']['payment'] ?? [];
        $payment_url = (string) ($payment['url'] ?? '');
        if ($payment_url === '') {
            return new \WP_Error('vdpg_doku_bad_response', __('Respons DOKU tidak berisi URL pembayaran.', 'vd-payment-gateway'), $result);
        }

        return [
            'payment_url' => $payment_url,
            'token' => (string) ($payment['token_id'] ?? ''),
            'reference' => (string) ($result['response']['order']['session_id'] ?? ''),
            'expires_at' => $this->parse_expired_date((string) ($payment['expired_date'] ?? '')),
            'request' => $body,
            'raw' => $result,
        ];
    }

    public function build_payment_body($invoice, $amount, array $args)
    {
        $options = $this->options();
        $return_url = esc_url_raw((string) ($args['return_url'] ?? ''));
        $expiry = (int) ($args['expiry'] ?? 0);
        if ($expiry <= 0) {
            $expiry = max(1, (int) $options['payment_due']);
        }

        $order = [
            'amount' => $amount,
            'invoice_number' => $invoice,
            'currency' => 'IDR',
            'auto_redirect' => $return_url !== '',
        ];
        if ($return_url !== '') {
            $order['callback_url'] = $return_url;
            $order['callback_url_result'] = $return_url;
        }

        $line_items = $this->build_line_items((array) ($args['items'] ?? []), $amount);
        if (!empty($line_items)) {
            $order['line_items'] = $line_items;
        }

        $body = [
            'order' => $order,
            'payment' => ['payment_due_date' => $expiry],
            'additional_info' => ['override_notification_url' => $this->notification_url()],
        ];

        $customer = $this->build_customer((array) ($args['customer'] ?? []), $args);
        if (!empty($customer)) {
            $body['customer'] = $customer;
        }

        return apply_filters('vd_pg_doku_payment_body', $body, $args);
    }

    /**
     * DOKU mewajibkan total line_items sama dengan order.amount. Selisih positif (ongkir,
     * biaya) dijadikan satu baris tambahan; bila total item melebihi amount (diskon),
     * line_items tidak dikirim.
     */
    private function build_line_items(array $items, $amount)
    {
        $lines = [];
        $sum = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = (int) round((float) ($item['price'] ?? 0));
            $qty = max(1, (int) ($item['qty'] ?? ($item['quantity'] ?? 1)));
            if ($price <= 0) {
                continue;
            }
            $line = [
                'name' => mb_substr(wp_strip_all_tags((string) ($item['name'] ?? 'Item')), 0, 255),
                'price' => $price,
                'quantity' => $qty,
            ];
            if (!empty($item['sku'])) {
                $line['sku'] = (string) $item['sku'];
            }
            $lines[] = $line;
            $sum += $price * $qty;
        }

        if (empty($lines) || $sum > $amount) {
            return [];
        }
        if ($sum < $amount) {
            $lines[] = ['name' => __('Ongkir dan biaya lain', 'vd-payment-gateway'), 'price' => $amount - $sum, 'quantity' => 1];
        }

        return $lines;
    }

    private function build_customer(array $customer, array $args)
    {
        $data = [];

        $id = (string) ($customer['id'] ?? '');
        if ($id !== '') {
            $data['id'] = substr($id, 0, 50);
        }

        $name = trim(preg_replace('/[^\p{L}\s]/u', '', (string) ($customer['name'] ?? '')));
        if ($name !== '') {
            $data['name'] = mb_substr($name, 0, 255);
        }

        $email = sanitize_email((string) ($customer['email'] ?? ''));
        if ($email !== '' && is_email($email)) {
            $data['email'] = $email;
        }

        $phone = self::normalize_phone((string) ($customer['phone'] ?? ''));
        if ($phone !== '') {
            $data['phone'] = $phone;
        }

        foreach (['address' => 400, 'city' => 100, 'state' => 100, 'postcode' => 10] as $key => $max) {
            $value = trim((string) ($customer[$key] ?? ''));
            if ($value !== '') {
                $data[$key] = mb_substr($value, 0, $max);
            }
        }

        if (!empty($data)) {
            $data['country'] = 'ID';
        }

        return $data;
    }

    /** 0812xxx / +62812xxx / 812xxx menjadi 62812xxx. */
    public static function normalize_phone($phone)
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '0') === 0) {
            $digits = '62' . substr($digits, 1);
        } elseif (strpos($digits, '62') !== 0) {
            $digits = '62' . $digits;
        }

        return substr($digits, 0, 16);
    }

    /** expired_date DOKU berformat yyyyMMddHHmmss dalam WIB (UTC+7). */
    private function parse_expired_date($value)
    {
        if (!preg_match('/^\d{14}$/', $value)) {
            return 0;
        }
        $date = \DateTime::createFromFormat('YmdHis', $value, new \DateTimeZone('Asia/Jakarta'));

        return $date ? $date->getTimestamp() : 0;
    }

    public function check_status($invoice)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_doku_inactive', __('Gateway DOKU belum aktif.', 'vd-payment-gateway'));
        }

        $result = $this->request('GET', self::PATH_STATUS . rawurlencode((string) $invoice));
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->normalize_payload($result);
    }

    public function parse_notification(\WP_REST_Request $request)
    {
        $options = $this->options();
        $body = (string) $request->get_body();

        $client_id = (string) $request->get_header('client_id');
        $request_id = (string) $request->get_header('request_id');
        $timestamp = (string) $request->get_header('request_timestamp');
        $signature = (string) $request->get_header('signature');

        if ($client_id === '' || !hash_equals((string) $options['client_id'], $client_id)) {
            return new \WP_Error('vdpg_doku_client', 'Client-Id tidak cocok', ['status' => 401]);
        }

        $verified = false;
        foreach ($this->notification_targets() as $target) {
            if (DokuSignature::verify($signature, $client_id, $request_id, $timestamp, $target, $body, $options['secret_key'])) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            return new \WP_Error('vdpg_doku_signature', 'Signature tidak valid', ['status' => 401]);
        }

        $payload = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) {
            return new \WP_Error('vdpg_doku_body', 'Body bukan JSON', ['status' => 400]);
        }

        return $this->normalize_payload($payload);
    }

    /**
     * Request-Target notifikasi = path URL notifikasi merchant. Path bisa berbeda bila
     * situs memakai ?rest_route= atau berada di subfolder, jadi beberapa kandidat dicoba.
     */
    private function notification_targets()
    {
        $targets = [];
        $path = wp_parse_url($this->notification_url(), PHP_URL_PATH);
        if ($path) {
            $targets[] = $path;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $uri_path = $uri !== '' ? wp_parse_url($uri, PHP_URL_PATH) : '';
        if ($uri_path) {
            $targets[] = $uri_path;
        }
        $targets[] = '/wp-json/vd-pg/v1/notify/doku';

        return array_values(array_unique($targets));
    }

    /** Ubah notifikasi / respons cek status DOKU menjadi format seragam. */
    public function normalize_payload(array $payload)
    {
        $transaction_status = strtoupper((string) ($payload['transaction']['status'] ?? ''));
        $order_status = strtoupper((string) ($payload['order']['status'] ?? ''));

        switch ($transaction_status) {
            case 'SUCCESS':
                $status = 'paid';
                break;
            case 'EXPIRED':
                $status = 'expired';
                break;
            case 'REFUNDED':
                $status = 'refunded';
                break;
            case 'FAILED':
                // Di DOKU Checkout pelanggan bisa ganti metode setelah gagal, jadi FAILED
                // diabaikan (tetap pending) sesuai anjuran dokumentasi DOKU.
                $status = 'pending';
                break;
            default:
                $status = $order_status === 'ORDER_EXPIRED' ? 'expired' : 'pending';
        }

        return [
            'invoice' => (string) ($payload['order']['invoice_number'] ?? ''),
            'status' => $status,
            'gateway_status' => $transaction_status !== '' ? $transaction_status : $order_status,
            'amount' => (int) round((float) ($payload['order']['amount'] ?? 0)),
            'reference' => (string) ($payload['transaction']['original_request_id'] ?? ''),
            'channel' => (string) ($payload['channel']['id'] ?? ''),
            'raw' => $payload,
        ];
    }

    private function request($method, $path, ?array $body = null)
    {
        $options = $this->options();
        $request_id = wp_generate_uuid4();
        $timestamp = DokuSignature::timestamp();
        $json = $body !== null ? wp_json_encode($body) : null;

        $headers = [
            'Client-Id' => $options['client_id'],
            'Request-Id' => $request_id,
            'Request-Timestamp' => $timestamp,
            'Signature' => DokuSignature::generate($options['client_id'], $request_id, $timestamp, $path, $json, $options['secret_key']),
            'Accept' => 'application/json',
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $started = microtime(true);
        $response = wp_remote_request($this->base_url() . $path, [
            'method' => $method,
            'headers' => $headers,
            'body' => $json,
            'timeout' => 20,
        ]);

        $log = [
            'mode' => $options['mode'],
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if (is_wp_error($response)) {
            Logger::log('doku.request', $log + ['error' => $response->get_error_message()]);

            return new \WP_Error('vdpg_doku_http', sprintf(__('Gagal menghubungi DOKU: %s', 'vd-payment-gateway'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        Logger::log('doku.request', $log + ['http' => $code, 'response' => is_array($data) ? $data : mb_substr($raw, 0, 1000)]);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $messages = is_array($data) ? ($data['error_messages'] ?? ($data['message'] ?? $data['error']['message'] ?? '')) : '';
            $message = is_array($messages) ? implode('; ', array_map('strval', $messages)) : (string) $messages;

            return new \WP_Error(
                'vdpg_doku_api',
                sprintf(__('DOKU menolak permintaan (HTTP %1$d): %2$s', 'vd-payment-gateway'), $code, $message !== '' ? $message : mb_substr($raw, 0, 300)),
                ['status' => $code, 'body' => $data]
            );
        }

        return $data;
    }
}
