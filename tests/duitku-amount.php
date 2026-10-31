<?php
/**
 * Callback Duitku "Sukses" tanpa nominal / nominal bukan angka / nominal beda harus ditolak:
 * (1) endpoint vd-payment-gateway, (2) action lama velocity_duitku_callback di velocity-toko.
 * Jalankan lewat run-tests.sh (butuh php -S 127.0.0.1:8791).
 */
global $wpdb;
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function dcheck($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
function dpost($body) { $r = wp_remote_post(rest_url('vd-pg/v1/notify/duitku'), ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $body, 'timeout' => 20]); return is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r); }
// Email dicegat dan dicatat (tidak dikirim) supaya jumlah email notifikasi bisa diperiksa.
$GLOBALS['mails'] = [];
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['mails'][] = $a; return true; }, 10, 2);
$mails_to = function ($needle) { return count(array_filter($GLOBALS['mails'], function ($m) use ($needle) { return strpos((string) $m['subject'], $needle) !== false; })); };
// Peringatan/notice PHP di kode yang diuji dihitung sebagai kegagalan.
$GLOBALS['php_warn'] = [];
set_error_handler(function ($no, $str, $file, $line) { if (strpos($file, 'velocity-toko') !== false || strpos($file, 'vd-payment-gateway') !== false) { $GLOBALS['php_warn'][] = "$str @ " . basename($file) . ":$line"; } return false; });
add_filter('pre_http_request', function ($pre, $args, $url) {
    return strpos($url, 'ongkir.velocitydeveloper.id') !== false ? ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => '{"rajaongkir":{"results":[]}}'] : $pre;
}, 10, 3);

$mcode = 'DS0001'; $mkey = 'duitku-key-xyz';
update_option('vdpg_duitku', ['enabled' => '1', 'mode' => 'sandbox', 'merchant_code' => $mcode, 'api_key' => $mkey, 'expiry' => 60, 'return_url' => '']);
update_option('vdpg_debug_log', '1');
$sig = function ($amount, $inv) use ($mcode, $mkey) { return hash_hmac('sha256', $mcode . $amount . $inv, $mkey); };
$cb = function ($inv, $amount, $rc, $with_amount = true) use ($mcode, $sig) {
    $b = ['merchantCode' => $mcode, 'merchantOrderId' => $inv, 'resultCode' => $rc, 'reference' => 'R', 'paymentCode' => 'BC', 'signature' => $sig($amount, $inv)];
    if ($with_amount) { $b['amount'] = (string) $amount; }
    return dpost($b);
};
$run = strtoupper(substr(md5(uniqid('', true)), 0, 6));

