<?php

namespace VdPaymentGateway\Gateway\Midtrans;

use VdPaymentGateway\Gateway\GatewayInterface;
use VdPaymentGateway\Logger;

/**
 * Midtrans Snap (redirect_url) + HTTP notification + cek status (Core API /v2/{order_id}/status).
 * Autentikasi Basic: base64(server_key + ':').
 * Notifikasi diverifikasi dengan signature_key = SHA512(order_id + status_code + gross_amount + server_key).
 * https://docs.midtrans.com/reference/snap-api dan https://docs.midtrans.com/docs/https-notification-webhooks
 */
class MidtransGateway implements GatewayInterface
{
    const OPTION = 'vdpg_midtrans';
    const INVOICE_MAX = 50;

    public function id()
    {
        return 'midtrans';
    }

    public function label()
    {
        return 'Midtrans';
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
            'merchant_id' => '',
            'client_key' => '',
            'server_key' => '',
            'expiry' => 60,
            'enabled_payments' => '',
        ]);
    }

    public function is_active()
    {
        $options = $this->options();

        return $options['enabled'] === '1' && $options['server_key'] !== '';
    }

    public function is_sandbox()
    {
        return $this->options()['mode'] !== 'production';
    }

    public function snap_url()
    {
        return $this->is_sandbox()
            ? 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            : 'https://app.midtrans.com/snap/v1/transactions';
    }

    public function api_base_url()
    {
        return $this->is_sandbox() ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com';
    }

    public function notification_url()
    {
        return rest_url('vd-pg/v1/notify/midtrans');
    }

    public function settings_fields()
    {
        return [
            'enabled' => ['type' => 'checkbox', 'label' => __('Aktifkan Midtrans', 'vd-payment-gateway')],
            'mode' => [
                'type' => 'select',
                'label' => __('Mode', 'vd-payment-gateway'),
                'choices' => ['sandbox' => 'Sandbox', 'production' => 'Production'],
            ],
            'merchant_id' => [
                'type' => 'text',
                'label' => 'Merchant ID',
                'hint' => __('Dashboard Midtrans > Settings > Access Keys. Hanya untuk catatan.', 'vd-payment-gateway'),
            ],
            'client_key' => [
                'type' => 'text',
                'label' => 'Client Key',
                'hint' => __('Dashboard Midtrans > Settings > Access Keys (awalan SB-Mid-client- untuk sandbox).', 'vd-payment-gateway'),
            ],
            'server_key' => [
                'type' => 'secret',
                'label' => 'Server Key',
                'hint' => __('Kosongkan bila tidak ingin mengubah Server Key yang tersimpan.', 'vd-payment-gateway'),
            ],
            'expiry' => [
                'type' => 'number',
                'label' => __('Batas bayar (menit)', 'vd-payment-gateway'),
                'hint' => __('Bawaan 60 menit.', 'vd-payment-gateway'),
            ],
            'enabled_payments' => [
                'type' => 'text',
                'label' => __('Metode yang ditampilkan', 'vd-payment-gateway'),
                'hint' => __('Opsional, pisahkan dengan koma, mis. bca_va,bni_va,gopay,other_qris. Kosongkan untuk semua metode aktif di akun Midtrans.', 'vd-payment-gateway'),
            ],
        ];
    }

    public function create_payment(array $args)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_midtrans_inactive', __('Gateway Midtrans belum aktif atau Server Key belum diisi.', 'vd-payment-gateway'));
        }

        $invoice = trim((string) ($args['invoice'] ?? ''));
        $amount = (int) round((float) ($args['amount'] ?? 0));

        if ($invoice === '' || strlen($invoice) > self::INVOICE_MAX || !preg_match('/^[A-Za-z0-9\-_~.]+$/', $invoice)) {
            return new \WP_Error('vdpg_invalid_invoice', __('Nomor invoice wajib diisi, maksimal 50 karakter (huruf, angka, - _ ~ .).', 'vd-payment-gateway'));
        }
        if ($amount <= 0) {
            return new \WP_Error('vdpg_invalid_amount', __('Jumlah pembayaran harus lebih dari 0.', 'vd-payment-gateway'));
        }

        $body = $this->build_snap_body($invoice, $amount, $args);
        $result = $this->request('POST', $this->snap_url(), $body, [
            // Notifikasi transaksi ini selalu ke endpoint plugin ini supaya status tercatat di satu tempat.
            'X-Override-Notification' => $this->notification_url(),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        $redirect = (string) ($result['redirect_url'] ?? '');
        if ($redirect === '' || empty($result['token'])) {
            return new \WP_Error('vdpg_midtrans_bad_response', __('Respons Midtrans tidak berisi URL pembayaran.', 'vd-payment-gateway'), $result);
        }

        return [
            'payment_url' => $redirect,
            'token' => (string) $result['token'],
            'reference' => (string) $result['token'],
            'expires_at' => time() + 60 * (int) $body['expiry']['duration'],
            'request' => $body,
            'raw' => $result,
        ];
    }

    public function build_snap_body($invoice, $amount, array $args)
    {
        $options = $this->options();
        $expiry = (int) ($args['expiry'] ?? 0);
        if ($expiry <= 0) {
            $expiry = max(1, (int) $options['expiry']);
        }

        $start = new \DateTime('now', new \DateTimeZone('Asia/Jakarta'));
        $body = [
            'transaction_details' => ['order_id' => $invoice, 'gross_amount' => $amount],
            'expiry' => ['start_time' => $start->format('Y-m-d H:i:s O'), 'unit' => 'minute', 'duration' => $expiry],
            'custom_field1' => mb_substr((string) ($args['source'] ?? ''), 0, 255),
            'custom_field2' => mb_substr((string) ($args['ref_id'] ?? ''), 0, 255),
        ];

        $items = $this->build_item_details((array) ($args['items'] ?? []), $amount);
        if (!empty($items)) {
            $body['item_details'] = $items;
        }

        $customer = $this->build_customer((array) ($args['customer'] ?? []));
        if (!empty($customer)) {
            $body['customer_details'] = $customer;
        }

        $return_url = esc_url_raw((string) ($args['return_url'] ?? ''));
        if ($return_url !== '') {
            $body['callbacks'] = ['finish' => $return_url];
        }

        $payments = array_values(array_filter(array_map(function ($code) {
            return sanitize_key(trim($code));
        }, explode(',', (string) $options['enabled_payments']))));
        if (!empty($payments)) {
            $body['enabled_payments'] = $payments;
        }

        return apply_filters('vd_pg_midtrans_snap_body', $body, $args);
    }

    /**
     * Midtrans menolak bila total item_details != gross_amount. Selisih positif (ongkir, biaya)
     * jadi satu baris tambahan; bila total item melebihi amount (diskon), item_details tidak dikirim.
     * Nama item maks 50 karakter.
     */
    private function build_item_details(array $items, $amount)
    {
        $lines = [];
        $sum = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = (int) round((float) ($item['price'] ?? 0));
            $qty = max(1, (int) ($item['qty'] ?? ($item['quantity'] ?? 1)));
            if ($price <= 0) {
                continue;
            }
            $sku = (string) ($item['sku'] ?? '');
            $lines[] = [
                'id' => mb_substr($sku !== '' ? $sku : 'item-' . ($index + 1), 0, 50),
                'price' => $price,
                'quantity' => $qty,
                'name' => mb_substr(wp_strip_all_tags((string) ($item['name'] ?? 'Item')), 0, 50),
            ];
            $sum += $price * $qty;
        }

        if (empty($lines) || $sum > $amount) {
            return [];
        }
        if ($sum < $amount) {
            $lines[] = ['id' => 'biaya-lain', 'price' => $amount - $sum, 'quantity' => 1, 'name' => __('Ongkir dan biaya lain', 'vd-payment-gateway')];
        }

        return $lines;
    }

    private function build_customer(array $customer)
    {
        $data = [];
        $name = trim((string) ($customer['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);
            $data['first_name'] = mb_substr((string) array_shift($parts), 0, 255);
            if (!empty($parts)) {
                $data['last_name'] = mb_substr(implode(' ', $parts), 0, 255);
            }
        }

        $email = sanitize_email((string) ($customer['email'] ?? ''));
        if ($email !== '' && is_email($email)) {
            $data['email'] = $email;
        }

        $phone = preg_replace('/[^\d+]/', '', (string) ($customer['phone'] ?? ''));
        if ($phone !== '') {
            $data['phone'] = mb_substr($phone, 0, 19);
        }

        $address = array_filter([
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'address' => mb_substr(trim((string) ($customer['address'] ?? '')), 0, 200),
            'city' => mb_substr(trim((string) ($customer['city'] ?? '')), 0, 100),
            'postal_code' => mb_substr(preg_replace('/\D/', '', (string) ($customer['postcode'] ?? '')), 0, 10),
        ], 'strlen');
        if (!empty($address['address']) || !empty($address['city'])) {
            $address['country_code'] = 'IDN';
            $data['billing_address'] = $address;
            $data['shipping_address'] = $address;
        }

        return $data;
    }

    public function check_status($invoice)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_midtrans_inactive', __('Gateway Midtrans belum aktif.', 'vd-payment-gateway'));
        }

        $result = $this->request('GET', $this->api_base_url() . '/v2/' . rawurlencode((string) $invoice) . '/status');
        if (is_wp_error($result)) {
            return $result;
        }

        // Midtrans menjawab HTTP 200 dengan status_code 404 bila transaksi belum ada (Snap belum dibayar/dipilih).
        if ((string) ($result['status_code'] ?? '') === '404') {
            return [
                'invoice' => (string) $invoice,
                'status' => 'pending',
                'gateway_status' => 'not_found',
                'amount' => 0,
                'reference' => '',
                'channel' => '',
                'raw' => $result,
            ];
        }

        return $this->normalize_payload($result);
    }

    public function parse_notification(\WP_REST_Request $request)
    {
        $payload = json_decode((string) $request->get_body(), true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) {
            return new \WP_Error('vdpg_midtrans_body', 'Body bukan JSON', ['status' => 400]);
        }

        $order_id = (string) ($payload['order_id'] ?? '');
        $status_code = (string) ($payload['status_code'] ?? '');
        $gross_amount = (string) ($payload['gross_amount'] ?? '');
        $signature = strtolower((string) ($payload['signature_key'] ?? ''));

        if ($order_id === '' || $status_code === '' || $gross_amount === '' || $signature === '') {
            return new \WP_Error('vdpg_midtrans_params', 'Parameter tidak lengkap', ['status' => 400]);
        }
        if (!hash_equals($this->signature($order_id, $status_code, $gross_amount), $signature)) {
            return new \WP_Error('vdpg_midtrans_signature', 'Signature tidak valid', ['status' => 401]);
        }

        return $this->normalize_payload($payload);
    }

    public function signature($order_id, $status_code, $gross_amount)
    {
        return hash('sha512', $order_id . $status_code . $gross_amount . (string) $this->options()['server_key']);
    }

    /** Ubah notifikasi / respons cek status Midtrans menjadi format seragam. */
    public function normalize_payload(array $payload)
    {
        $transaction_status = strtolower((string) ($payload['transaction_status'] ?? ''));
        $fraud_status = strtolower((string) ($payload['fraud_status'] ?? ''));

        switch ($transaction_status) {
            case 'capture':
                // Kartu kredit: hanya "accept" yang lunas; "challenge" menunggu keputusan di dashboard.
                $status = ($fraud_status === '' || $fraud_status === 'accept') ? 'paid' : ($fraud_status === 'deny' ? 'failed' : 'pending');
                break;
            case 'settlement':
                $status = 'paid';
                break;
            case 'expire':
                $status = 'expired';
                break;
            case 'refund':
                $status = 'refunded';
                break;
            case 'partial_refund':
                // Refund sebagian: transaksi tetap lunas (status refunded final); payload tetap tersimpan.
                $status = 'paid';
                break;
            case 'cancel':
            case 'failure':
                $status = 'failed';
                break;
            default:
                // pending, authorize, deny (pelanggan masih bisa mencoba metode lain di halaman Snap).
                $status = 'pending';
        }

        return [
            'invoice' => (string) ($payload['order_id'] ?? ''),
            'status' => $status,
            'gateway_status' => $transaction_status . ($fraud_status !== '' ? '/' . $fraud_status : ''),
            'amount' => (int) round((float) ($payload['gross_amount'] ?? 0)),
            'reference' => (string) ($payload['transaction_id'] ?? ''),
            'channel' => $this->channel($payload),
            'raw' => $payload,
        ];
    }

    /** payment_type + bank/penerbit, mis. bank_transfer:bca, qris:gopay, echannel. */
    private function channel(array $payload)
    {
        $type = (string) ($payload['payment_type'] ?? '');
        $detail = '';
        if (!empty($payload['va_numbers'][0]['bank'])) {
            $detail = (string) $payload['va_numbers'][0]['bank'];
        } elseif (!empty($payload['permata_va_number'])) {
            $detail = 'permata';
        } elseif (!empty($payload['issuer'])) {
            $detail = (string) $payload['issuer'];
        } elseif (!empty($payload['bank'])) {
            $detail = (string) $payload['bank'];
        } elseif (!empty($payload['store'])) {
            $detail = (string) $payload['store'];
        }

        return sanitize_text_field($type . ($detail !== '' ? ':' . $detail : ''));
    }

    private function request($method, $url, ?array $body = null, array $extra_headers = [])
    {
        $options = $this->options();
        $json = $body !== null ? wp_json_encode($body) : null;
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($options['server_key'] . ':'),
        ] + $extra_headers;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $started = microtime(true);
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $json,
            'timeout' => 20,
        ]);

        $log = [
            'mode' => $options['mode'],
            'method' => $method,
            'path' => (string) wp_parse_url($url, PHP_URL_PATH),
            'headers' => $headers,
            'body' => $body,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if (is_wp_error($response)) {
            Logger::log('midtrans.request', $log + ['error' => Logger::scrub($response->get_error_message())]);

            return new \WP_Error('vdpg_midtrans_http', sprintf(__('Gagal menghubungi Midtrans: %s', 'vd-payment-gateway'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        Logger::log('midtrans.request', $log + ['http' => $code, 'response' => is_array($data) ? $data : Logger::excerpt($raw)]);

        // Cek status transaksi yang belum ada: Midtrans membalas status_code 404 (HTTP 200 atau 404).
        if ($code === 404 && is_array($data) && (string) ($data['status_code'] ?? '') === '404') {
            return $data;
        }
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $messages = is_array($data) ? ($data['error_messages'] ?? ($data['status_message'] ?? '')) : '';
            $message = is_array($messages) ? implode('; ', array_map('strval', $messages)) : (string) $messages;

            return new \WP_Error(
                'vdpg_midtrans_api',
                sprintf(__('Midtrans menolak permintaan (HTTP %1$d): %2$s', 'vd-payment-gateway'), $code, $message !== '' ? $message : mb_substr($raw, 0, 300)),
                ['status' => $code, 'body' => $data]
            );
        }

        return $data;
    }
}
