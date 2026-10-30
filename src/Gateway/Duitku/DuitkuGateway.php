<?php

namespace VdPaymentGateway\Gateway\Duitku;

use VdPaymentGateway\Gateway\GatewayInterface;

/**
 * Duitku Pop (createInvoice) + HTTP callback + cek status.
 * Signature memakai HMAC-SHA256 (MD5/SHA256 lama dinyatakan obsolete oleh Duitku sejak Apr 2026).
 * https://docs.duitku.com/pop/en/ dan https://docs.duitku.com/api/en/
 */
class DuitkuGateway implements GatewayInterface
{
    const OPTION = 'vdpg_duitku';

    public function id()
    {
        return 'duitku';
    }

    public function label()
    {
        return 'Duitku';
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
            'merchant_code' => '',
            'api_key' => '',
            'expiry' => 60,
            'return_url' => '',
        ]);
    }

    public function is_active()
    {
        $options = $this->options();

        return $options['enabled'] === '1' && $options['merchant_code'] !== '' && $options['api_key'] !== '';
    }

    public function is_sandbox()
    {
        return $this->options()['mode'] !== 'production';
    }

    public function invoice_url()
    {
        return $this->is_sandbox()
            ? 'https://api-sandbox.duitku.com/api/merchant/createInvoice'
            : 'https://api-prod.duitku.com/api/merchant/createInvoice';
    }

    public function status_url()
    {
        return $this->is_sandbox()
            ? 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus'
            : 'https://passport.duitku.com/webapi/api/merchant/transactionStatus';
    }

    public function pop_js_url()
    {
        return $this->is_sandbox()
            ? 'https://app-sandbox.duitku.com/lib/js/duitku.js'
            : 'https://app-prod.duitku.com/lib/js/duitku.js';
    }

    public function notification_url()
    {
        return rest_url('vd-pg/v1/notify/duitku');
    }

    public function settings_fields()
    {
        return [
            'enabled' => ['type' => 'checkbox', 'label' => __('Aktifkan Duitku', 'vd-payment-gateway')],
            'mode' => [
                'type' => 'select',
                'label' => __('Mode', 'vd-payment-gateway'),
                'choices' => ['sandbox' => 'Sandbox', 'production' => 'Production'],
            ],
            'merchant_code' => [
                'type' => 'text',
                'label' => __('Kode Merchant', 'vd-payment-gateway'),
                'hint' => __('Dashboard Duitku > Proyek Saya.', 'vd-payment-gateway'),
            ],
            'api_key' => [
                'type' => 'secret',
                'label' => __('API Key (Merchant Key)', 'vd-payment-gateway'),
                'hint' => __('Kosongkan bila tidak ingin mengubah API Key yang tersimpan.', 'vd-payment-gateway'),
            ],
            'expiry' => [
                'type' => 'number',
                'label' => __('Batas bayar (menit)', 'vd-payment-gateway'),
                'hint' => __('Bawaan 60 menit.', 'vd-payment-gateway'),
            ],
            'return_url' => [
                'type' => 'text',
                'label' => __('Return URL bawaan', 'vd-payment-gateway'),
                'hint' => __('Dipakai bila plugin pemanggil tidak mengirim return URL.', 'vd-payment-gateway'),
            ],
        ];
    }

    public function sign(...$parts)
    {
        return hash_hmac('sha256', implode('', array_map('strval', $parts)), (string) $this->options()['api_key']);
    }

    public function create_payment(array $args)
    {
        $invoice = trim((string) ($args['invoice'] ?? ''));
        $amount = (int) round((float) ($args['amount'] ?? 0));

        if ($invoice === '' || strlen($invoice) > 50) {
            return new \WP_Error('vdpg_invalid_invoice', __('Nomor invoice wajib diisi, maksimal 50 karakter.', 'vd-payment-gateway'));
        }
        if ($amount <= 0) {
            return new \WP_Error('vdpg_invalid_amount', __('Jumlah pembayaran harus lebih dari 0.', 'vd-payment-gateway'));
        }

        $customer = (array) ($args['customer'] ?? []);
        $name = trim((string) ($customer['name'] ?? ''));
        $parts = $name !== '' ? preg_split('/\s+/', $name) : ['Customer'];
        $first = (string) array_shift($parts);
        $last = implode(' ', $parts);
        $email = sanitize_email((string) ($customer['email'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        $address = [
            'firstName' => $first,
            'lastName' => $last,
            'address' => (string) ($customer['address'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'postalCode' => (string) ($customer['postcode'] ?? ''),
            'phone' => $phone,
            'countryCode' => 'ID',
        ];

        $params = [
            'paymentAmount' => $amount,
            'merchantOrderId' => $invoice,
            'productDetails' => (string) ($args['description'] ?? sprintf(__('Pembayaran %s', 'vd-payment-gateway'), $invoice)),
            'additionalParam' => (string) ($args['ref_id'] ?? ''),
            'merchantUserInfo' => (string) ($customer['id'] ?? ''),
            'customerVaName' => $name !== '' ? $name : 'Customer',
            'email' => is_email($email) ? $email : '',
            'phoneNumber' => $phone,
            'customerDetail' => [
                'firstName' => $first,
                'lastName' => $last,
                'email' => is_email($email) ? $email : '',
                'phoneNumber' => $phone,
                'billingAddress' => $address,
                'shippingAddress' => $address,
            ],
            'returnUrl' => (string) ($args['return_url'] ?? ''),
            'expiryPeriod' => (int) ($args['expiry'] ?? 0),
        ];

        $items = $this->build_item_details((array) ($args['items'] ?? []), $amount);
        if (!empty($items)) {
            $params['itemDetails'] = $items;
        }

        return $this->create_invoice(apply_filters('vd_pg_duitku_invoice_params', $params, $args));
    }

    /**
     * Kirim parameter createInvoice Duitku apa adanya (dipakai juga oleh alias
     * Velocity_Addons_Duitku untuk plugin lama).
     *
     * @return array|\WP_Error Hasil normal + 'raw' berisi respons asli Duitku.
     */
    public function create_invoice(array $params)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_duitku_inactive', __('Gateway Duitku belum aktif atau kredensial belum diisi.', 'vd-payment-gateway'));
        }

        $options = $this->options();
        // callbackUrl selalu ke endpoint plugin ini supaya status tercatat di satu tempat.
        $params['callbackUrl'] = $this->notification_url();
        if (empty($params['returnUrl'])) {
            $params['returnUrl'] = $options['return_url'] !== '' ? $options['return_url'] : home_url('/');
        }
        if (empty($params['expiryPeriod'])) {
            $params['expiryPeriod'] = max(1, (int) $options['expiry']);
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $body = wp_json_encode($params);

        $response = wp_remote_post($this->invoice_url(), [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-duitku-signature' => $this->sign($options['merchant_code'], $timestamp),
                'x-duitku-timestamp' => $timestamp,
                'x-duitku-merchantcode' => $options['merchant_code'],
            ],
            'body' => $body,
            'timeout' => 20,
        ]);

        $result = $this->decode_response($response);
        if (is_wp_error($result)) {
            return $result;
        }
        if (empty($result['paymentUrl']) || (string) ($result['statusCode'] ?? '') !== '00') {
            return new \WP_Error('vdpg_duitku_api', sprintf(__('Duitku menolak permintaan: %s', 'vd-payment-gateway'), (string) ($result['statusMessage'] ?? 'tanpa pesan')), $result);
        }

        return [
            'payment_url' => (string) $result['paymentUrl'],
            'token' => (string) ($result['reference'] ?? ''),
            'reference' => (string) ($result['reference'] ?? ''),
            'expires_at' => time() + 60 * (int) $params['expiryPeriod'],
            'request' => $params,
            'raw' => $result,
        ];
    }

    /** Duitku: harga per baris = harga satuan x qty, total harus sama dengan paymentAmount. */
    private function build_item_details(array $items, $amount)
    {
        $lines = [];
        $sum = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(1, (int) ($item['qty'] ?? ($item['quantity'] ?? 1)));
            $total = (int) round((float) ($item['price'] ?? 0)) * $qty;
            if ($total <= 0) {
                continue;
            }
            $lines[] = ['name' => mb_substr(wp_strip_all_tags((string) ($item['name'] ?? 'Item')), 0, 255), 'price' => $total, 'quantity' => $qty];
            $sum += $total;
        }

        if (empty($lines) || $sum > $amount) {
            return [];
        }
        if ($sum < $amount) {
            $lines[] = ['name' => __('Ongkir dan biaya lain', 'vd-payment-gateway'), 'price' => $amount - $sum, 'quantity' => 1];
        }

        return $lines;
    }

    public function check_status($invoice)
    {
        if (!$this->is_active()) {
            return new \WP_Error('vdpg_duitku_inactive', __('Gateway Duitku belum aktif.', 'vd-payment-gateway'));
        }

        $merchant_code = $this->options()['merchant_code'];
        $response = wp_remote_post($this->status_url(), [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode([
                'merchantCode' => $merchant_code,
                'merchantOrderId' => (string) $invoice,
                'signature' => $this->sign($merchant_code, $invoice),
            ]),
            'timeout' => 20,
        ]);

        $result = $this->decode_response($response);
        if (is_wp_error($result)) {
            return $result;
        }

        $code = (string) ($result['statusCode'] ?? '');
        $status = $code === '00' ? 'paid' : ($code === '02' ? 'failed' : 'pending');

        return [
            'invoice' => (string) ($result['merchantOrderId'] ?? $invoice),
            'status' => $status,
            'gateway_status' => $code,
            'amount' => (int) round((float) ($result['amount'] ?? 0)),
            'reference' => (string) ($result['reference'] ?? ''),
            'channel' => '',
            'raw' => $result,
        ];
    }

    public function parse_notification(\WP_REST_Request $request)
    {
        $data = $request->get_body_params();
        if (empty($data)) {
            $data = $request->get_params();
        }
        $data = array_map(function ($value) {
            return is_scalar($value) ? (string) $value : '';
        }, (array) $data);
        unset($data['gateway']);

        $options = $this->options();
        $merchant_code = $data['merchantCode'] ?? '';
        $amount = $data['amount'] ?? '';
        $invoice = $data['merchantOrderId'] ?? '';
        $signature = $data['signature'] ?? '';

        if ($merchant_code === '' || $amount === '' || $invoice === '' || $signature === '') {
            return new \WP_Error('vdpg_duitku_params', 'Parameter tidak lengkap', ['status' => 400]);
        }
        if (!hash_equals((string) $options['merchant_code'], $merchant_code)) {
            return new \WP_Error('vdpg_duitku_merchant', 'Kode merchant tidak cocok', ['status' => 401]);
        }

        $valid = hash_equals($this->sign($merchant_code, $amount, $invoice), $signature)
            // Akun Duitku lama yang belum pindah ke HMAC masih mengirim MD5.
            || hash_equals(md5($merchant_code . $amount . $invoice . $options['api_key']), $signature);
        if (!$valid) {
            return new \WP_Error('vdpg_duitku_signature', 'Bad Signature', ['status' => 401]);
        }

        $result_code = $data['resultCode'] ?? '';

        return [
            'invoice' => $invoice,
            'status' => $result_code === '00' ? 'paid' : 'failed',
            'gateway_status' => $result_code,
            'amount' => (int) round((float) $amount),
            'reference' => $data['reference'] ?? '',
            'channel' => $data['paymentCode'] ?? '',
            'raw' => $data,
        ];
    }

    private function decode_response($response)
    {
        if (is_wp_error($response)) {
            return new \WP_Error('vdpg_duitku_http', sprintf(__('Gagal menghubungi Duitku: %s', 'vd-payment-gateway'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code !== 200 || !is_array($data)) {
            $message = is_array($data) ? (string) ($data['Message'] ?? ($data['statusMessage'] ?? '')) : '';

            return new \WP_Error(
                'vdpg_duitku_api',
                sprintf(__('Duitku menolak permintaan (HTTP %1$d): %2$s', 'vd-payment-gateway'), $code, $message !== '' ? $message : mb_substr($raw, 0, 300)),
                ['status' => $code, 'body' => $data]
            );
        }

        return $data;
    }
}
