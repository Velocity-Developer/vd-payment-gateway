<?php

namespace VdPaymentGateway\Compat;

use VdPaymentGateway\PaymentService;
use VdPaymentGateway\Rest\NotificationController;

/**
 * Jembatan untuk plugin yang masih memakai API Duitku lama dari velocity-addons:
 * kelas Velocity_Addons_Duitku, shortcode [tombol_bayar_duitku], endpoint
 * velocityaddons/v1/duitku_callback, dan action velocity_duitku_callback.
 */
class LegacyDuitku
{
    public static function boot()
    {
        // Addons lama (< 2.3) yang masih memuat kelas aslinya dibiarkan bekerja sendiri.
        if (class_exists('Velocity_Addons_Duitku', false)) {
            return;
        }

        // Dimuat lewat autoloader (saat class_exists()/new dipanggil), bukan langsung, supaya
        // mengaktifkan addons lama setelah plugin ini aktif tidak gagal karena kelas ganda.
        spl_autoload_register(function ($class) {
            if ($class === 'Velocity_Addons_Duitku') {
                require_once VDPG_PATH . 'src/Compat/class-velocity-addons-duitku.php';
            }
        });

        if (!shortcode_exists('tombol_bayar_duitku')) {
            add_shortcode('tombol_bayar_duitku', [__CLASS__, 'shortcode_pay_button']);
        }

        add_action('rest_api_init', [__CLASS__, 'register_legacy_route']);
        add_action('vd_pg_payment_status_changed', [__CLASS__, 'fire_legacy_action'], 10, 3);
    }

    public static function create_invoice(array $params)
    {
        $driver = vd_pg_gateway('duitku');
        if (!$driver) {
            return new \WP_Error('vdpg_unknown_gateway', 'Gateway Duitku tidak tersedia');
        }

        return (new PaymentService())->create_payment_with('duitku', [
            'invoice' => (string) $params['merchantOrderId'],
            'amount' => (int) $params['paymentAmount'],
            'source' => 'legacy-duitku',
            'ref_id' => (string) ($params['additionalParam'] ?? ''),
        ], function () use ($driver, $params) {
            return $driver->create_invoice($params);
        });
    }

    /** Invoice yang dibuat sebelum migrasi masih bisa mengirim callback ke URL lama. */
    public static function register_legacy_route()
    {
        register_rest_route('velocityaddons/v1', '/duitku_callback', [
            'methods' => 'POST',
            'callback' => function (\WP_REST_Request $request) {
                $request->set_param('gateway', 'duitku');

                return (new NotificationController())->handle($request);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Action lama menerima payload callback Duitku. Untuk perubahan yang berasal dari
     * cek status (bukan callback), payload disusun ulang dengan kunci yang sama.
     */
    public static function fire_legacy_action($transaction, $status, $payload)
    {
        if ($transaction->gateway !== 'duitku' || !in_array($status, ['paid', 'failed', 'expired'], true)) {
            return;
        }

        $payload = is_array($payload) ? $payload : [];
        $payload = array_merge([
            'merchantCode' => vd_pg_gateway('duitku')->options()['merchant_code'],
            'merchantOrderId' => $transaction->invoice,
            'amount' => (string) $transaction->amount,
            'reference' => $transaction->reference,
            'paymentCode' => $transaction->channel,
            'additionalParam' => $transaction->ref_id,
        ], $payload);
        $payload['resultCode'] = $status === 'paid' ? '00' : '01';

        do_action('velocity_duitku_callback', $payload);
    }

    public static function shortcode_pay_button($atts)
    {
        $atts = shortcode_atts(['invoice' => '', 'class' => 'btn btn-primary'], $atts);
        $transaction = vd_pg_get_transaction('duitku', (string) $atts['invoice']);

        if (!$transaction || $transaction->reference === '') {
            return '<div class="alert alert-danger">' . esc_html__('Data invoice Duitku tidak ditemukan, silakan buat invoice terlebih dahulu.', 'vd-payment-gateway') . '</div>';
        }
        if ($transaction->status === 'paid') {
            return '<div class="alert alert-success">' . esc_html__('Pembayaran sudah diterima.', 'vd-payment-gateway') . '</div>';
        }

        $driver = vd_pg_gateway('duitku');
        $button_id = 'vdpg-duitku-' . substr(md5($transaction->invoice), 0, 10);
        wp_enqueue_script('vdpg-duitku-pop', $driver->pop_js_url(), [], null, true);
        wp_add_inline_script('vdpg-duitku-pop', sprintf(
            'document.getElementById(%1$s)&&document.getElementById(%1$s).addEventListener("click",function(e){e.preventDefault();if(window.checkout&&checkout.process){checkout.process(%2$s,{defaultLanguage:"id",successEvent:function(){e.target.style.display="none";}});}else{window.location.href=%3$s;}});',
            wp_json_encode($button_id),
            wp_json_encode($transaction->reference),
            wp_json_encode($transaction->payment_url)
        ));

        return sprintf(
            '<a href="%1$s" id="%2$s" class="%3$s">%4$s %5$s</a>',
            esc_url($transaction->payment_url),
            esc_attr($button_id),
            esc_attr($atts['class']),
            esc_html__('Bayar Sekarang', 'vd-payment-gateway'),
            esc_html(number_format_i18n((int) $transaction->amount))
        );
    }
}
