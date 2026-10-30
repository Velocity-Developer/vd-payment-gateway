# VD Payment Gateway

Plugin payment gateway terpusat untuk plugin/theme Velocity Developer (vd-store, vd-marketplace, velocity-donasi, velocity-toko).
Gateway ditulis sekali di sini; plugin lain cukup memanggil API di bawah.

Gateway saat ini: **DOKU Checkout** (Non-SNAP). Duitku menyusul (tahap berikutnya).

## Pengaturan

Menu **Payment Gateway → DOKU** di wp-admin:

- Aktifkan, Mode (Sandbox/Production), Client ID, Secret Key, batas bayar (menit).
- Salin **Notification URL** (`/wp-json/vd-pg/v1/notify/doku`) ke DOKU Back Office → Integration → Notification.

Tab **Transaksi** menampilkan riwayat dan tombol **Cek status** (memanggil Check Status API DOKU).

## API untuk plugin lain

```php
if (function_exists('vd_pg_is_available') && vd_pg_is_available('doku')) {
    $result = vd_pg_create_payment('doku', [
        'invoice'    => 'INV-001',          // unik per gateway, maks 64 (30 bila kartu kredit aktif)
        'amount'     => 150000,             // rupiah, tanpa desimal
        'source'     => 'vd-store',         // pemilik transaksi
        'ref_id'     => $order_id,
        'return_url' => home_url('/terima-kasih'),
        'customer'   => ['name' => 'Budi', 'email' => 'budi@contoh.com', 'phone' => '0812...'],
        'items'      => [['name' => 'Kaos', 'price' => 75000, 'qty' => 2]],
    ]);
    if (!is_wp_error($result)) {
        wp_redirect($result['payment_url']);
    }
}
```

Fungsi lain: `vd_pg_gateway($id)`, `vd_pg_active_gateways()`, `vd_pg_get_transaction($gateway, $invoice)`, `vd_pg_sync_status($gateway, $invoice)`.

### Action saat status berubah

Dipicu **sekali** per perubahan status (notifikasi ganda diabaikan):

- `vd_pg_payment_paid` / `vd_pg_payment_paid_{source}` — `($transaction, $payload)`
- `vd_pg_payment_expired`, `vd_pg_payment_refunded` (dan varian `_{source}`)
- `vd_pg_payment_status_changed` — `($transaction, $status, $payload)`
- `vd_pg_payment_amount_mismatch` — nominal notifikasi ≠ nominal tagihan; transaksi **tidak** ditandai lunas.

Filter: `vd_pg_gateways` (daftarkan gateway baru, implementasi `GatewayInterface`), `vd_pg_doku_payment_body`.

## Catatan DOKU

- Signature request & notifikasi: HMAC-SHA256 atas `Client-Id`, `Request-Id`, `Request-Timestamp`, `Request-Target`, `Digest` (SHA-256 base64 body).
- Status `FAILED` di DOKU Checkout diabaikan (pelanggan bisa ganti metode bayar), sesuai anjuran DOKU.
- Status lunas/refund tidak bisa turun kembali ke pending/expired.
- Total `line_items` disamakan dengan `amount`: selisih (ongkir/biaya) jadi satu baris tambahan; bila ada diskon, `line_items` tidak dikirim.
