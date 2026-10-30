<?php

/**
 * Alias kelas Duitku dari velocity-addons (< 2.3, dihapus di commit 2a81d86) supaya
 * vd-store, vd-marketplace, dan velocity-donasi versi lama tetap bisa memakai Duitku.
 * Hanya dimuat bila kelas aslinya tidak ada (lihat LegacyDuitku::boot()).
 */

if (!defined('ABSPATH') || class_exists('Velocity_Addons_Duitku', false)) {
    return;
}

class Velocity_Addons_Duitku
{
    /** Ditandai supaya kode lain bisa membedakan alias dengan kelas asli addons. */
    const VDPG_ALIAS = true;

    public function init()
    {
    }

    private static function driver()
    {
        return vd_pg_gateway('duitku');
    }

    public static function is_active()
    {
        $driver = self::driver();

        return $driver !== null && $driver->is_active();
    }

    /** Format opsi lama addons. */
    public static function options()
    {
        $driver = self::driver();
        $options = $driver ? $driver->options() : [];

        return [
            'mode' => $options['mode'] ?? 'sandbox',
            'kode_merchant' => $options['merchant_code'] ?? '',
            'merchant_key' => $options['api_key'] ?? '',
            'callback_url' => $driver ? $driver->notification_url() : '',
            'return_url' => $options['return_url'] ?? '',
            'url_createinvoice' => $driver ? $driver->invoice_url() : '',
        ];
    }

    /**
     * Sama dengan createInvoice() lama: menerima parameter Duitku mentah dan
     * mengembalikan respons Duitku (merchantCode, reference, paymentUrl, statusCode, statusMessage).
     */
    public function createInvoice($params = null)
    {
        if (empty($params) || !is_array($params)) {
            return new WP_Error('invalid_params', 'Parameter tidak valid');
        }
        if (empty($params['merchantOrderId']) || empty($params['paymentAmount'])) {
            return new WP_Error('missing_params', 'Parameter yang belum diisi: merchantOrderId, paymentAmount');
        }

        $result = \VdPaymentGateway\Compat\LegacyDuitku::create_invoice($params);

        return is_wp_error($result) ? $result : $result['raw'];
    }

    public function get_by_invoice($invoice)
    {
        $transaction = vd_pg_get_transaction('duitku', (string) $invoice);
        if (!$transaction) {
            return null;
        }

        return (object) [
            'id' => $transaction->id,
            'invoice' => $transaction->invoice,
            'reference' => $transaction->reference,
            'payment_url' => $transaction->payment_url,
            'amount' => $transaction->amount,
            'status_code' => $transaction->status === 'paid' ? '00' : '01',
        ];
    }
}
