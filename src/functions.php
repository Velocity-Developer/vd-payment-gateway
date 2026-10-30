<?php

/**
 * API publik VD Payment Gateway untuk plugin/theme lain.
 * Selalu cek dulu dengan function_exists('vd_pg_create_payment').
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @return \VdPaymentGateway\Gateway\GatewayInterface|null */
function vd_pg_gateway($id)
{
    return \VdPaymentGateway\Plugin::instance()->registry()->get($id);
}

function vd_pg_is_available($id)
{
    $gateway = vd_pg_gateway($id);

    return $gateway !== null && $gateway->is_active();
}

/** @return array id => label untuk gateway yang aktif. */
function vd_pg_active_gateways()
{
    $list = [];
    foreach (\VdPaymentGateway\Plugin::instance()->registry()->active() as $id => $gateway) {
        $list[$id] = $gateway->label();
    }

    return $list;
}

/**
 * Buat tagihan. Contoh:
 *
 * vd_pg_create_payment('doku', [
 *     'invoice'    => 'INV-001',
 *     'amount'     => 150000,
 *     'source'     => 'vd-store',      // pemilik transaksi, dipakai di action vd_pg_payment_paid_{source}
 *     'ref_id'     => $order_id,
 *     'return_url' => home_url('/terima-kasih'),
 *     'customer'   => ['name' => 'Budi', 'email' => 'budi@contoh.com', 'phone' => '0812...'],
 *     'items'      => [['name' => 'Kaos', 'price' => 75000, 'qty' => 2]],
 * ]);
 *
 * @return array|\WP_Error [payment_url, token, reference, expires_at, transaction_id, raw]
 */
function vd_pg_create_payment($gateway_id, array $args)
{
    return (new \VdPaymentGateway\PaymentService())->create_payment($gateway_id, $args);
}

/** @return object|null Baris transaksi. */
function vd_pg_get_transaction($gateway_id, $invoice)
{
    return (new \VdPaymentGateway\Transaction\TransactionRepository())->find($gateway_id, $invoice);
}

/** Tanya status ke gateway dan perbarui transaksi. */
function vd_pg_sync_status($gateway_id, $invoice)
{
    return (new \VdPaymentGateway\PaymentService())->sync_status($gateway_id, $invoice);
}
