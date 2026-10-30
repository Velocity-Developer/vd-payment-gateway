<?php

namespace VdPaymentGateway;

use VdPaymentGateway\Transaction\TransactionRepository;

/**
 * Titik masuk yang dipakai plugin lain (vd-store, vd-marketplace, velocity-donasi, theme toko).
 */
class PaymentService
{
    private $transactions;

    public function __construct(?TransactionRepository $transactions = null)
    {
        $this->transactions = $transactions ?: new TransactionRepository();
    }

    public function create_payment($gateway_id, array $args)
    {
        $gateway = Plugin::instance()->registry()->get($gateway_id);
        if (!$gateway) {
            return new \WP_Error('vdpg_unknown_gateway', __('Gateway tidak dikenal.', 'vd-payment-gateway'));
        }

        $invoice = trim((string) ($args['invoice'] ?? ''));
        $existing = $invoice !== '' ? $this->transactions->find($gateway->id(), $invoice) : null;
        if ($existing && $existing->status === 'paid') {
            return new \WP_Error('vdpg_already_paid', __('Invoice ini sudah lunas.', 'vd-payment-gateway'));
        }

        $result = $gateway->create_payment($args);
        if (is_wp_error($result)) {
            return $result;
        }

        $saved = $this->transactions->upsert_pending($gateway->id(), $invoice, [
            'source' => sanitize_key((string) ($args['source'] ?? '')),
            'ref_id' => (string) ($args['ref_id'] ?? ''),
            'amount' => (int) round((float) ($args['amount'] ?? 0)),
            'reference' => $result['reference'] ?? '',
            'payment_url' => $result['payment_url'] ?? '',
            'request' => $result['request'] ?? null,
            'response' => $result['raw'] ?? null,
        ]);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $result['transaction_id'] = (int) $saved;
        unset($result['request']);

        return $result;
    }

    /**
     * Terapkan status ternormalisasi (dari notifikasi atau cek status) ke transaksi.
     * Aksi vd_pg_payment_* hanya dipicu sekali per perubahan status.
     */
    public function apply_status($gateway_id, array $normalized)
    {
        $transaction = $this->transactions->find($gateway_id, (string) ($normalized['invoice'] ?? ''));
        if (!$transaction) {
            return new \WP_Error('vdpg_unknown_invoice', 'Invoice tidak ditemukan', ['status' => 404]);
        }

        $this->transactions->save_notification($transaction->id, $normalized['raw'] ?? []);

        $status = (string) ($normalized['status'] ?? 'pending');
        if ($status === 'paid' && (int) $normalized['amount'] !== (int) $transaction->amount) {
            // Nominal tidak cocok: jangan tandai lunas, biarkan admin memeriksa.
            do_action('vd_pg_payment_amount_mismatch', $transaction, $normalized);

            return new \WP_Error('vdpg_amount_mismatch', 'Nominal tidak cocok', ['status' => 200]);
        }

        if ($status === 'pending') {
            return $transaction;
        }

        $changed = $this->transactions->transition($transaction->id, $status, [
            'channel' => (string) ($normalized['channel'] ?? ''),
        ]);
        if (!$changed) {
            return $transaction;
        }

        $transaction = $this->transactions->find_by_id($transaction->id);
        $payload = $normalized['raw'] ?? [];

        do_action('vd_pg_payment_status_changed', $transaction, $status, $payload);
        do_action("vd_pg_payment_{$status}", $transaction, $payload);
        if ($transaction->source !== '') {
            do_action("vd_pg_payment_{$status}_{$transaction->source}", $transaction, $payload);
        }

        return $transaction;
    }

    /** Tanya status langsung ke gateway lalu terapkan (untuk tombol "Cek status" / cron). */
    public function sync_status($gateway_id, $invoice)
    {
        $gateway = Plugin::instance()->registry()->get($gateway_id);
        if (!$gateway) {
            return new \WP_Error('vdpg_unknown_gateway', __('Gateway tidak dikenal.', 'vd-payment-gateway'));
        }

        $normalized = $gateway->check_status($invoice);
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        if ($normalized['invoice'] === '') {
            $normalized['invoice'] = (string) $invoice;
        }

        return $this->apply_status($gateway->id(), $normalized);
    }
}
