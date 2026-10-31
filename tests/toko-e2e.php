<?php
/**
 * Tes velocity-toko: hitung ulang total checkout di server (+ DOKU bila tersedia).
 * Jalankan lewat tests/run-tests.sh (lihat tests/README.md).
 */
global $wpdb;
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function tcheck($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
$NEW = function_exists('velocitytoko_checkout_server_total');
// velocity-toko 1.3.5 (hotfix) belum punya velocitytoko_parse_ongkir: pakai format lama "kode - layanan - ongkir".
$ONGKIR_PARSER = function_exists('velocitytoko_parse_ongkir');
$ongkir_of = function ($v) use ($ONGKIR_PARSER) { if ($ONGKIR_PARSER) { return velocitytoko_parse_ongkir($v)['ongkir']; } $x = explode('-', $v); return (int) preg_replace('/\D+/', '', end($x)); };
echo $NEW ? "(kode baru)\n" : "(kode lama: tanpa hitung ulang server)\n";

if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
add_filter('wp_die_handler', function () { return function () { throw new Exception('wp_die'); }; });
add_filter('wp_die_ajax_handler', function () { return function () { throw new Exception('wp_die'); }; });
$GLOBALS['mails'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['mails'][] = $atts; return true; }, 10, 2);
$GLOBALS['ongkir_fail'] = false; $GLOBALS['ongkir_calls'] = 0;
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (preg_match('#ongkir\.velocitydeveloper\.id/api/v1/(province|city)$#', $url)) {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => '{"rajaongkir":{"results":[{"province_id":"1","province":"Uji"}]}}'];
    }
    if (strpos($url, 'ongkir.velocitydeveloper.id/api/v1/subdistrict?') !== false) {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => '{"rajaongkir":{"results":[]}}'];
    }
    if (strpos($url, 'ongkir.velocitydeveloper.id/api/v1/cost') !== false) {
        $GLOBALS['ongkir_calls']++;
        if ((string) $args['body']['destination'] === '999999') { return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => '{"rajaongkir":{"status":{"code":400,"description":"Invalid destination"}}}']; }
        if ($GLOBALS['ongkir_fail']) { return new WP_Error('http_request_failed', 'timeout (mock)'); }
        $w = (int) $args['body']['weight'];
        $reg = 18000 * max(1, (int) ceil($w / 1000)); // tarif per kg
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => wp_json_encode(['rajaongkir' => [
            'origin_details' => ['type' => 'Kota', 'city_name' => 'Uji', 'province' => 'Uji'],
            'results' => [['code' => 'jne', 'name' => 'JNE', 'costs' => [
                ['service' => 'REG', 'description' => 'Reguler', 'cost' => [['value' => $reg, 'etd' => '2-3']]],
                ['service' => 'YES', 'description' => 'Yakin', 'cost' => [['value' => $reg + 12000, 'etd' => '1']]],
                ['service' => 'REG-23', 'description' => 'Reguler 23', 'cost' => [['value' => $reg + 5000, 'etd' => '2-3']]],
                ['service' => 'YES-1-DAY', 'description' => 'Yakin 1 hari', 'cost' => [['value' => $reg + 20000, 'etd' => '1']]],
            ]]]]])];
    }
    if (strpos($url, 'ongkir.velocitydeveloper.id/api/v1/waybill') !== false) {
        $sum = ['courier_name' => 'JNE', 'waybill_number' => 'RESI1', 'service_code' => 'REG', 'waybill_date' => '2026-10-30', 'status' => 'DELIVERED',
            'shipper_name' => 'Toko', 'origin' => 'Uji', 'receiver_name' => 'Rina Pembeli', 'destination' => 'Kota Uji'];
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => wp_json_encode(['rajaongkir' => ['status' => ['description' => 'OK'], 'result' => [
            'summary' => $sum, 'details' => ['waybill_time' => '10:00', 'weight' => '1000'],
            'manifest' => [['manifest_date' => '2026-10-30', 'manifest_time' => '11:00', 'manifest_description' => '<img src=x onerror=alert(2)>Diterima']]]]])];
    }
    if (strpos($url, 'ongkir.velocitydeveloper.id') !== false || strpos($url, 'zenziva') !== false) {
        return new WP_Error('http_request_failed', 'tidak dipakai di tes');
    }
    if (strpos($url, 'duitku.com') !== false) {
        $GLOBALS['duitku_calls'] = ($GLOBALS['duitku_calls'] ?? 0) + 1;
        return new WP_Error('http_request_failed', 'duitku mock mati');
    }
    return $pre;
}, 10, 3);

// --- Data uji: lokasi, produk, kupon, pengaturan ---
$tc = $wpdb->prefix . 'vd_city'; $ts = $wpdb->prefix . 'vd_subdistricts';
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $tc") < 501) {
    for ($i = 1; $i <= 501; $i++) { $wpdb->insert($tc, ['city_id' => $i, 'province_id' => 1, 'province' => 'Uji', 'type' => 'Kota', 'city_name' => "Kota $i", 'postal_code' => 10000 + $i]); }
}
if (!$wpdb->get_var("SELECT COUNT(*) FROM $ts WHERE subdistrict_id = 1001")) {
    $wpdb->insert($ts, ['subdistrict_id' => 1001, 'city_id' => 77, 'province_id' => 1, 'province' => 'Uji', 'type' => 'Kota', 'city' => 'Kota 77', 'subdistrict_name' => 'Kec Uji']);
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%getongkir\\_%'");
foreach (['ongkir' => '1', 'courier' => ['jne'], 'city_origin' => 501, 'kodeunik' => '0', 'kodeunik_min' => 1, 'kodeunik_max' => 999, 'smspembeli' => 'off', 'smspenjual' => 'off', 'cod' => [], 'emailadmin' => 'admin@toko.test', 'pesancheckout' => 'Terima kasih [nama-customer]', 'buyeremail' => 'Total: [total-order] [detail-pesanan]'] as $k => $v) { set_theme_mod($k, $v); }

$mk = function ($title, $meta) { $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => $title]); foreach ($meta as $k => $v) { update_post_meta($id, $k, $v); } return $id; };
$p1 = $mk('Kaos Uji', ['harga' => '100000', 'harga_promo' => '90000', 'berat' => '1']);
$p2 = $mk('Jaket Uji', ['harga' => '50000', 'berat' => '0.5', 'namaopsi2' => 'Ukuran', 'opsiharga' => ['S=50000', 'XL=75000']]);
$tk = $wpdb->prefix . 'kupon';
$wpdb->delete($tk, ['kode' => 'HEMAT10']); $wpdb->delete($tk, ['kode' => 'LAMA']);
$wpdb->insert($tk, ['kode' => 'HEMAT10', 'detail' => wp_json_encode(['kode' => 'HEMAT10', 'jenis' => 'percent', 'potongan' => '10', 'minorder' => '100000', 'tanggalend' => '31 Desember 2030', 'status' => 'on', 'jumlah' => 5])]);
$wpdb->insert($tk, ['kode' => 'LAMA', 'detail' => wp_json_encode(['kode' => 'LAMA', 'jenis' => 'rupiah', 'potongan' => '20000', 'minorder' => '0', 'tanggalend' => '1 Januari 2020', 'status' => 'on', 'jumlah' => 5])]);
$kupon_sesi = function ($kode) use ($wpdb, $tk) { return ['pakaikupon' => 'yes', 'detail' => $wpdb->get_var($wpdb->prepare("SELECT detail FROM $tk WHERE kode = %s", $kode))]; };

