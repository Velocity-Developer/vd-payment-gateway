# Tes integrasi vd-payment-gateway

Tes ujung-ke-ujung untuk vd-payment-gateway dan plugin pemakainya (velocity-toko, velocity-donasi,
vd-store, vd-marketplace). API DOKU/Duitku di-mock (`pre_http_request`); notifikasi dikirim
bertanda tangan ke endpoint REST asli lewat server `php -S`. Semua kredensial di sini **palsu**.

Folder ini tidak ikut di zip rilis (`.gitattributes` export-ignore dan `.distignore`).

## Isi

| Suite | Menguji |
|---|---|
| `e2e.php` | vd-payment-gateway: DOKU & Duitku, notifikasi, idempoten, action lama, vd-store |
| `log-mask.php` | penyamaran rahasia & token URL di log |
| `duitku-amount.php` | callback Duitku tanpa/dengan nominal salah (endpoint & action lama velocity-toko) |
| `toko-e2e.php` | velocity-toko: total checkout di server, ongkir, DOKU, lacak, sinkron status |
| `donasi-e2e.php` | velocity-donasi: invoice server, DOKU, Duitku callback |
| `mp-e2e.php` | vd-marketplace + DOKU |

Suite plugin yang tidak terpasang akan gagal; jalankan hanya suite yang relevan.

## Persiapan (sekali)

1. WordPress uji dengan SQLite (plugin `sqlite-database-integration` + drop-in `db.php`) atau MySQL
   khusus uji. **Jangan pakai situs produksi**: tes membuat/menghapus pesanan, donasi, user, opsi.
2. Symlink plugin ke `wp-content/plugins/` (vd-payment-gateway, velocity-toko, velocity-donasi,
   vd-store, vd-marketplace).
3. Siapkan (siteurl `http://127.0.0.1:8791`, mu-plugin pencatat hook, aktifkan plugin):
   ```bash
   cd /path/wordpress && wp --allow-root eval-file /path/vd-payment-gateway/tests/install.php
   ```
   Bila WordPress belum terpasang: `php tests/install.php /path/wordpress`.

## Menjalankan

```bash
VDPG_TEST_WP=/path/wordpress tests/run-tests.sh              # semua suite
VDPG_TEST_WP=/path/wordpress tests/run-tests.sh duitku-amount e2e
```

- Butuh `php`, `curl`, `lsof`, dan WP-CLI (`wp` di PATH, atau `WP_CLI="php /path/wp-cli.phar"`).
- Port 8791 harus bebas; bila terpakai proses lain, skrip berhenti dengan kode 2.
- Server `php -S` (maks. 15 menit) dan proses `sendmail` turunannya dimatikan otomatis saat selesai,
  gagal, atau diinterupsi.
- Keluaran hanya baris `GAGAL`/`Fatal`/`Hasil`; kode keluar 0 bila semua suite `0 gagal`.
