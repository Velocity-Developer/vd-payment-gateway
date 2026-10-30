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

        // Dua percobaan: bila baris berubah/dibuat proses lain di antara find() dan tulis, baca ulang sekali.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $existing = $this->find($gateway, $invoice);
            if ($existing) {
                if ($existing->status === 'paid') {
                    return new \WP_Error('vdpg_already_paid', __('Invoice ini sudah lunas.', 'vd-payment-gateway'));
                }
                if (self::owned_by_other($existing, $row['source'], $row['ref_id'])) {
                    return self::invoice_taken_error();
                }
                // Tulis hanya bila pemilik & status baris masih sama dengan yang tadi dibaca (compare-and-set).
                $updated = $wpdb->update($this->table(), $row + ['status' => 'pending'], [
                    'id' => (int) $existing->id,
                    'source' => (string) $existing->source,
                    'ref_id' => (string) $existing->ref_id,
                    'status' => (string) $existing->status,
                ]);
                if ($updated) {
                    return (int) $existing->id;
                }
                // 0 baris bisa berarti nilainya sudah identik (MySQL menghitung baris yang berubah saja):
                // berhasil bila baris kini memang milik kita dan masih pending.
                $now_row = $updated === 0 ? $this->find($gateway, $invoice) : null;
                if ($now_row && (int) $now_row->id === (int) $existing->id && $now_row->status === 'pending'
                    && (string) $now_row->source === $row['source'] && (string) $now_row->ref_id === $row['ref_id']) {
                    return (int) $existing->id;
                }
                continue;
            }

            // UNIQUE (gateway, invoice): insert gagal bila proses lain baru saja membuat baris yang sama.
            $suppress = $wpdb->suppress_errors(true);
            $inserted = $wpdb->insert($this->table(), $row + [
                'gateway' => (string) $gateway,
                'invoice' => (string) $invoice,
                'status' => 'pending',
                'created_at' => $now,
            ]);
            $wpdb->suppress_errors($suppress);
            if ($inserted) {
                return (int) $wpdb->insert_id;
            }
            // Gagal bukan karena baris kembar (UNIQUE): jangan dilaporkan sebagai invoice dipakai.
            if (!$this->find($gateway, $invoice)) {
                return new \WP_Error('vdpg_db_error', __('Transaksi gagal disimpan. Silakan coba lagi.', 'vd-payment-gateway'), ['status' => 500]);
            }
        }

        return self::invoice_taken_error();
    }

    /**
     * Invoice sudah dipakai sumber/data lain (mis. pesanan vd-store vs donasi): jangan ditimpa, karena
     * notifikasi berikutnya akan diteruskan ke pemilik yang salah. Baris hasil impor Duitku lama boleh diambil alih.
     */
    public static function owned_by_other($existing, $source, $ref_id)
    {
        $source = sanitize_key((string) $source);
        if ((string) $existing->source === '' || $existing->source === 'legacy-import') {
            return false;
        }
        if ($existing->source !== $source) {
            return true;
        }

        return (string) $existing->ref_id !== '' && (string) $ref_id !== '' && (string) $existing->ref_id !== (string) $ref_id;
    }

    public static function invoice_taken_error()
    {
        return new \WP_Error('vdpg_invoice_taken', __('Nomor invoice ini sudah dipakai transaksi lain.', 'vd-payment-gateway'), ['status' => 409]);
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