echo "== vd-payment-gateway (endpoint notifikasi)\n";
$inv = 'DA-' . $run;
$r = vd_pg_create_payment('duitku', ['invoice' => $inv, 'amount' => 50000, 'source' => 'uji', 'ref_id' => '1']);
$GLOBALS['vd_ok'] = !is_wp_error($r);
// create_payment memanggil API Duitku asli tanpa mock: catat transaksi langsung bila gagal.
if (is_wp_error($r)) {
    (new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('duitku', $inv, ['source' => 'uji', 'ref_id' => '1', 'amount' => 50000]);
}
$st = function () use ($inv) { return vd_pg_get_transaction('duitku', $inv)->status; };
$c = $cb($inv, '', '00', false);
dcheck('Sukses TANPA amount ditolak, transaksi tetap pending', $c >= 400 && $st() === 'pending', "http $c, " . $st());
$c = $cb($inv, 'abc', '00');
dcheck('Sukses dengan amount bukan angka ditolak, tetap pending', $c >= 400 && $st() === 'pending', "http $c, " . $st());
$c = $cb($inv, '', '00');
dcheck('Sukses dengan amount kosong ditolak, tetap pending', $c >= 400 && $st() === 'pending', "http $c, " . $st());
$logf = class_exists('\VdPaymentGateway\Logger') ? \VdPaymentGateway\Logger::files() : [];
$log = $logf ? (string) file_get_contents($logf[0]) : '';
dcheck('3 callback ditolak dicatat 1x saja (dibatasi per jam per invoice)', substr_count($log, 'status.amount_mismatch {"gateway":"duitku","invoice":"' . $inv) === 1, substr_count($log, 'status.amount_mismatch {"gateway":"duitku","invoice":"' . $inv) . 'x');
delete_transient('vdpg_duitku_badamt_' . md5($inv));
$c = $cb($inv, '', '02', false);
dcheck('Gagal tanpa amount tetap diterima (200) → failed', $c === 200 && $st() === 'failed', "http $c, " . $st());
dcheck('Gagal tanpa amount tidak menimpa nominal transaksi (tetap 50000)', (int) vd_pg_get_transaction('duitku', $inv)->amount === 50000, (string) vd_pg_get_transaction('duitku', $inv)->amount);

$inv2 = 'DB-' . $run;
(new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('duitku', $inv2, ['source' => 'uji', 'ref_id' => '2', 'amount' => 50000]);
$c = $cb($inv2, '50000', '00');
dcheck('Sukses dengan amount benar → paid', $c === 200 && vd_pg_get_transaction('duitku', $inv2)->status === 'paid', "http $c");

echo "== velocity-toko (action lama velocity_duitku_callback)\n";
if (!class_exists('\\VToko\\Order')) {
    dcheck('plugin velocity-toko aktif', false);
} else {
    // 1.4.0 (feat) mencatat ke log vd-payment-gateway & meneruskan pesanan vdpg; 1.3.5 (hotfix) hanya error_log.
    $FEAT = function_exists('velocitytoko_pg_status_changed');
    $t = $wpdb->prefix . 'order';
    $detail = wp_json_encode(['email' => 'pembeli@example.com', 'subdistrict_destination' => '1', 'produk' => ['products' => []]]);
    $mk = function ($suffix) use ($wpdb, $t, $detail, $run) {
        $wpdb->insert($t, ['id_pembeli' => 0, 'invoice' => 'VT' . $suffix . $run, 'date' => current_time('mysql'), 'detail' => $detail, 'status' => 'Transaksi Baru', 'pembayaran' => 'duitku', 'qty' => 1, 'total' => 125000]);
        return (int) $wpdb->insert_id;
    };
    $status = function ($id) use ($wpdb, $t) { return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id = %d", $id)); };

    $o = $mk('A');
    $GLOBALS['mails'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00']);
    dcheck('callback lama Sukses TANPA amount: pesanan tidak Lunas', $status($o) === 'Transaksi Baru', $status($o));
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00', 'amount' => 'abc']);
    dcheck('callback lama amount bukan angka: tidak Lunas', $status($o) === 'Transaksi Baru', $status($o));
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00', 'amount' => '10000']);
    dcheck('callback lama amount beda dari total: tidak Lunas', $status($o) === 'Transaksi Baru', $status($o));
    dcheck('email admin "nominal tidak sesuai" terkirim tepat 1x untuk 3 callback salah', $mails_to('VTA' . $run . ': nominal tidak sesuai') === 1, $mails_to('VTA' . $run . ': nominal tidak sesuai') . 'x');
    $log = $logf ? (string) file_get_contents($logf[0]) : '';
    if ($FEAT) dcheck('log toko mencatat 3x status.amount_mismatch (source velocity-toko)', substr_count($log, '"source":"velocity-toko","invoice":"VTA' . $run . '"') === 3 && strpos($log, '"notified":"10000","billed":125000') !== false);
    $m = array_values(array_filter($GLOBALS['mails'], function ($m) use ($run) { return strpos((string) $m['subject'], 'VTA' . $run . ': nominal tidak sesuai') !== false; }));
    dcheck('email nominal kosong menyebut "tidak valid", bukan Rp 0', $m && strpos($m[0]['message'], 'Nominal dibayar: tidak valid') !== false, $m ? strip_tags($m[0]['message']) : '-');
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00', 'amount' => '124999.5']);
    dcheck('callback lama amount pecahan (124999.5) tidak dibulatkan jadi Lunas', $status($o) === 'Transaksi Baru', $status($o));
    $GLOBALS['mails'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00', 'amount' => '125000.00']);
    dcheck('callback lama amount = total → Lunas', $status($o) === 'Lunas', $status($o));
    dcheck('email lunas lama memakai nomor invoice & UTF-8', $mails_to('Invoice #VTA' . $run . ' telah dibayarkan') === 2 && stripos(implode('', (array) $GLOBALS['mails'][0]['headers']), 'charset=UTF-8') !== false, wp_json_encode(array_column($GLOBALS['mails'], 'subject')));
    $GLOBALS['mails'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTA' . $run, 'resultCode' => '00', 'amount' => '125000']);
    dcheck('callback lunas ganda: tidak ada email kedua', count($GLOBALS['mails']) === 0 && $status($o) === 'Lunas', count($GLOBALS['mails']) . ' email');

    // Status lanjutan tidak dimundurkan dan tidak memicu email walau nominal benar.
    $sk = $mk('K');
    $wpdb->update($t, ['status' => 'Sukses'], ['id' => $sk]);
    $GLOBALS['mails'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTK' . $run, 'resultCode' => '00', 'amount' => '125000']);
    dcheck('pesanan Sukses + callback lunas benar: status tetap, tanpa email', $status($sk) === 'Sukses' && count($GLOBALS['mails']) === 0, $status($sk) . ', ' . count($GLOBALS['mails']) . ' email');

    // Invoice berisi kutip (dulu disisipkan mentah ke SQL) tidak mengenai pesanan lain.
    $q = $mk('Q');
    $GLOBALS['php_warn'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => "x' OR invoice LIKE 'VTQ%", 'resultCode' => '00', 'amount' => '125000']);
    dcheck('invoice dengan kutip tidak mengenai pesanan lain', $status($q) === 'Transaksi Baru', $status($q));

    // Pesanan toko yang dibayar lewat vd-payment-gateway: action lama untuk transaksi yang sama diabaikan (1.4.0).
    $p = $mk('P');
    if ($FEAT) {
    (new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('duitku', 'VTP' . $run, ['source' => 'velocity-toko', 'ref_id' => (string) $p, 'amount' => 125000]);
    $GLOBALS['mails'] = [];
    do_action('velocity_duitku_callback', ['merchantOrderId' => 'VTP' . $run, 'resultCode' => '00', 'amount' => '1']);
    dcheck('action lama untuk pesanan lewat vdpg diabaikan (tanpa email/status)', count($GLOBALS['mails']) === 0 && $status($p) === 'Transaksi Baru', count($GLOBALS['mails']) . ' email, ' . $status($p));
    }
    delete_option('velocitytoko_pg_mismatch_' . $p);

    // Pesanan yang sudah Lunas / berstatus lanjut: nominal salah tidak mengirim email palsu, status tetap.
    foreach (['L' => 'Lunas', 'S' => 'Sukses'] as $k => $stt) {
        $id = $mk($k);
        $wpdb->update($t, ['status' => $stt], ['id' => $id]);
        $GLOBALS['mails'] = [];
        do_action('velocity_duitku_callback', ['merchantOrderId' => 'VT' . $k . $run, 'resultCode' => '00', 'amount' => '1']);
        dcheck("pesanan $stt + nominal salah: tanpa email, status tetap", count($GLOBALS['mails']) === 0 && $status($id) === $stt, count($GLOBALS['mails']) . ' email, ' . $status($id));
        delete_option('velocitytoko_pg_mismatch_' . $id);
    }

    // Detail pesanan tanpa produk/kecamatan: get_by_invoice tidak memunculkan peringatan PHP.
    $wpdb->insert($t, ['id_pembeli' => 0, 'invoice' => 'VTX' . $run, 'date' => current_time('mysql'), 'detail' => '{"email":"x@example.com"}', 'status' => 'Transaksi Baru', 'pembayaran' => 'duitku', 'qty' => 1, 'total' => 1000]);
    $GLOBALS['php_warn'] = [];
    $got = (new \VToko\Order())->get_by_invoice('VTX' . $run);
    dcheck('get_by_invoice tanpa detail produk: tanpa peringatan, itemproducts kosong', $got && $got->itemproducts === [] && !$GLOBALS['php_warn'], implode('; ', $GLOBALS['php_warn']));

    $GLOBALS['php_warn'] = []; $err = '';
    try {
        do_action('velocity_duitku_callback', ['merchantOrderId' => 'TIDAK-ADA-' . $run, 'resultCode' => '00', 'amount' => '1']);
        do_action('velocity_duitku_callback', []);
    } catch (\Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
    dcheck('invoice tidak dikenal / payload kosong: tanpa exception & peringatan', $err === '' && !$GLOBALS['php_warn'], $err . implode('; ', $GLOBALS['php_warn']));
    $wpdb->query("DELETE FROM $t WHERE invoice LIKE 'VT_" . esc_sql($run) . "'");
    delete_option('velocitytoko_pg_mismatch_' . $o);
}
// Bersihkan transaksi uji.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}vdpg_transactions WHERE invoice IN (%s, %s, %s)", $inv, $inv2, 'VTP' . $run));
update_option('vdpg_debug_log', '0');
echo "\nHasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
