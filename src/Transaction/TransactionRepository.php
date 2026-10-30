<?php

namespace VdPaymentGateway\Transaction;

class TransactionRepository
{
    const STATUSES = ['pending', 'paid', 'failed', 'expired', 'refunded'];

    private function table()
    {
        return Installer::table();
    }

    public function find($gateway, $invoice)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE gateway = %s AND invoice = %s",
            (string) $gateway,
            (string) $invoice
        ));
    }

    public function find_by_id($id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", (int) $id));
    }

    /**
     * Simpan tagihan baru, atau perbarui tagihan yang belum lunas dengan invoice sama
     * (mis. pelanggan meminta link bayar ulang).
     */
    public function upsert_pending($gateway, $invoice, array $data)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $row = [
            'source' => (string) ($data['source'] ?? ''),
            'ref_id' => (string) ($data['ref_id'] ?? ''),
            'amount' => (int) ($data['amount'] ?? 0),
            'reference' => (string) ($data['reference'] ?? ''),
            'payment_url' => (string) ($data['payment_url'] ?? ''),
            'request_payload' => isset($data['request']) ? wp_json_encode($data['request']) : null,
            'response_payload' => isset($data['response']) ? wp_json_encode($data['response']) : null,
            'note' => '',
            'updated_at' => $now,
        ];

        $existing = $this->find($gateway, $invoice);
        if ($existing) {
            if ($existing->status === 'paid') {
                return new \WP_Error('vdpg_already_paid', __('Invoice ini sudah lunas.', 'vd-payment-gateway'));
            }
            $row['status'] = 'pending';
            $wpdb->update($this->table(), $row, ['id' => (int) $existing->id]);

            return (int) $existing->id;
        }

        $row['gateway'] = (string) $gateway;
        $row['invoice'] = (string) $invoice;
        $row['status'] = 'pending';
        $row['created_at'] = $now;
        $wpdb->insert($this->table(), $row);

        return (int) $wpdb->insert_id;
    }

    /**
     * Ubah status secara atomik. Mengembalikan true hanya bila baris benar-benar berubah,
     * sehingga notifikasi ganda tidak memicu aksi dua kali.
     */
    public function transition($id, $status, array $extra = [])
    {
        global $wpdb;

        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        $now = current_time('mysql', true);
        $sets = ['status = %s', 'updated_at = %s'];
        $values = [$status, $now];

        if ($status === 'paid') {
            $sets[] = 'paid_at = %s';
            $values[] = $now;
        }
        foreach (['reference', 'channel', 'notification_payload'] as $column) {
            if (isset($extra[$column]) && $extra[$column] !== '') {
                $sets[] = "$column = %s";
                $values[] = is_string($extra[$column]) ? $extra[$column] : wp_json_encode($extra[$column]);
            }
        }

        // Status final (paid/refunded) tidak boleh turun kembali ke pending/failed/expired.
        $guard = $status === 'refunded' ? "status = 'paid'" : "status NOT IN ('paid','refunded')";

        $values[] = (int) $id;
        $values[] = $status;
        $sql = "UPDATE {$this->table()} SET " . implode(', ', $sets) . " WHERE id = %d AND status <> %s AND $guard";

        $affected = $wpdb->query($wpdb->prepare($sql, $values));

        return $affected === 1;
    }

    public function set_note($id, $note)
    {
        global $wpdb;

        $wpdb->update($this->table(), [
            'note' => mb_substr((string) $note, 0, 255),
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $id]);
    }

    public function save_notification($id, $payload)
    {
        global $wpdb;

        $wpdb->update($this->table(), [
            'notification_payload' => is_string($payload) ? $payload : wp_json_encode($payload),
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $id]);
    }

    public function latest($limit = 50, $offset = 0, $gateway = '')
    {
        global $wpdb;

        if ($gateway !== '') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE gateway = %s ORDER BY id DESC LIMIT %d OFFSET %d",
                $gateway,
                (int) $limit,
                (int) $offset
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ));
    }
}