$cart_default = [
    "k1" => ['id' => 'k1', 'jumlah' => 2, 'detail' => wp_json_encode([$p1, null, null])],
    "k2" => ['id' => 'k2', 'jumlah' => 1, 'detail' => wp_json_encode([$p2, null, ['Ukuran', 'XL', '75000']])],
];
// Isian form seperti yang dibuat checkout.php + checkout.js untuk pembeli jujur.
$honest = function (array $o = []) use ($p1, $p2, $ongkir_of) {
    $sub = 2 * 90000 + 75000;
    $kupon = $o['kupon'] ?? ['kode' => '', 'potongan' => '', 'jenis' => ''];
    if ($kupon['jenis'] === 'percent') { $sub = $sub - $sub * $kupon['potongan'] / 100; }
    if ($kupon['jenis'] === 'rupiah') { $sub = $sub - $kupon['potongan']; }
    $ongkir = $o['ongkir'] ?? 'JNE - REG - 54000';
    $ku = $o['kodeunik'] ?? null;
    $total = $sub + $ongkir_of($ongkir) + (int) $ku;
    $post = [
        'nama' => 'Rina Pembeli', 'email' => 'rina@example.com', 'hp' => '081233334444', 'alamat' => 'Jl. Uji 1', 'kodepos' => '12345',
        'pengiriman' => 'no', 'subdistrict_destination' => '1001', 'city_destination' => '77', 'prov_destination' => '1',
        'pembayaran' => $o['pembayaran'] ?? 'bank', 'ongkir' => $ongkir, 'invoice' => '123456789',
        'produk' => [
            'products' => [
                ['id' => $p1, 'nama' => 'Kaos Uji', 'img' => '', 'harga' => '90000', 'hargaasli' => '100000', 'jumlah' => '2', 'berat' => '1'],
                ['id' => $p2, 'nama' => 'Jaket Uji', 'img' => '', 'harga' => '75000', 'hargaasli' => '', 'jumlah' => '1', 'berat' => '0.5', 'isiopsi' => ',Ukuran: XL'],
            ],
            'potongan-ongkir' => '0', 'subhargatotal' => (string) $sub, 'qty' => '3', 'hargatotal' => (string) $total, 'berattotal' => '2500',
            'kupon' => $kupon,
        ],
    ];
    if ($ku !== null) { $post['kodeunik'] = (string) $ku; }
    return $post;
};
// Proses checkout: tampilkan ongkir (ajax, mengisi cache sesi), lalu finish.php.
$run = function (array $post, array $o = []) use ($wpdb, $cart_default) {
    wp_set_current_user($o['user'] ?? 0);
    $_SESSION['keranjang'] = $o['cart'] ?? $cart_default;
    if (!empty($o['user'])) {
        $wpdb->delete($wpdb->prefix . 'keranjang', ['id_pembeli' => $o['user']]);
        foreach ($o['cart'] ?? $cart_default as $c) { $wpdb->insert($wpdb->prefix . 'keranjang', ['id_pembeli' => $o['user'], 'id_produk' => $c['id'], 'jumlah' => $c['jumlah'], 'detail' => $c['detail']]); }
        unset($_SESSION['keranjang']);
    }
    $_SESSION['kupon'] = $o['kupon'] ?? '';
    $_SESSION['token'] = 'tok' . wp_rand(); $_SESSION['velocitytoko_captcha_code'] = 'ABCD';
    if (empty($o['skip_ajax'])) {
        $_POST = ['destination' => $o['ajax_dest'] ?? '1001', 'berattotal' => '2500', 'potongongkir' => $o['potongongkir'] ?? '0'];
        if (!empty($o['ajax_fail'])) { $GLOBALS['ongkir_fail'] = true; }
        ob_start(); try { ongkir_ajax(); } catch (Exception $e) {} $GLOBALS['ongkir_html'] = ob_get_clean();
    }
    if (!empty($o['before_finish'])) { ($o['before_finish'])(); }
    $_POST = $post; $_POST['token'] = $_SESSION['token']; $_POST['velocitytoko_captcha_input'] = 'ABCD';
    $GLOBALS['mails'] = [];
    $max = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}order");
    ob_start(); try { (function () { include VELOCITY_TOKO_PLUGIN_DIR . 'inc/store/finish.php'; })(); } catch (Exception $e) { echo 'EXC:' . $e->getMessage(); }
    $html = ob_get_clean();
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}order WHERE id > %d ORDER BY id DESC LIMIT 1", $max));
    return [$html, $order];
};
$rejected = function ($r) { return !$r[1] && stripos($r[0], 'muat ulang keranjang') !== false; };

echo "== Checkout jujur tetap lolos\n";
$r = $run($honest());
tcheck('bank tanpa kupon: pesanan dibuat, total 309.000', $r[1] && (int) $r[1]->total === 309000, $r[1] ? $r[1]->total : strip_tags($r[0]));
if ($NEW) { tcheck('dropdown ongkir dari ajax tetap sama', strpos($GLOBALS['ongkir_html'], 'value="JNE - REG - 54000"') !== false && strpos($GLOBALS['ongkir_html'], 'JNE - YES: Rp 66,000') !== false, $GLOBALS['ongkir_html']); }
$r = $run($honest(['kupon' => ['kode' => 'HEMAT10', 'potongan' => '10', 'jenis' => 'percent']]), ['kupon' => $kupon_sesi('HEMAT10')]);
tcheck('kupon 10%: total 229.500 + 54.000 = 283.500', $r[1] && (int) $r[1]->total === 283500, $r[1] ? $r[1]->total : strip_tags($r[0]));
set_theme_mod('kodeunik', '1');
$r = $run($honest(['kodeunik' => 123, 'pembayaran' => 'duitku']));
tcheck('kode unik 123 + Duitku: total 309.123', $r[1] && (int) $r[1]->total === 309123, $r[1] ? $r[1]->total : strip_tags($r[0]));
set_theme_mod('kodeunik', '0');
$r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - YES - 66000']));
tcheck('Duitku dengan ongkir YES: total 321.000', $r[1] && (int) $r[1]->total === 321000, $r[1] ? $r[1]->total : strip_tags($r[0]));
tcheck('email pembeli memuat total server', $r[1] && (bool) array_filter($GLOBALS['mails'], function ($m) { return strpos($m['message'], '321.000') !== false; }));

if ($ONGKIR_PARSER) {
echo "== Nama layanan ongkir mengandung \"-\"\n";
$po = function ($v) { $x = velocitytoko_parse_ongkir($v); return $x['kode'] . '|' . $x['layanan'] . '|' . $x['ongkir'] . ($x['valid'] ? '' : '|x'); };
foreach (['JNE - REG-23 - 59000' => 'JNE|REG-23|59000', 'JNE - YES-1-DAY - 74000' => 'JNE|YES-1-DAY|74000', 'JNE - REG - 54000' => 'JNE|REG|54000',
    'COD -  - 0' => 'COD||0', 'No - 0 - 0' => 'No|0|0', 'jne - REG - 18.000' => 'jne|REG|18000', 'jne-REG-18000' => 'jne|REG|18000', 'sampah' => 'sampah||0|x', 'jne - REG-23' => 'jne|REG|23', 'jne - REG - -5000' => 'jne - REG - -5000||0|x'] as $in => $want) {
    tcheck("urai ongkir \"$in\" → $want", $po($in) === $want, $po($in));
}
if ($NEW) { tcheck('dropdown ongkir memuat layanan REG-23 dan YES-1-DAY utuh', strpos($GLOBALS['ongkir_html'], 'value="JNE - REG-23 - 59000"') !== false && strpos($GLOBALS['ongkir_html'], 'value="JNE - YES-1-DAY - 74000"') !== false, $GLOBALS['ongkir_html']); }
$r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - REG-23 - 59000']));
tcheck('checkout ongkir REG-23: total 314.000, kurir tersimpan utuh', $r[1] && (int) $r[1]->total === 314000 && $r[1]->kurir === 'JNE - REG-23 - 59000', $r[1] ? $r[1]->total . ' ' . $r[1]->kurir : strip_tags($r[0]));
tcheck('email pembeli menampilkan ongkir "JNE - REG-23 - 59.000"', (bool) array_filter($GLOBALS['mails'], function ($m) { return strpos($m['message'], 'JNE - REG-23 - ') !== false && strpos($m['message'], '59.000') !== false; }));
$r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - YES-1-DAY - 74000']));
tcheck('checkout ongkir YES-1-DAY: total 329.000', $r[1] && (int) $r[1]->total === 329000 && $r[1]->kurir === 'JNE - YES-1-DAY - 74000', $r[1] ? $r[1]->total . ' ' . $r[1]->kurir : strip_tags($r[0]));
$p = $honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - YES-1-DAY - 1000']); $p['produk']['hargatotal'] = (string) (255000 + 1000);
tcheck('ongkir YES-1-DAY dimurahkan → ditolak', $rejected($run($p)));
}

