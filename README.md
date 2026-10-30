# VD Payment Gateway

Plugin payment gateway terpusat untuk plugin/theme Velocity Developer (vd-store, vd-marketplace, velocity-donasi, velocity-toko).
Gateway ditulis sekali di sini; plugin lain cukup memanggil API di bawah.

Gateway: **DOKU Checkout** (Non-SNAP) dan **Duitku Pop**.

## Pengaturan

Menu **Payment Gateway → DOKU** di wp-admin:

- Aktifkan, Mode (Sandbox/Production), Client ID, Secret Key, batas bayar (menit).
- Salin **Notification URL** (`/wp-json/vd-pg/v1/notify/doku`) ke DOKU Back Office → Integration → Notification.

Menu **Payment Gateway → Duitku**: Aktifkan, Mode, Kode Merchant, API Key, batas bayar, Return URL bawaan.
Callback URL dikirim otomatis per transaksi (`/wp-json/vd-pg/v1/notify/duitku`), tidak perlu diisi di dashboard Duitku.

Tab **Transaksi** menampilkan riwayat dan tombol **Cek status** (memanggil API cek status gateway).

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

## Kompatibilitas Duitku lama (velocity-addons < 2.3)

Duitku dihapus dari velocity-addons (commit `2a81d86`). Plugin ini menyediakan pengganti supaya vd-store, vd-marketplace,
dan velocity-donasi versi sekarang langsung bisa memakai Duitku lagi tanpa rilis ulang:

- Kelas `Velocity_Addons_Duitku` (`is_active()`, `options()`, `createInvoice($params)` → respons Duitku mentah, `get_by_invoice()`).
  Dimuat lewat autoloader dan **hanya bila kelas aslinya tidak ada**, jadi aman dipasang bersama addons lama.
- Shortcode `[tombol_bayar_duitku invoice="..."]` (Duitku Pop).
- Endpoint lama `/wp-json/velocityaddons/v1/duitku_callback` tetap menerima callback invoice lama.
- Action lama `velocity_duitku_callback($payload)` dipicu sekali per perubahan status (resultCode `00` = lunas).
- Saat aktivasi, pengaturan `velocity_duitku_options` dan riwayat tabel `vd_duitku_invoice`/`vd_duitku_callback` diimpor sekali
  (hasilnya di opsi `vdpg_duitku_imported`).

## Catatan Duitku

- Signature memakai HMAC-SHA256 (Duitku menyatakan MD5/SHA256 lama obsolete sejak Apr 2026). Callback dengan signature MD5 lama masih diterima.
- `itemDetails.price` = harga satuan × qty; totalnya disamakan dengan `paymentAmount` seperti pada DOKU.

## Catatan DOKU

- Signature request & notifikasi: HMAC-SHA256 atas `Client-Id`, `Request-Id`, `Request-Timestamp`, `Request-Target`, `Digest` (SHA-256 base64 body).
- Status `FAILED` di DOKU Checkout diabaikan (pelanggan bisa ganti metode bayar), sesuai anjuran DOKU.
- Status lunas/refund tidak bisa turun kembali ke pending/expired.
- Status lunas ditetapkan dari notifikasi yang signature-nya valid dan nominalnya cocok; Check Status API tidak dipanggil otomatis
  (DOKU menyarankan jeda 60 detik). Gunakan tombol **Cek status** atau `vd_pg_sync_status()` bila notifikasi tidak datang.
- Nominal notifikasi yang tidak cocok diberi catatan merah di tab Transaksi.
- Total `line_items` disamakan dengan `amount`: selisih (ongkir/biaya) jadi satu baris tambahan; bila ada diskon, `line_items` tidak dikirim.

## Log debug

Tab **Payment Gateway › Log** (mati secara bawaan, opsi `vdpg_debug_log`). Isinya per baris (JSON, waktu UTC):

- `doku.request` / `duitku.request`: permintaan ke API gateway, kode HTTP, waktu respons, dan isi responsnya.
- `notify.<gateway>`: notifikasi masuk (header, body, hasil cek signature, status akhir, kode HTTP balasan).
- `status.changed` / `status.amount_mismatch`: perubahan status transaksi atau nominal yang tidak cocok.

Secret key, signature, token, dan password disamarkan (4 karakter awal…4 akhir). Berkas harian
`wp-content/uploads/vdpg-logs/vdpg-YYYY-MM-DD-<hash>.log`, otomatis dihapus setelah 14 hari.
Nama berkas memakai hash acak per situs. Bila perlu, pindahkan foldernya ke luar folder publik lewat filter `vdpg_log_dir`.

## Pengujian

Skrip ujung-ke-ujung (mock DOKU & Duitku, aman dijalankan berulang) ada di luar repo pada server pengembangan:
`/root/vdpg-test/scripts/e2e.php` → `wp eval-file e2e.php`.
