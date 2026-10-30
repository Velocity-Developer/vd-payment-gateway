<?php

namespace VdPaymentGateway\Compat;

use VdPaymentGateway\Gateway\Duitku\DuitkuGateway;
use VdPaymentGateway\Transaction\Installer;

/**
 * Sekali jalan: ambil pengaturan dan riwayat invoice Duitku dari velocity-addons lama
 * (opsi velocity_duitku_options, tabel vd_duitku_invoice / vd_duitku_callback).
 */
class DuitkuImporter
{
    const DONE_OPTION = 'vdpg_duitku_imported';

    public static function run()
    {
        if (get_option(self::DONE_OPTION)) {
            return;
        }

        $result = [
            'settings' => self::import_settings(),
            'invoices' => self::import_invoices(),
        ];

        update_option(self::DONE_OPTION, $result + ['at' => current_time('mysql', true)], false);
    }

    private static function import_settings()
    {
        $old = get_option('velocity_duitku_options');
        if (!is_array($old) || get_option(DuitkuGateway::OPTION) !== false) {
            return false;
        }

        update_option(DuitkuGateway::OPTION, [
            'enabled' => get_option('velocity_duitku', '0') === '1' ? '1' : '0',
            'mode' => ($old['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'merchant_code' => sanitize_text_field((string) ($old['kode_merchant'] ?? '')),
            'api_key' => trim((string) ($old['merchant_key'] ?? '')),
            'expiry' => 60,
            'return_url' => esc_url_raw((string) ($old['return_url'] ?? '')),
        ], false);

        return true;
    }

    private static function import_invoices()
    {
        global $wpdb;

        $old_invoice = $wpdb->prefix . 'vd_duitku_invoice';
        $old_callback = $wpdb->prefix . 'vd_duitku_callback';
        if (!self::table_exists($old_invoice)) {
            return 0;
        }

        $paid = [];
        if (self::table_exists($old_callback)) {
            foreach ((array) $wpdb->get_results("SELECT invoice, payment_code, update_at FROM $old_callback WHERE result_code = '00'") as $row) {
                $paid[$row->invoice] = $row;
            }
        }

        $table = Installer::table();
        $count = 0;
        foreach ((array) $wpdb->get_results("SELECT * FROM $old_invoice") as $row) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE gateway = 'duitku' AND invoice = %s", $row->invoice));
            if ($exists) {
                continue;
            }
            $created = self::datetime($row->created_at ?? '');
            $callback = $paid[$row->invoice] ?? null;
            $wpdb->insert($table, [
                'gateway' => 'duitku',
                'invoice' => (string) $row->invoice,
                'source' => 'legacy-import',
                'amount' => (int) round((float) ($row->amount ?? 0)),
                'status' => $callback ? 'paid' : 'pending',
                'reference' => (string) $row->reference,
                'channel' => $callback ? (string) $callback->payment_code : '',
                'payment_url' => (string) $row->payment_url,
                'note' => __('Diimpor dari velocity-addons', 'vd-payment-gateway'),
                'paid_at' => $callback ? self::datetime($callback->update_at) : null,
                'created_at' => $created,
                'updated_at' => self::datetime($row->update_at ?? '') ?: $created,
            ]);
            $count++;
        }

        return $count;
    }

    private static function table_exists($table)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function datetime($value)
    {
        $time = strtotime((string) $value);

        return $time ? gmdate('Y-m-d H:i:s', $time) : current_time('mysql', true);
    }
}