$wpdb->delete($tk, ['kode' => 'ONGKIR10']);
$wpdb->insert($tk, ['kode' => 'ONGKIR10', 'detail' => wp_json_encode(['kode' => 'ONGKIR10', 'jenis' => 'ongkir', 'potongan' => '10000', 'minorder' => '0', 'tanggalend' => '31 Desember 2030', 'status' => 'on', 'jumlah' => 5])]);
$r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - REG - 44000', 'kupon' => ['kode' => 'ONGKIR10', 'potongan' => '10000', 'jenis' => 'ongkir']]), ['kupon' => $kupon_sesi('ONGKIR10'), 'potongongkir' => '10000']);
tcheck('kupon potongan ongkir 10.000 + Duitku: total 255.000 + 44.000', $r[1] && (int) $r[1]->total === 299000, $r[1] ? $r[1]->total : strip_tags($r[0]));
$wpdb->delete($tk, ['kode' => 'ONGKIR10']);
$uid = username_exists('pembeli-uji') ?: wp_create_user('pembeli-uji', wp_generate_password(), 'pembeli-uji@example.com');
$r = $run($honest(['pembayaran' => 'duitku']), ['user' => $uid]);
tcheck('pembeli login (keranjang di database) + Duitku: total 309.000', $r[1] && (int) $r[1]->total === 309000, $r[1] ? $r[1]->total : strip_tags($r[0]));
$p = $honest(['pembayaran' => 'duitku']); $p['produk']['hargatotal'] = '10000';
$r = $run($p, ['user' => $uid]);
tcheck('pembeli login, hargatotal diubah → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
$wpdb->delete($wpdb->prefix . 'keranjang', ['id_pembeli' => $uid]);
require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid);

echo "== Isian form diubah pembeli: Duitku ditolak\n";
$p = $honest(['pembayaran' => 'duitku']); $p['produk']['hargatotal'] = '10000';
$r = $run($p);
tcheck('hargatotal diubah → ditolak, pesanan tidak dibuat', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : strip_tags($r[0]));
tcheck('pesan ramah "Harga atau ongkos kirim berubah"', strpos($r[0], 'Harga atau ongkos kirim berubah') !== false);
tcheck('keranjang tidak dikosongkan saat ditolak', !empty($_SESSION['keranjang']));
$p = $honest(['pembayaran' => 'duitku']); $p['produk']['products'][0]['harga'] = '1000'; $p['produk']['hargatotal'] = (string) (2 * 1000 + 75000 + 54000);
$r = $run($p);
tcheck('harga produk diubah (total ikut disesuaikan) → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
$p = $honest(['pembayaran' => 'duitku', 'kupon' => ['kode' => 'HEMAT10', 'potongan' => '90', 'jenis' => 'percent']]);
$r = $run($p, ['kupon' => $kupon_sesi('HEMAT10')]);
tcheck('kupon[potongan] 10% → 90% → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
$p = $honest(['pembayaran' => 'duitku', 'kupon' => ['kode' => 'HEMAT10', 'potongan' => '10', 'jenis' => 'percent']]);
$r = $run($p);
tcheck('kupon dikirim tanpa kupon di sesi → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
$p = $honest(['pembayaran' => 'duitku', 'kupon' => ['kode' => 'LAMA', 'potongan' => '20000', 'jenis' => 'rupiah']]);
$r = $run($p, ['kupon' => $kupon_sesi('LAMA')]);
tcheck('kupon kedaluwarsa di database → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
$r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => 'JNE - REG - 1000']));
tcheck('ongkir diubah → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
set_theme_mod('kodeunik', '1');
$r = $run($honest(['pembayaran' => 'duitku', 'kodeunik' => -50000]));
tcheck('kode unik di luar rentang (-50.000) → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
set_theme_mod('kodeunik', '0');
$cart_cheat = ['k1' => ['id' => 'k1', 'jumlah' => 2, 'detail' => wp_json_encode([$p1, null, null])], 'k2' => ['id' => 'k2', 'jumlah' => 1, 'detail' => wp_json_encode([$p2, null, ['Ukuran', 'XL', '1000']])]];
$p = $honest(['pembayaran' => 'duitku']); $p['produk']['products'][1]['harga'] = '1000'; $p['produk']['hargatotal'] = (string) (180000 + 1000 + 54000);
$r = $run($p, ['cart' => $cart_cheat]);
tcheck('harga opsi diubah saat tambah keranjang (XL=1000) → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');

echo "== Transfer bank: dicatat, total pesanan = hitungan server\n";
$p = $honest(['pembayaran' => 'bank']); $p['produk']['hargatotal'] = '10000'; $p['produk']['products'][0]['harga'] = '1000';
$r = $run($p);
tcheck('bank dengan total diubah: pesanan dibuat dengan total server 309.000', $r[1] && (int) $r[1]->total === 309000, $r[1] ? $r[1]->total : strip_tags($r[0]));
$d = $r[1] ? json_decode($r[1]->detail, true) : [];
tcheck('detail pesanan menyimpan harga server, bukan isian form', ($d['produk']['products'][0]['harga'] ?? null) == 90000 && ($d['produk']['hargatotal'] ?? null) == 309000, wp_json_encode($d['produk']['products'][0] ?? []));

echo "== API ongkir gagal saat checkout diproses\n";
$clear = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%getongkir\\_%'"); wp_cache_flush(); };
$clear();
$r = $run($honest(['pembayaran' => 'duitku']), ['before_finish' => function () use ($clear) { $clear(); $GLOBALS['ongkir_fail'] = true; }]);
$GLOBALS['ongkir_fail'] = false;
tcheck('Duitku: ongkir dari cache sesi saat tampil → lolos', $r[1] && (int) $r[1]->total === 309000, $r[1] ? $r[1]->total : strip_tags($r[0]));
if ($NEW) {
    $clear(); unset($_SESSION['velocitytoko_ongkir']);
    $GLOBALS['ongkir_fail'] = true;
    $r = $run($honest(['pembayaran' => 'duitku']), ['skip_ajax' => true]);
    tcheck('Duitku: API gagal & tanpa cache → ditolak dengan pesan ongkir', $rejected($r) && strpos($r[0], 'Ongkos kirim belum bisa diperiksa') !== false, strip_tags($r[0]));
    $r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => '0 - Ongkir tidak tersedia - 0']), ['skip_ajax' => true]);
    tcheck('Duitku: "Ongkir tidak tersedia - 0" saat API gagal tanpa cache → ditolak', $rejected($r));
    $r = $run($honest(['pembayaran' => 'bank']), ['skip_ajax' => true]);
    tcheck('bank: API gagal & tanpa cache → pesanan tetap dibuat (dicatat)', $r[1] && (int) $r[1]->total === 309000, $r[1] ? $r[1]->total : strip_tags($r[0]));
    $GLOBALS['ongkir_fail'] = false;
    $clear();
    // Temuan Haibara: cadangan "Ongkir tidak tersedia - 0" tidak boleh jadi bukti ongkir dari sesi.
    $p = $honest(['pembayaran' => 'duitku', 'ongkir' => '0 - Ongkir tidak tersedia - 0']); $p['subdistrict_destination'] = '999999';
    $r = $run($p, ['ajax_dest' => '999999']);
    tcheck('Duitku: tujuan tidak valid (API error) + "Ongkir tidak tersedia - 0" → ditolak', $rejected($r), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : strip_tags($r[0]));
    $clear(); unset($_SESSION['velocitytoko_ongkir']);
    $r = $run($honest(['pembayaran' => 'duitku', 'ongkir' => '0 - Ongkir tidak tersedia - 0']), ['before_finish' => function () {}, 'ajax_fail' => true]);
    tcheck('Duitku: "Ongkir tidak tersedia" ditampilkan saat API gagal tidak disimpan ke sesi → ditolak', $rejected($r) && empty($_SESSION['velocitytoko_ongkir']), $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
    $GLOBALS['ongkir_fail'] = false; $clear();
    $r = $run($honest(['pembayaran' => 'duitku']), ['before_finish' => function () use ($clear) {
        $clear(); $GLOBALS['ongkir_fail'] = true;
        foreach ($_SESSION['velocitytoko_ongkir'] as $k => $v) { $_SESSION['velocitytoko_ongkir'][$k]['time'] = time() - 31 * MINUTE_IN_SECONDS; }
    }]);
    tcheck('Duitku: cache sesi ongkir lebih dari 30 menit → ditolak', $rejected($r) && strpos($r[0], 'Ongkos kirim belum bisa diperiksa') !== false, $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');
    $GLOBALS['ongkir_fail'] = false; $clear();

    echo "== Harga opsi diubah admin setelah masuk keranjang\n";
    update_post_meta($p2, 'opsiharga', ['S=50000', 'XL=80000']);
    tcheck('harga opsi keranjang memakai meta terbaru (XL=80.000)', function_exists('velocitytoko_cart_option_price') && velocitytoko_cart_option_price($p2, [$p2, null, ['Ukuran', 'XL', '75000']]) === 80000);
    $_SESSION['keranjang'] = $cart_default; wp_set_current_user(0);
    ob_start(); try { include VELOCITY_TOKO_PLUGIN_DIR . 'inc/store/keranjang.php'; } catch (Throwable $e) { echo 'EXC:' . $e->getMessage(); } $cartsc = ob_get_clean(); file_put_contents('/tmp/cartsc.html', $cartsc);
    ob_start(); try { include VELOCITY_TOKO_PLUGIN_DIR . 'inc/store/checkout.php'; } catch (Throwable $e) { echo 'EXC:' . $e->getMessage(); } $ck = ob_get_clean(); file_put_contents('/tmp/ck.html', $ck);
    tcheck('halaman checkout memakai harga opsi terbaru', (bool) preg_match('/name="produk\[products\]\[\d+\]\[harga\]" value="80000"/', $ck) && !preg_match('/value="75000"/', $ck), strpos($ck, 'EXC:') !== false ? substr($ck, strpos($ck, 'EXC:'), 120) : '');
    tcheck('halaman keranjang menampilkan harga opsi terbaru', strpos($cartsc, '80.000') !== false && strpos($cartsc, '75.000') === false, substr(trim(strip_tags($cartsc)), 0, 200));
    $p = $honest(['pembayaran' => 'duitku']); $p['produk']['products'][1]['harga'] = '80000'; $p['produk']['subhargatotal'] = '260000'; $p['produk']['hargatotal'] = '314000';
    $r = $run($p);
    tcheck('form jujur dengan harga opsi terbaru + Duitku → lolos, total 314.000', $r[1] && (int) $r[1]->total === 314000, $r[1] ? $r[1]->total : strip_tags($r[0]));
    update_post_meta($p2, 'opsiharga', ['S=50000', 'XL=75000']);
}


if (function_exists('velocitytoko_pg_create_payment')) {
    echo "== DOKU lewat vd-payment-gateway (mock DOKU, notifikasi bertanda tangan ke server 8791)\n";
    $dsecret = 'SK-test-123'; $dclient = 'BRN-0001-TEST';
    $dokuopt0 = get_option('vdpg_doku');
    update_option('vdpg_doku', ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => $dclient, 'secret_key' => $dsecret, 'payment_due' => 60]);
    $GLOBALS['cap'] = ['n' => 0];
    add_filter('pre_http_request', function ($pre, $args, $url) {
        if (strpos($url, 'api-sandbox.doku.com') === false) return $pre;
        if (preg_match('#/orders/v1/status/([^/?]+)#', $url, $mm)) {
            $GLOBALS['cap']['status'] = ($GLOBALS['cap']['status'] ?? 0) + 1;
            $st = $GLOBALS['dstatus'][rawurldecode($mm[1])] ?? ['PENDING', 0];
            return ['response' => ['code' => 200], 'headers' => [], 'cookies' => [], 'body' => json_encode(['order' => ['invoice_number' => rawurldecode($mm[1]), 'amount' => $st[1], 'status' => 'ORDER_GENERATED'], 'transaction' => ['status' => $st[0]]])];
        }
        $b = json_decode((string) $args['body'], true); $GLOBALS['cap']['doku'] = $b; $GLOBALS['cap']['n']++;
        $exp = (new DateTime('+1 hour', new DateTimeZone('Asia/Jakarta')))->format('YmdHis');
        $tok = 'tok-' . $b['order']['invoice_number'] . '-' . $GLOBALS['cap']['n'];
        return ['response' => ['code' => 200], 'headers' => [], 'cookies' => [], 'body' => json_encode(['message' => ['SUCCESS'], 'response' => [
            'order' => ['amount' => $b['order']['amount'], 'invoice_number' => $b['order']['invoice_number'], 'session_id' => 'sess-toko'],
            'payment' => ['token_id' => $tok, 'url' => 'https://sandbox.doku.com/checkout-link-v2/' . $tok, 'expired_date' => $exp]]])];
    }, 5, 3);
    $dn = function ($invoice, $amount, $status = 'SUCCESS') use ($dclient, $dsecret) {
        $body = json_encode(['order' => ['invoice_number' => $invoice, 'amount' => $amount], 'transaction' => ['status' => $status], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BRI']]);
        $rid = wp_generate_uuid4(); $ts = \VdPaymentGateway\Gateway\Doku\DokuSignature::timestamp();
        $sig = \VdPaymentGateway\Gateway\Doku\DokuSignature::generate($dclient, $rid, $ts, '/wp-json/vd-pg/v1/notify/doku', $body, $dsecret);
        $r = wp_remote_post(rest_url('vd-pg/v1/notify/doku'), ['timeout' => 30, 'body' => $body, 'headers' => ['Client-Id' => $dclient, 'Request-Id' => $rid, 'Request-Timestamp' => $ts, 'Signature' => $sig, 'Content-Type' => 'application/json']]);
        return is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
    };
    $order_row = function ($id) use ($wpdb) { return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}order WHERE id = %d", $id)); };
    $lacak = function ($invoice) { return do_shortcode('[vtoko-lacak invoice="' . $invoice . '"]'); };

    // Pilihan metode di checkout (Duitku aktif & nonaktif).
    $render_ck = function () use ($cart_default) {
        $_SESSION['keranjang'] = $cart_default; wp_set_current_user(0);
        ob_start(); try { include VELOCITY_TOKO_PLUGIN_DIR . 'inc/store/checkout.php'; } catch (Throwable $e) { echo 'EXC:' . $e->getMessage(); } return ob_get_clean();
    };
    $duitkuopt0 = get_option('vdpg_duitku');
    update_option('vdpg_duitku', array_merge((array) $duitkuopt0, ['enabled' => '0']));
    $ck = $render_ck();
    tcheck('Duitku nonaktif: checkout menampilkan Bank dan DOKU', strpos($ck, '<option value="doku">DOKU</option>') !== false && strpos($ck, 'value="bank"') !== false);
    update_option('vdpg_duitku', $duitkuopt0);
    $ck = $render_ck();
    tcheck('Duitku aktif: pilihan Duitku dan DOKU', strpos($ck, '<option value="duitku">Duitku</option>') !== false && strpos($ck, '<option value="doku">DOKU</option>') !== false, class_exists('Velocity_Addons_Duitku') && Velocity_Addons_Duitku::is_active() ? 'duitku aktif' : 'duitku nonaktif');
    update_option('vdpg_doku', array_merge(get_option('vdpg_doku'), ['enabled' => '0']));
    $ck = $render_ck();
    tcheck('DOKU nonaktif: pilihan DOKU hilang (Duitku tetap seperti semula)', strpos($ck, 'value="doku"') === false && strpos($ck, 'name="pembayaran" value="duitku"') !== false);
    $n0 = $GLOBALS['cap']['n'];
    $r = $run($honest(['pembayaran' => 'doku']));
    tcheck('DOKU nonaktif tapi dikirim di form → ditolak, pesanan tidak dibuat', !$r[1] && strpos($r[0], 'Metode pembayaran tidak tersedia') !== false && $GLOBALS['cap']['n'] === $n0, $r[1] ? 'pesanan dibuat' : '');
    update_option('vdpg_doku', array_merge(get_option('vdpg_doku'), ['enabled' => '1']));

    // Total diubah → ditolak tanpa memanggil DOKU.
    $p = $honest(['pembayaran' => 'doku']); $p['produk']['hargatotal'] = '10000';
    $r = $run($p);
    tcheck('DOKU dengan hargatotal diubah → ditolak, DOKU tidak dipanggil', $rejected($r) && $GLOBALS['cap']['n'] === $n0, $r[1] ? 'pesanan dibuat, total ' . $r[1]->total : '');

    $r9 = $run($honest(['pembayaran' => 'doku', 'ongkir' => 'JNE - YES-1-DAY - 74000'])); $b9 = $GLOBALS['cap']['doku'] ?? [];
    $li9 = array_values(array_filter($b9['order']['line_items'] ?? [], function ($li) { return strpos($li['name'], 'Ongkos kirim') === 0; }));
    tcheck('DOKU ongkir YES-1-DAY: item "Ongkos kirim JNE YES-1-DAY" 74.000', $r9[1] && $li9 && $li9[0]['name'] === 'Ongkos kirim JNE YES-1-DAY' && (int) $li9[0]['price'] === 74000, wp_json_encode($b9['order']['line_items'] ?? []));
    if ($r9[1]) { delete_option('velocitytoko_pg_' . $r9[1]->id); $wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'doku', 'invoice' => $r9[1]->invoice]); }
    // Checkout jujur.
    set_theme_mod('kodeunik', '1'); set_theme_mod('buyeremail', 'Total: [total-order] [nomor-rekening]');
    $r = $run($honest(['pembayaran' => 'doku', 'kodeunik' => 77, 'kupon' => ['kode' => 'HEMAT10', 'potongan' => '10', 'jenis' => 'percent']]), ['kupon' => $kupon_sesi('HEMAT10')]);
    set_theme_mod('kodeunik', '0'); set_theme_mod('buyeremail', 'Total: [total-order] [detail-pesanan]');
    $o = $r[1]; $oid = $o ? (int) $o->id : 0; $inv = $o ? $o->invoice : '';
    tcheck('checkout DOKU (kupon 10% + kode unik 77): pesanan dibuat, total 283.577', $o && $o->pembayaran === 'doku' && (int) $o->total === 283577 && $o->status === 'Transaksi Baru', $o ? $o->total : strip_tags($r[0]));
    $b = $GLOBALS['cap']['doku'] ?? [];
    tcheck('tagihan DOKU = total pesanan, invoice = invoice pesanan', (int) ($b['order']['amount'] ?? 0) === 283577 && ($b['order']['invoice_number'] ?? '') === $inv, wp_json_encode($b['order'] ?? []));
    tcheck('URL kembali DOKU ke halaman pesanan', strpos($b['order']['callback_url'] ?? '', 'invoice=' . $inv) !== false, $b['order']['callback_url'] ?? '');
    $t = vd_pg_get_transaction('doku', $inv);
    tcheck('transaksi vdpg source=velocity-toko, ref_id=id pesanan, pending', $t && $t->source === 'velocity-toko' && (int) $t->ref_id === $oid && $t->status === 'pending');
    tcheck('halaman selesai menampilkan tombol Bayar via DOKU', strpos($r[0], 'checkout-link-v2/tok-' . $inv) !== false && stripos($r[0], 'Bayar Sekarang via DOKU') !== false);
    tcheck('email pembeli memuat link bayar DOKU', (bool) array_filter($GLOBALS['mails'], function ($m) use ($inv) { return strpos($m['message'], 'checkout-link-v2/tok-' . $inv) !== false; }));
    $_SESSION['keranjang'] = $cart_default;

    // Halaman pesanan: link masih berlaku → tidak membuat tagihan baru.
    $n1 = $GLOBALS['cap']['n'];
    $h = $lacak($inv);
    tcheck('halaman pesanan menampilkan link bayar yang sama (tanpa tagihan baru)', strpos($h, 'checkout-link-v2/tok-' . $inv . '-' . $n1) !== false && $GLOBALS['cap']['n'] === $n1, substr(strip_tags($h), 0, 120));
    tcheck('halaman lacak dengan invoice injeksi SQL tidak menampilkan pesanan', strpos($lacak("x' OR '1'='1"), 'INVOICE #') === false);

    // Notifikasi EXPIRED → pesanan tetap menunggu pembayaran, link dibuat ulang saat halaman dibuka.
    $c = $dn($inv, 283577, 'EXPIRED');
    tcheck('notifikasi EXPIRED diterima, pesanan tetap Transaksi Baru', $c === 200 && $order_row($oid)->status === 'Transaksi Baru' && vd_pg_get_transaction('doku', $inv)->status === 'expired', "$c " . $order_row($oid)->status);
    $d = get_option('velocitytoko_pg_' . $oid); $d['expires_at'] = time() - 10; update_option('velocitytoko_pg_' . $oid, $d, false);
    $h = $lacak($inv);
    tcheck('link kedaluwarsa dibuat ulang dengan invoice & nominal sama', $GLOBALS['cap']['n'] === $n1 + 1 && ($GLOBALS['cap']['doku']['order']['invoice_number'] ?? '') === $inv && (int) $GLOBALS['cap']['doku']['order']['amount'] === 283577 && strpos($h, 'tok-' . $inv . '-' . ($n1 + 1)) !== false && vd_pg_get_transaction('doku', $inv)->status === 'pending');
    $d = get_option('velocitytoko_pg_' . $oid); $d['expires_at'] = time() - 10; update_option('velocitytoko_pg_' . $oid, $d, false);
    $h = $lacak($inv);
    tcheck('buat ulang dibatasi 1x per 5 menit', $GLOBALS['cap']['n'] === $n1 + 1 && strpos($h, 'sedang dibuat ulang') !== false);
    delete_option('velocitytoko_pg_regen_' . $oid); $lacak($inv);

    // Nominal tidak cocok → tidak lunas.
    $c = $dn($inv, 1000);
    tcheck('notifikasi SUCCESS nominal salah → pesanan tidak Lunas', $order_row($oid)->status === 'Transaksi Baru', "$c " . $order_row($oid)->status);
    // Tanda tangan salah → ditolak.
    $body = json_encode(['order' => ['invoice_number' => $inv, 'amount' => 283577], 'transaction' => ['status' => 'SUCCESS']]);
    $rr = wp_remote_post(rest_url('vd-pg/v1/notify/doku'), ['timeout' => 30, 'body' => $body, 'headers' => ['Client-Id' => $dclient, 'Request-Id' => 'x', 'Request-Timestamp' => \VdPaymentGateway\Gateway\Doku\DokuSignature::timestamp(), 'Signature' => 'HMACSHA256=palsu', 'Content-Type' => 'application/json']]);
    tcheck('notifikasi bertanda tangan palsu ditolak', !is_wp_error($rr) && wp_remote_retrieve_response_code($rr) >= 400 && $order_row($oid)->status === 'Transaksi Baru', is_wp_error($rr) ? $rr->get_error_message() : wp_remote_retrieve_response_code($rr));
    // SUCCESS → Lunas (2x, idempoten).
    $c1 = $dn($inv, 283577); $c2 = $dn($inv, 283577);
    tcheck('notifikasi SUCCESS (2x) → pesanan Lunas', $c1 === 200 && $c2 === 200 && $order_row($oid)->status === 'Lunas', "$c1 $c2 " . $order_row($oid)->status);
    $h = $lacak($inv); $n2 = $GLOBALS['cap']['n'];
    tcheck('pesanan Lunas: halaman pesanan tanpa tombol bayar', stripos($h, 'Bayar Sekarang') === false && is_wp_error(velocitytoko_pg_payment_link($oid)) && $GLOBALS['cap']['n'] === $n2);
    // REFUNDED → Refund.
    $c = $dn($inv, 283577, 'REFUNDED');
    tcheck('notifikasi REFUNDED → pesanan Refund', $order_row($oid)->status === 'Refund', "$c " . $order_row($oid)->status);

    // Email lunas (diproses di proses ini supaya email tertangkap).
    $r = $run($honest(['pembayaran' => 'doku']));
    $o2 = $r[1]; $GLOBALS['mails'] = [];
    $res = (new \VdPaymentGateway\PaymentService())->apply_status('doku', ['invoice' => $o2->invoice, 'amount' => 309000, 'status' => 'paid', 'channel' => 'QRIS', 'raw' => []]);
    $to = array_map(function ($m) { return is_array($m['to']) ? implode(',', $m['to']) : $m['to']; }, $GLOBALS['mails']);
    tcheck('lunas: email ke pembeli & admin', $order_row($o2->id)->status === 'Lunas' && in_array('rina@example.com', $to, true) && in_array('admin@toko.test', $to, true), implode(' ', $to));
    // Admin membatalkan pesanan → tidak bisa dibayar / dibuat ulang.
    $r = $run($honest(['pembayaran' => 'doku']));
    $o3 = $r[1]; $wpdb->update($wpdb->prefix . 'order', ['status' => 'Gagal'], ['id' => $o3->id]);
    $d = get_option('velocitytoko_pg_' . $o3->id); $d['expires_at'] = time() - 10; update_option('velocitytoko_pg_' . $o3->id, $d, false);
    $n3 = $GLOBALS['cap']['n']; $h = $lacak($o3->invoice);
    tcheck('pesanan dibatalkan admin: tanpa tombol bayar, tanpa tagihan baru', stripos($h, 'Bayar Sekarang') === false && $GLOBALS['cap']['n'] === $n3);
    // Transaksi sumber lain dengan ref_id sama diabaikan.
    $fake = (object) ['source' => 'velocity-donasi', 'ref_id' => (string) $o3->id, 'invoice' => $o3->invoice, 'gateway' => 'doku', 'amount' => 309000];
    velocitytoko_pg_status_changed($fake, 'paid', []);
    $fake2 = (object) ['source' => 'velocity-toko', 'ref_id' => (string) $o3->id, 'invoice' => 'LAIN123', 'gateway' => 'doku', 'amount' => 309000];
    velocitytoko_pg_status_changed($fake2, 'paid', []);
    tcheck('event transaksi sumber/invoice lain tidak mengubah pesanan', $order_row($o3->id)->status === 'Gagal');
    $tx = (object) ['source' => 'velocity-toko', 'ref_id' => (string) $o3->id, 'invoice' => $o3->invoice, 'gateway' => 'doku', 'amount' => 1000, 'reference' => '', 'channel' => ''];
    delete_option('velocitytoko_pg_mismatch_' . $o3->id); $GLOBALS['mails'] = [];
    velocitytoko_pg_status_changed($tx, 'paid', []);
    tcheck('nominal transaksi ≠ total pesanan → tidak Lunas', $order_row($o3->id)->status === 'Gagal');
    $mm = array_values(array_filter($GLOBALS['mails'], function ($m) { return $m['to'] === 'admin@toko.test' && strpos($m['subject'], 'nominal tidak sesuai') !== false; }));
    tcheck('nominal tidak cocok: email admin berisi invoice, total & nominal', count($mm) === 1 && strpos($mm[0]['message'], $o3->invoice) !== false && strpos($mm[0]['message'], '309.000') !== false && strpos($mm[0]['message'], '1.000') !== false, count($mm) ? strip_tags($mm[0]['message']) : 'tanpa email');
    tcheck('email nominal tidak cocok tanpa data pribadi pembeli', count($mm) === 1 && !preg_match('/Rina|rina@|0812|Jl\. Uji/', $mm[0]['subject'] . $mm[0]['message']));
    velocitytoko_pg_status_changed($tx, 'paid', []);
    tcheck('email nominal tidak cocok tidak terkirim berulang untuk invoice yang sama', count($GLOBALS['mails']) === 1, (string) count($GLOBALS['mails']));
    $tx->amount = 309000; $GLOBALS['mails'] = [];
    velocitytoko_pg_status_changed($tx, 'paid', []);
    $adm = array_filter($GLOBALS['mails'], function ($m) { return $m['to'] === 'admin@toko.test'; });
    tcheck('pesanan batal lalu dibayar → Lunas, email admin memuat peringatan', $order_row($o3->id)->status === 'Lunas' && $adm && strpos(reset($adm)['message'], 'Perhatian') !== false);
    $nm = count($GLOBALS['mails']); velocitytoko_pg_status_changed($tx, 'paid', []);
    tcheck('event paid berulang tidak mengirim email lagi', count($GLOBALS['mails']) === $nm);

    echo "== Notifikasi lunas terlambat: status lanjutan tidak dimundurkan (DOKU & Duitku)\n";
    $r = $run($honest(['pembayaran' => 'doku'])); $o4 = $r[1];
    $wpdb->update($wpdb->prefix . 'order', ['status' => 'Sukses'], ['id' => $o4->id]); $GLOBALS['mails'] = [];
    $tx4 = (object) ['source' => 'velocity-toko', 'ref_id' => (string) $o4->id, 'invoice' => $o4->invoice, 'gateway' => 'doku', 'amount' => 309000, 'reference' => '', 'channel' => ''];
    velocitytoko_pg_status_changed($tx4, 'paid', []);
    tcheck('DOKU: pesanan Sukses + notifikasi lunas terlambat → tetap Sukses, tanpa email', $order_row($o4->id)->status === 'Sukses' && !$GLOBALS['mails'], $order_row($o4->id)->status);
    $r = $run($honest(['pembayaran' => 'duitku'])); $o5 = $r[1];
    // Callback Duitku asli selalu membawa amount; tanpa amount kini ditolak (lihat duitku-amount.php).
    $duitku_cb = function ($inv) use ($wpdb) { (new VToko\Order())->duitku_callback(['resultCode' => '00', 'merchantOrderId' => $inv, 'amount' => (string) $wpdb->get_var($wpdb->prepare("SELECT total FROM {$wpdb->prefix}order WHERE invoice = %s", $inv))]); };
    $wpdb->update($wpdb->prefix . 'order', ['status' => 'Sukses'], ['id' => $o5->id]); $GLOBALS['mails'] = [];
    $duitku_cb($o5->invoice);
    tcheck('Duitku: pesanan Sukses + callback lunas terlambat → tetap Sukses, tanpa email', $order_row($o5->id)->status === 'Sukses' && !$GLOBALS['mails'], $order_row($o5->id)->status);
    $wpdb->update($wpdb->prefix . 'order', ['status' => 'Transaksi Baru'], ['id' => $o5->id]);
    $duitku_cb($o5->invoice); $nm5 = count($GLOBALS['mails']); $duitku_cb($o5->invoice);
    tcheck('Duitku: Transaksi Baru → Lunas, callback ganda tidak mengirim email lagi', $order_row($o5->id)->status === 'Lunas' && $nm5 === 2 && count($GLOBALS['mails']) === 2, $order_row($o5->id)->status . " $nm5");
    $wpdb->update($wpdb->prefix . 'order', ['status' => 'Gagal'], ['id' => $o5->id]);
    $duitku_cb($o5->invoice);
    tcheck('Duitku: Gagal → Lunas (status awal)', $order_row($o5->id)->status === 'Lunas');

    echo "== Halaman lacak: token / data tersamar\n";
    $_GET = []; wp_set_current_user(0);
    $h = $lacak($o4->invoice);
    tcheck('tanpa token (link lama): data penerima tersamar', strpos($h, 'Rina Pembeli') === false && strpos($h, 'Jl. Uji 1') === false && strpos($h, ': 12345<') === false && strpos($h, 'R*** P***') !== false && strpos($h, 'disamarkan') !== false, '');
    tcheck('tanpa token: halaman tetap terbuka (status & produk tampil)', strpos($h, 'INVOICE #' . $o4->invoice) !== false && strpos($h, 'Kaos Uji') !== false);
    $_GET['t'] = 'tokensalah123';
    tcheck('token salah: data tersamar', strpos($lacak($o4->invoice), 'Rina Pembeli') === false);
    $url = velocitytoko_invoice_url($o4->invoice);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q); $_GET['t'] = $q['t'] ?? '';
    $h = $lacak($o4->invoice);
    tcheck('link bertoken: data penerima lengkap', strpos($h, 'Rina Pembeli') !== false && strpos($h, 'Jl. Uji 1') !== false && strpos($h, 'disamarkan') === false);
    $_GET['t'] = velocitytoko_invoice_token($o5->id, $o5->invoice);
    tcheck('token pesanan lain tidak berlaku', strpos($lacak($o4->invoice), 'Rina Pembeli') === false);
    $_GET = [];
    $admin = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($admin ? $admin[0]->ID : 0);
    tcheck('admin melihat data lengkap tanpa token', strpos($lacak($o4->invoice), 'Rina Pembeli') !== false);
    wp_set_current_user(0);

    // Form konfirmasi tanpa token: nama di pesan terima kasih tersamar dan di-escape.
    $_SESSION['token'] = 'sesi-uji'; $_FILES = ['file' => ['name' => '']];
    $d4 = $order_row($o4->id)->detail; $j4 = json_decode($d4, true);
    $_POST = ['metode' => 'BNI', 'detail' => '', 'token' => 'sesi-uji'];
    $h = $lacak($o4->invoice);
    tcheck('konfirmasi tanpa token: nama di pesan terima kasih tersamar', strpos($h, 'Terimakasih R*** P***') !== false && strpos($h, 'Rina Pembeli') === false);
    $j4['nama'] = 'Rina <img src=x onerror=alert(1)>';
    $wpdb->update($wpdb->prefix . 'order', ['detail' => wp_json_encode($j4)], ['id' => $o4->id]);
    $_GET['t'] = velocitytoko_invoice_token($o4->id, $o4->invoice);
    $h = $lacak($o4->invoice);
    tcheck('konfirmasi dengan token: nama utuh tapi di-escape (XSS tersimpan)', strpos($h, 'Terimakasih Rina &lt;img') !== false && strpos($h, '<img src=x onerror=alert(1)>') === false);
    $wpdb->update($wpdb->prefix . 'order', ['detail' => $d4], ['id' => $o4->id]);
    $_POST = []; $_FILES = []; $_GET = [];

    // XSS lewat ?invoice= di form lacak.
    $_GET['invoice'] = '"><script>alert(1)</script>';
    $h = do_shortcode('[vtoko-lacak]');
    tcheck('form lacak: ?invoice= di-escape (tanpa reflected XSS)', strpos($h, '<script>alert(1)') === false);
    $_GET = [];

    // Tracking resi: nama penerima tersamar tanpa token, isi respons API di-escape.
    $wpdb->update($wpdb->prefix . 'order', ['resi' => 'RESI1', 'kurir' => 'jne'], ['id' => $o4->id]);
    $h = $lacak($o4->invoice);
    tcheck('tracking tanpa token: penerima tersamar, manifest di-escape', strpos($h, 'R*** P*** (Kota Uji)') !== false && strpos($h, 'Rina Pembeli') === false && strpos($h, '<img src=x onerror=alert(2)>') === false && strpos($h, '&lt;img src=x') !== false);
    $_GET['t'] = velocitytoko_invoice_token($o4->id, $o4->invoice);
    tcheck('tracking dengan token: nama penerima utuh', strpos($lacak($o4->invoice), 'Rina Pembeli (Kota Uji)') !== false);
    $_GET = [];

    // Checkout bank: link di halaman selesai & SMS memakai token; email [link-pesanan].
    set_theme_mod('buyeremail', 'Total: [total-order] [link-pesanan]');
    $r = $run($honest(['pembayaran' => 'bank'])); $o6 = $r[1];
    set_theme_mod('buyeremail', 'Total: [total-order] [detail-pesanan]');
    $tok6 = velocitytoko_invoice_token($o6->id, $o6->invoice);
    tcheck('halaman selesai bank: link konfirmasi bertoken', strpos($r[0], 't=' . $tok6) !== false);
    tcheck('email pembeli [link-pesanan] bertoken', (bool) array_filter($GLOBALS['mails'], function ($m) use ($tok6) { return strpos($m['message'], 't=' . $tok6) !== false; }));
    $hh = wp_remote_head(velocitytoko_invoice_url($o6->invoice), ['timeout' => 30, 'redirection' => 0]);
    tcheck('halaman detail bertoken mengirim Referrer-Policy: no-referrer', !is_wp_error($hh) && wp_remote_retrieve_header($hh, 'referrer-policy') === 'no-referrer', is_wp_error($hh) ? $hh->get_error_message() : wp_remote_retrieve_response_code($hh) . ' ' . wp_remote_retrieve_header($hh, 'referrer-policy'));
    foreach ([$o4, $o5] as $ox) { delete_option('velocitytoko_pg_' . $ox->id); delete_option('velocitytoko_pg_regen_' . $ox->id); $wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'doku', 'invoice' => $ox->invoice]); }
    delete_option('velocitytoko_pg_mismatch_' . $o3->id);

    echo "== Sinkronisasi susulan (notifikasi masuk saat velocity-toko tidak terpasang)\n";
    $svc = new \VdPaymentGateway\PaymentService();
    $missed = function ($o, $status) use ($svc) {
        remove_action('vd_pg_payment_status_changed', 'velocitytoko_pg_status_changed', 10);
        $svc->apply_status('doku', ['invoice' => $o->invoice, 'status' => $status, 'amount' => (int) $o->total, 'raw' => []]);
        add_action('vd_pg_payment_status_changed', 'velocitytoko_pg_status_changed', 10, 3);
    };
    $sync_orders = [];
    $mkdoku = function () use ($run, $honest, &$sync_orders) { $r = $run($honest(['pembayaran' => 'doku'])); $sync_orders[] = $r[1]; return $r[1]; };
    $s1 = $mkdoku(); $missed($s1, 'paid');
    tcheck('notifikasi lunas terlewat: transaksi paid, pesanan masih Transaksi Baru', vd_pg_get_transaction('doku', $s1->invoice)->status === 'paid' && $order_row($s1->id)->status === 'Transaksi Baru');
    $GLOBALS['mails'] = []; $h = $lacak($s1->invoice);
    tcheck('halaman pesanan dibuka → Lunas disusulkan, email pembeli & admin terkirim', $order_row($s1->id)->status === 'Lunas' && count($GLOBALS['mails']) === 2 && strpos($h, 'Lunas') !== false, $order_row($s1->id)->status . ' ' . count($GLOBALS['mails']));
    $lacak($s1->invoice);
    tcheck('dibuka lagi: tidak ada email ganda', count($GLOBALS['mails']) === 2);

    $s2 = $mkdoku(); $missed($s2, 'paid');
    $s3 = $mkdoku(); $wpdb->update($wpdb->prefix . 'order', ['status' => 'Sukses'], ['id' => $s3->id]); $missed($s3, 'paid');
    $GLOBALS['mails'] = []; velocitytoko_pg_reconcile_cron();
    tcheck('cron harian: pesanan lain yang terlewat → Lunas', $order_row($s2->id)->status === 'Lunas');
    tcheck('cron harian: pesanan berstatus Sukses tidak diubah', $order_row($s3->id)->status === 'Sukses');
    $missed($s2, 'refunded');
    velocitytoko_pg_reconcile_cron();
    tcheck('cron harian: refund terlewat → Refund', $order_row($s2->id)->status === 'Refund');
    $wpdb->update($wpdb->prefix . 'order', ['date' => date('d-m-Y H:i:s', current_time('timestamp') - 40 * DAY_IN_SECONDS)], ['id' => $s1->id]);
    $s4 = $mkdoku(); $missed($s4, 'paid'); $wpdb->update($wpdb->prefix . 'order', ['date' => date('d-m-Y H:i:s', current_time('timestamp') - 40 * DAY_IN_SECONDS)], ['id' => $s4->id]);
    velocitytoko_pg_reconcile_cron();
    tcheck('cron harian: pesanan lebih dari 30 hari dilewati (dibuka di halaman tetap tersusul)', $order_row($s4->id)->status === 'Transaksi Baru' && ($lacak($s4->invoice) || true) && $order_row($s4->id)->status === 'Lunas');

    // Cek ke gateway: tombol Cek Status & cron untuk pesanan yang masih pending.
    $s5 = $mkdoku(); $GLOBALS['dstatus'][$s5->invoice] = ['SUCCESS', (int) $s5->total];
    $h = $lacak($s5->invoice);
    tcheck('halaman pesanan pending menampilkan tombol Cek Status', strpos($h, 'Cek Status') !== false && strpos($h, 'name="_vtnonce"') !== false && $order_row($s5->id)->status === 'Transaksi Baru');
    $c0 = $GLOBALS['cap']['status'] ?? 0;
    $_POST = ['velocitytoko_pg_cek' => (string) $s5->id, '_vtnonce' => 'salah'];
    $lacak($s5->invoice);
    tcheck('Cek Status dengan nonce salah: gateway tidak ditanya', ($GLOBALS['cap']['status'] ?? 0) === $c0 && $order_row($s5->id)->status === 'Transaksi Baru');
    $_POST = ['velocitytoko_pg_cek' => (string) $s5->id, '_vtnonce' => wp_create_nonce('velocitytoko_pg_cek_' . $s5->id)];
    $GLOBALS['mails'] = []; $h = $lacak($s5->invoice); $_POST = [];
    tcheck('Cek Status: gateway ditanya, pesanan Lunas, pesan tampil', ($GLOBALS['cap']['status'] ?? 0) === $c0 + 1 && $order_row($s5->id)->status === 'Lunas' && strpos($h, 'Pembayaran diterima, status pesanan sudah diperbarui') !== false && count($GLOBALS['mails']) === 2, $order_row($s5->id)->status);
    $s6 = $mkdoku();
    $_POST = ['velocitytoko_pg_cek' => (string) $s6->id, '_vtnonce' => wp_create_nonce('velocitytoko_pg_cek_' . $s6->id)];
    $h = $lacak($s6->invoice); $_POST = []; $c1 = $GLOBALS['cap']['status'];
    tcheck('Cek Status saat belum dibayar: pesan "belum diterima", tetap Transaksi Baru', strpos($h, 'pembayaran belum diterima') !== false && $order_row($s6->id)->status === 'Transaksi Baru');
    $w = velocitytoko_pg_check_status($s6->id);
    tcheck('Cek Status dibatasi 1x per menit per pesanan', is_wp_error($w) && $w->get_error_code() === 'velocitytoko_pg_check_wait' && $GLOBALS['cap']['status'] === $c1);
    // Cron: tanya gateway hanya untuk pesanan pending berumur 1 jam–3 hari.
    $s7 = $mkdoku(); $GLOBALS['dstatus'][$s7->invoice] = ['SUCCESS', (int) $s7->total];
    $s8 = $mkdoku(); $GLOBALS['dstatus'][$s8->invoice] = ['SUCCESS', (int) $s8->total];
    $wpdb->update($wpdb->prefix . 'order', ['date' => date('d-m-Y H:i:s', current_time('timestamp') - 2 * HOUR_IN_SECONDS)], ['id' => $s7->id]);
    $wpdb->update($wpdb->prefix . 'order', ['date' => date('d-m-Y H:i:s', current_time('timestamp') - 4 * DAY_IN_SECONDS)], ['id' => $s6->id]);
    $c2 = $GLOBALS['cap']['status'];
    velocitytoko_pg_reconcile_cron();
    tcheck('cron: pesanan pending umur 2 jam dicek ke gateway → Lunas', $order_row($s7->id)->status === 'Lunas');
    tcheck('cron: pesanan baru (<1 jam) & lama (>3 hari) tidak dicek ke gateway', $order_row($s8->id)->status === 'Transaksi Baru' && $GLOBALS['cap']['status'] === $c2 + 1, ($GLOBALS['cap']['status'] - $c2) . ' panggilan');
    tcheck('cron terjadwal harian', wp_get_schedule('velocitytoko_pg_reconcile') === 'daily');
    deactivate_velocity_toko();
    tcheck('deaktivasi menghapus jadwal cron (dijadwalkan ulang saat aktif lagi)', wp_next_scheduled('velocitytoko_pg_reconcile') === false);
    wp_schedule_event(time() + 60, 'daily', 'velocitytoko_pg_reconcile');
    foreach ($sync_orders as $ox) { if ($ox) { delete_option('velocitytoko_pg_' . $ox->id); delete_option('velocitytoko_pg_regen_' . $ox->id); delete_option('velocitytoko_pg_check_' . $ox->id); $wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'doku', 'invoice' => $ox->invoice]); } }

    foreach ([$oid, $o2->id, $o3->id] as $id) { delete_option('velocitytoko_pg_' . $id); delete_option('velocitytoko_pg_regen_' . $id); }
    foreach ([$inv, $o2->invoice, $o3->invoice] as $i) { $wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'doku', 'invoice' => $i]); }
    $dokuopt0 === false ? delete_option('vdpg_doku') : update_option('vdpg_doku', $dokuopt0);
}

// Bersihkan data uji.
wp_delete_post($p1, true); wp_delete_post($p2, true);
$wpdb->delete($tk, ['kode' => 'HEMAT10']); $wpdb->delete($tk, ['kode' => 'LAMA']);
$wpdb->query("DELETE FROM {$wpdb->prefix}order WHERE detail LIKE '%rina@example.com%'");
echo "\nHasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
