<?php
/**
 * Tes ujung-ke-ujung vd-payment-gateway dengan mock DOKU & Duitku. Aman dijalankan berulang.
 * Jalankan lewat tests/run-tests.sh (lihat tests/README.md).
 */
use VdPaymentGateway\Gateway\Doku\DokuSignature;
global $wpdb;
define("VDPG_TEST_LOG", WP_CONTENT_DIR . "/vdpg-hook.log"); $log = VDPG_TEST_LOG;
@unlink($log);
$wpdb->query("DELETE FROM {$wpdb->prefix}vdpg_transactions");
$run = strtoupper(substr(md5(uniqid('', true)), 0, 6));
$GLOBALS["pass"] = 0; $GLOBALS["fail"] = 0;
function check($label, $ok, $info = '') { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
function hooks($needle) { return substr_count(@file_get_contents(VDPG_TEST_LOG) ?: '', $needle); }
function post_to($url, $headers, $body) { $r = wp_remote_post($url, ['headers' => $headers, 'body' => $body, 'timeout' => 20]); return is_wp_error($r) ? [0, $r->get_error_message()] : [wp_remote_retrieve_response_code($r), wp_remote_retrieve_body($r)]; }

$dsecret = 'SK-test-123'; $dclient = 'BRN-0001-TEST';
$mcode = 'DS0001'; $mkey = 'duitku-key-xyz';
update_option('vdpg_doku', ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => $dclient, 'secret_key' => $dsecret, 'payment_due' => 60]);
update_option('vdpg_duitku', ['enabled' => '1', 'mode' => 'sandbox', 'merchant_code' => $mcode, 'api_key' => $mkey, 'expiry' => 60, 'return_url' => '']);
// Opsi diubah langsung di DB; registry di-cache, jadi buat ulang.
$GLOBALS['cap'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) use ($dsecret, $mcode, $mkey) {
    $resp = function ($code, $body) { return ['response' => ['code' => $code], 'body' => is_string($body) ? $body : json_encode($body), 'headers' => [], 'cookies' => []]; };
    $path = parse_url($url, PHP_URL_PATH);
    if (strpos($url, 'api-sandbox.doku.com') !== false) {
        $h = $args['headers'];
        $ok = DokuSignature::verify($h['Signature'], $h['Client-Id'], $h['Request-Id'], $h['Request-Timestamp'], $path, $args['body'], $dsecret);
        $GLOBALS['cap']['doku'] = ['sig' => $ok, 'body' => json_decode((string) $args['body'], true)];
        if (!$ok) return $resp(401, ['error' => ['message' => 'Invalid Signature']]);
        if ($args['method'] === 'GET') return $resp(200, ['order' => ['invoice_number' => basename($path), 'amount' => 175000], 'transaction' => ['status' => 'SUCCESS'], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA']]);
        $b = json_decode($args['body'], true);
        return $resp(200, '{"message":["SUCCESS"],"response":{"order":{"amount":"' . $b['order']['amount'] . '","invoice_number":"' . $b['order']['invoice_number'] . '","session_id":"sess"},"payment":{"token_id":"tok","url":"https://sandbox.doku.com/checkout-link-v2/tok","expired_date":"' . (new DateTime('+1 hour', new DateTimeZone('Asia/Jakarta')))->format('YmdHis') . '"},"uuid":2225240712094712339107164227041411929328}}');
    }
    if (strpos($url, 'duitku.com') !== false) {
        $h = $args['headers']; $b = json_decode($args['body'], true);
        if (strpos($path, 'createInvoice') !== false) {
            $ok = $h['x-duitku-merchantcode'] === $mcode && hash_equals(hash_hmac('sha256', $mcode . $h['x-duitku-timestamp'], $mkey), $h['x-duitku-signature']);
            $GLOBALS['cap']['duitku'] = ['sig' => $ok, 'body' => $b];
            if (!$ok) return $resp(401, ['Message' => 'Invalid signature']);
            return $resp(200, ['merchantCode' => $mcode, 'reference' => 'DS-REF-' . $b['merchantOrderId'], 'paymentUrl' => 'https://app-sandbox.duitku.com/redirect_checkout?reference=X', 'statusCode' => '00', 'statusMessage' => 'SUCCESS']);
        }
        if (strpos($path, 'transactionStatus') !== false) {
            $ok = hash_equals(hash_hmac('sha256', $mcode . $b['merchantOrderId'], $mkey), $b['signature']);
            $GLOBALS['cap']['duitku_status'] = $ok;
            return $resp(200, ['merchantOrderId' => $b['merchantOrderId'], 'reference' => 'R', 'amount' => '50000', 'statusCode' => '00', 'statusMessage' => 'SUCCESS']);
        }
    }
    return $pre;
}, 10, 3);

echo "== DOKU (run $run)\n";
$inv = "DK-$run-1";
$r = vd_pg_create_payment('doku', ['invoice' => $inv, 'amount' => 175000, 'source' => 'vd-store', 'ref_id' => '42', 'return_url' => home_url('/thanks'),
    'customer' => ['name' => 'Budi', 'email' => 'budi@example.com', 'phone' => '0812-3456-789'], 'items' => [['name' => 'Kaos', 'price' => 75000, 'qty' => 2]]]);
check('buat pembayaran DOKU', !is_wp_error($r) && !empty($r['payment_url']), is_wp_error($r) ? $r->get_error_message() : '');
check('signature request DOKU diterima mock', !empty($GLOBALS['cap']['doku']['sig']));
$li = $GLOBALS['cap']['doku']['body']['order']['line_items'] ?? [];
check('total line_items = amount', array_sum(array_map(function ($l) { return $l['price'] * $l['quantity']; }, $li)) === 175000);
check('nomor HP jadi 62…', ($GLOBALS['cap']['doku']['body']['customer']['phone'] ?? '') === '628123456789');

$dn = function ($payload, $secret = null) use ($dclient, $dsecret) {
    $body = json_encode($payload); $rid = wp_generate_uuid4(); $ts = DokuSignature::timestamp();
    $sig = DokuSignature::generate($dclient, $rid, $ts, '/wp-json/vd-pg/v1/notify/doku', $body, $secret ?? $dsecret);
    return post_to(rest_url('vd-pg/v1/notify/doku'), ['Client-Id' => $dclient, 'Request-Id' => $rid, 'Request-Timestamp' => $ts, 'Signature' => $sig, 'Content-Type' => 'application/json'], $body);
};
$ok = ['order' => ['invoice_number' => $inv, 'amount' => 175000], 'transaction' => ['status' => 'SUCCESS'], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA']];
$rr = $dn($ok, 'salah'); check('signature salah ditolak', $rr[0] === 401, implode(' ', $rr));
$bad = $ok; $bad['order']['amount'] = 1000; $dn($bad);
$t = vd_pg_get_transaction('doku', $inv);
check('nominal salah tidak lunas + diberi catatan', $t->status === 'pending' && strpos($t->note, 'tidak cocok') !== false, $t->note);
$f = $ok; $f['transaction']['status'] = 'FAILED'; $dn($f);
check('FAILED diabaikan (tetap pending)', vd_pg_get_transaction('doku', $inv)->status === 'pending');
$dn($ok); $dn($ok);
$t = vd_pg_get_transaction('doku', $inv);
check('SUCCESS 2x → lunas, catatan dibersihkan? (tidak, catatan tetap utk jejak)', $t->status === 'paid');
check('action vd_pg_payment_paid dipicu tepat 1x', hooks("vd_pg_payment_paid $inv") === 1, hooks("vd_pg_payment_paid $inv") . 'x');
$e = $ok; $e['transaction']['status'] = 'EXPIRED'; $dn($e);
check('EXPIRED setelah lunas tidak menurunkan status', vd_pg_get_transaction('doku', $inv)->status === 'paid');
check('invoice asing → 404', $dn(['order' => ['invoice_number' => 'NOPE-' . $run, 'amount' => 1], 'transaction' => ['status' => 'SUCCESS']])[0] === 404);
$x = vd_pg_create_payment('doku', ['invoice' => $inv, 'amount' => 175000]);
check('buat ulang invoice lunas ditolak', is_wp_error($x) && $x->get_error_code() === 'vdpg_already_paid');
$inv2 = "DK-$run-2";
vd_pg_create_payment('doku', ['invoice' => $inv2, 'amount' => 175000, 'source' => 'vd-store']);
$s = vd_pg_sync_status('doku', $inv2);
check('cek status DOKU (GET, signature tanpa digest)', !is_wp_error($s) && $s->status === 'paid' && !empty($GLOBALS['cap']['doku']['sig']));

echo "== Invoice dipakai sumber lain (0.2.5)\n";
$invx = "DKX-$run"; $GLOBALS['cap']['doku'] = [];
vd_pg_create_payment('doku', ['invoice' => $invx, 'amount' => 50000, 'source' => 'velocity-donasi', 'ref_id' => '901', 'customer' => ['name' => 'Ani'], 'items' => []]);
$GLOBALS['cap']['doku'] = [];
$r = vd_pg_create_payment('doku', ['invoice' => $invx, 'amount' => 999, 'source' => 'vd-store', 'ref_id' => '5', 'customer' => ['name' => 'X'], 'items' => []]);
$t = vd_pg_get_transaction('doku', $invx);
check('invoice milik sumber lain ditolak, API tidak dipanggil, transaksi utuh', is_wp_error($r) && $r->get_error_code() === 'vdpg_invoice_taken' && empty($GLOBALS['cap']['doku']) && $t->source === 'velocity-donasi' && $t->ref_id === '901' && (int) $t->amount === 50000);
$r = vd_pg_create_payment('doku', ['invoice' => $invx, 'amount' => 50000, 'source' => 'velocity-donasi', 'ref_id' => '902', 'customer' => ['name' => 'X'], 'items' => []]);
check('invoice sama, ref_id lain ditolak', is_wp_error($r) && vd_pg_get_transaction('doku', $invx)->ref_id === '901');
$r = vd_pg_create_payment('doku', ['invoice' => $invx, 'amount' => 50000, 'source' => 'velocity-donasi', 'ref_id' => '901', 'customer' => ['name' => 'Ani'], 'items' => []]);
check('pemilik yang sama boleh membuat ulang link', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : '');
$repo = new \VdPaymentGateway\Transaction\TransactionRepository();
check('upsert_pending langsung juga menolak sumber lain', is_wp_error($repo->upsert_pending('doku', $invx, ['source' => 'vd-store', 'ref_id' => '5', 'amount' => 1])));

echo "== Penulisan bersamaan (0.2.6): find() basi = proses lain menulis di antara baca & tulis\n";
if (!class_exists('VdpgStaleRepo')) {
    class VdpgStaleRepo extends \VdPaymentGateway\Transaction\TransactionRepository {
        public $stale = false;
        public function find($g, $i) { if ($this->stale !== false) { $r = $this->stale; $this->stale = false; return $r; } return parent::find($g, $i); }
    }
}
$tt = $wpdb->prefix . 'vdpg_transactions'; $sr = new VdpgStaleRepo();
$ia = "CC-A-$run"; $sr->upsert_pending('doku', $ia, ['source' => '', 'ref_id' => '', 'amount' => 1000]);
$sr->stale = $sr->find('doku', $ia) ?: null; $sr->stale = clone $sr->stale;
$wpdb->update($tt, ['source' => 'vd-store', 'ref_id' => '7'], ['gateway' => 'doku', 'invoice' => $ia]);
$r = $sr->upsert_pending('doku', $ia, ['source' => 'velocity-donasi', 'ref_id' => '9', 'amount' => 2000]);
$t = vd_pg_get_transaction('doku', $ia);
check('baris diambil sumber lain di sela baca-tulis: ditolak, tidak ditimpa', is_wp_error($r) && $r->get_error_code() === 'vdpg_invoice_taken' && $t->source === 'vd-store' && (int) $t->amount === 1000, is_wp_error($r) ? $r->get_error_code() : 'tertimpa');
$ib = "CC-B-$run"; $sr->upsert_pending('doku', $ib, ['source' => 'velocity-donasi', 'ref_id' => '9', 'amount' => 2000]);
$sr->stale = clone $sr->find('doku', $ib);
$wpdb->update($tt, ['status' => 'paid'], ['gateway' => 'doku', 'invoice' => $ib]);
$r = $sr->upsert_pending('doku', $ib, ['source' => 'velocity-donasi', 'ref_id' => '9', 'amount' => 2000]);
check('baris lunas di sela baca-tulis: tidak kembali ke pending', is_wp_error($r) && $r->get_error_code() === 'vdpg_already_paid' && vd_pg_get_transaction('doku', $ib)->status === 'paid', is_wp_error($r) ? $r->get_error_code() : 'tertimpa');
$ic = "CC-C-$run"; $sr->upsert_pending('doku', $ic, ['source' => 'vd-store', 'ref_id' => '7', 'amount' => 1000]);
$sr->stale = null;
$r = $sr->upsert_pending('doku', $ic, ['source' => 'velocity-donasi', 'ref_id' => '9', 'amount' => 2000]);
check('insert bersamaan (UNIQUE) untuk sumber lain: ditolak, baris tetap', is_wp_error($r) && vd_pg_get_transaction('doku', $ic)->source === 'vd-store', is_wp_error($r) ? $r->get_error_code() : (string) $r);
$id1 = $sr->upsert_pending('doku', $ia . 'X', ['source' => 'vd-store', 'ref_id' => '7', 'amount' => 1000]);
$id2 = $sr->upsert_pending('doku', $ia . 'X', ['source' => 'vd-store', 'ref_id' => '7', 'amount' => 1000]);
check('tulis ulang identik oleh pemilik sama tetap berhasil', is_int($id1) && $id1 > 0 && $id1 === $id2, wp_json_encode([$id1, $id2]));
$breakins = function ($q) use ($tt) { return stripos($q, "INSERT INTO `$tt`") === 0 ? 'INSERT INTO vdpg_tabel_tidak_ada (x) VALUES (1)' : $q; };
add_filter('query', $breakins);
$r = $repo->upsert_pending('doku', "CC-D-$run", ['source' => 'vd-store', 'ref_id' => '7', 'amount' => 1000]);
remove_filter('query', $breakins);
check('insert gagal karena error DB (bukan kembar): vdpg_db_error, bukan invoice_taken (0.2.7)', is_wp_error($r) && $r->get_error_code() === 'vdpg_db_error', is_wp_error($r) ? $r->get_error_code() : (string) $r);

echo "== Duitku\n";
check('alias Velocity_Addons_Duitku tersedia', class_exists('Velocity_Addons_Duitku') && defined('Velocity_Addons_Duitku::VDPG_ALIAS'));
check('Velocity_Addons_Duitku::is_active()', Velocity_Addons_Duitku::is_active());
$dinv = "DU-$run-1";
$r = vd_pg_create_payment('duitku', ['invoice' => $dinv, 'amount' => 50000, 'source' => 'velocity-donasi', 'ref_id' => '7', 'customer' => ['name' => 'Siti Aminah', 'email' => 'siti@example.com'], 'items' => [['name' => 'Donasi', 'price' => 50000, 'qty' => 1]]]);
check('buat invoice Duitku (HMAC header)', !is_wp_error($r) && !empty($GLOBALS['cap']['duitku']['sig']), is_wp_error($r) ? $r->get_error_message() : '');
check('callbackUrl mengarah ke plugin ini', ($GLOBALS['cap']['duitku']['body']['callbackUrl'] ?? '') === rest_url('vd-pg/v1/notify/duitku'));

$cb = function ($invoice, $amount, $rc, $url = null, $md5 = false) use ($mcode, $mkey) {
    $sig = $md5 ? md5($mcode . $amount . $invoice . $mkey) : hash_hmac('sha256', $mcode . $amount . $invoice, $mkey);
    return post_to($url ?: rest_url('vd-pg/v1/notify/duitku'), ['Content-Type' => 'application/x-www-form-urlencoded'],
        ['merchantCode' => $mcode, 'amount' => (string) $amount, 'merchantOrderId' => $invoice, 'resultCode' => $rc, 'reference' => 'DS-REF', 'paymentCode' => 'BC', 'signature' => $sig]);
};
$bad = post_to(rest_url('vd-pg/v1/notify/duitku'), [], ['merchantCode' => $mcode, 'amount' => '50000', 'merchantOrderId' => $dinv, 'resultCode' => '00', 'signature' => 'xxx']);
check('callback Duitku signature salah ditolak', $bad[0] === 401);
$cb($dinv, 50000, '00'); $cb($dinv, 50000, '00');
check('callback Duitku HMAC → lunas', vd_pg_get_transaction('duitku', $dinv)->status === 'paid');
check('velocity_duitku_callback (action lama) dipicu 1x, rc=00', hooks("velocity_duitku_callback $dinv rc=00") === 1, hooks("velocity_duitku_callback $dinv") . 'x');

echo "== Alias untuk plugin lama\n";
$linv = "LG-$run-1";
$raw = (new Velocity_Addons_Duitku())->createInvoice(['paymentAmount' => 30000, 'merchantOrderId' => $linv, 'productDetails' => 'Tes', 'additionalParam' => '99', 'email' => 'a@example.com', 'callbackUrl' => '', 'returnUrl' => '']);
check('createInvoice() lama mengembalikan respons Duitku mentah', is_array($raw) && ($raw['statusCode'] ?? '') === '00' && !empty($raw['paymentUrl']));
$html = do_shortcode('[tombol_bayar_duitku invoice="' . $linv . '"]');
check('shortcode [tombol_bayar_duitku] tampil', strpos($html, 'Bayar Sekarang') !== false);
$res = $cb($linv, 30000, '00', rest_url('velocityaddons/v1/duitku_callback'), true);
check('URL callback lama + signature MD5 lama diterima', $res[0] === 200 && vd_pg_get_transaction('duitku', $linv)->status === 'paid', $res[0] . ' ' . $res[1]);

echo "== vd-store: Duitku lewat alias\n";
if (class_exists('\WpStore\Domain\Payment\PaymentService')) {
    check('vd-store melihat Duitku tersedia', \WpStore\Domain\Payment\DuitkuGateway::is_available());
    $order_id = wp_insert_post(['post_type' => 'store_order', 'post_status' => 'publish', 'post_title' => 'Order tes']);
    $onum = "VS-$run";
    update_post_meta($order_id, '_store_order_number', $onum);
    update_post_meta($order_id, '_store_order_status', 'pending');
    $pi = (new \WpStore\Domain\Payment\PaymentService())->initialize_order_payment($order_id, 'duitku', ['order_number' => $onum, 'name' => 'Andi', 'email' => 'andi@example.com', 'phone' => '0811', 'items' => [['title' => 'Kopi', 'price' => 25000, 'qty' => 2]]], 50000);
    check('checkout vd-store dapat payment_url Duitku', !is_wp_error($pi) && !empty($pi['payment_url']), is_wp_error($pi) ? $pi->get_error_message() : '');
    $cb($onum, 50000, '00');
    clean_post_cache($order_id);
    $st = get_post_meta($order_id, '_store_order_status', true);
    $extra = get_post_meta($order_id, '_store_order_payment_extra', true);
    check('callback → pesanan vd-store berubah status', $st === 'processing' && ($extra['gateway_status'] ?? '') === 'paid', "status=$st");
    wp_delete_post($order_id, true);
} else {
    echo "  (vd-store tidak aktif, dilewati)\n";
}

echo "== vd-store 1.5: DOKU lewat vd-payment-gateway\n";
if (class_exists('\WpStore\Domain\Payment\VdPaymentGateway')) {
    $reg = new \WpStore\Domain\Payment\PaymentMethodRegistry();
    check('DOKU terdaftar & tersedia di registry vd-store', $reg->is_available('doku') && in_array('doku', $reg->available_methods(), true));
    $settings = get_option('wp_store_settings', []);
    $settings['payment_methods'] = ['bank_transfer', 'doku'];
    update_option('wp_store_settings', $settings);
    $pm = rest_do_request(new WP_REST_Request('GET', '/wp-store/v1/settings/payment-methods'))->get_data();
    $ids = wp_list_pluck((array) ($pm['data'] ?? []), 'name', 'id');
    check('checkout menawarkan DOKU berlabel "DOKU"', ($ids['doku'] ?? '') === 'DOKU', wp_json_encode($ids));
    $o = get_option('vdpg_doku'); update_option('vdpg_doku', array_merge($o, ['enabled' => '0']));
    $pm = rest_do_request(new WP_REST_Request('GET', '/wp-store/v1/settings/payment-methods'))->get_data();
    check('DOKU nonaktif → tidak ditawarkan walau dicentang', !in_array('doku', wp_list_pluck((array) $pm['data'], 'id'), true));
    update_option('vdpg_doku', $o);

    $make_order = function ($onum, $total) {
        $id = wp_insert_post(['post_type' => 'store_order', 'post_status' => 'publish', 'post_title' => 'Budi Santoso - 2026-10-30']);
        update_post_meta($id, '_store_order_number', $onum);
        update_post_meta($id, '_store_order_status', 'pending');
        update_post_meta($id, '_store_order_payment_method', 'doku');
        update_post_meta($id, '_store_order_total', $total);
        update_post_meta($id, '_store_order_email', 'budi@example.com');
        update_post_meta($id, '_store_order_phone', '0812-1111-2222');
        update_post_meta($id, '_store_order_items', [['title' => 'Kaos', 'price' => 75000, 'qty' => 2]]);
        return $id;
    };
    $onum = "VSD-$run";
    $oid = $make_order($onum, 175000);
    $pi = (new \WpStore\Domain\Payment\PaymentService())->initialize_order_payment($oid, 'doku', ['order_number' => $onum, 'name' => 'Budi Santoso', 'email' => 'budi@example.com', 'phone' => '0812-1111-2222', 'items' => [['title' => 'Kaos', 'price' => 75000, 'qty' => 2]]], 175000);
    check('checkout vd-store dapat payment_url DOKU', !is_wp_error($pi) && strpos($pi['payment_url'], 'doku.com') !== false && $pi['expires_at'] > time(), is_wp_error($pi) ? $pi->get_error_message() : '');
    (new \WpStore\Domain\Order\OrderService())->update_payment_data($oid, $pi);
    $body = $GLOBALS['cap']['doku']['body'];
    check('ongkir jadi baris tambahan, total line_items = 175.000', array_sum(array_map(function ($l) { return $l['price'] * $l['quantity']; }, $body['order']['line_items'])) === 175000);
    check('callback_url DOKU kembali ke halaman terima kasih', strpos($body['order']['callback_url'] ?? '', 'order=' . $onum) !== false, $body['order']['callback_url'] ?? '');
    $t = vd_pg_get_transaction('doku', $onum);
    check('transaksi vdpg: source=vd-store, ref_id=order', $t && $t->source === 'vd-store' && (int) $t->ref_id === $oid);
    $ok = ['order' => ['invoice_number' => $onum, 'amount' => 175000], 'transaction' => ['status' => 'SUCCESS'], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA']];
    $dn($ok); $dn($ok);
    clean_post_cache($oid);
    $extra = get_post_meta($oid, '_store_order_payment_extra', true);
    check('notifikasi DOKU → pesanan processing', get_post_meta($oid, '_store_order_status', true) === 'processing' && ($extra['gateway'] ?? '') === 'doku' && ($extra['payment_code'] ?? '') === 'VIRTUAL_ACCOUNT_BCA', get_post_meta($oid, '_store_order_status', true));
    check('wp_store_payment_completed dipicu 1x walau notifikasi 2x', hooks("wp_store_payment_completed $onum doku") === 1, hooks("wp_store_payment_completed $onum") . 'x');

    $onum2 = "VSE-$run";
    $oid2 = $make_order($onum2, 175000);
    $pi = (new \WpStore\Domain\Payment\PaymentService())->initialize_order_payment($oid2, 'doku', ['order_number' => $onum2, 'name' => 'Budi', 'items' => []], 175000);
    (new \WpStore\Domain\Order\OrderService())->update_payment_data($oid2, $pi);
    update_post_meta($oid2, '_store_order_payment_url', 'https://sandbox.doku.com/checkout-link-v2/LAMA');
    check('link masih berlaku dipakai apa adanya', \WpStore\Domain\Payment\VdPaymentGateway::payment_link($oid2) === 'https://sandbox.doku.com/checkout-link-v2/LAMA');
    update_post_meta($oid2, '_store_order_payment_expires_at', time() - 10);
    delete_transient('wp_store_pay_regen_' . $oid2);
    $GLOBALS['cap']['doku'] = [];
    $link = \WpStore\Domain\Payment\VdPaymentGateway::payment_link($oid2);
    check('link kedaluwarsa dibuat ulang dengan invoice sama', $link !== 'https://sandbox.doku.com/checkout-link-v2/LAMA' && ($GLOBALS['cap']['doku']['body']['order']['invoice_number'] ?? '') === $onum2 && (int) get_post_meta($oid2, '_store_order_payment_expires_at', true) > time(), is_wp_error($link) ? $link->get_error_message() : $link);
    update_post_meta($oid2, '_store_order_payment_expires_at', time() - 10);
    $GLOBALS['cap']['doku'] = [];
    $again = \WpStore\Domain\Payment\VdPaymentGateway::payment_link($oid2);
    check('buat ulang kedua dalam 5 menit ditahan (DOKU tidak dipanggil)', is_wp_error($again) && empty($GLOBALS['cap']['doku']));
    delete_transient('wp_store_pay_regen_' . $oid2);
    \WpStore\Domain\Payment\VdPaymentGateway::payment_link($oid2);
    ob_start(); \WpStore\Domain\Payment\VdPaymentGateway::render_pay_box($oid2, 'doku'); $html = ob_get_clean();
    check('tombol "Bayar Sekarang" DOKU tampil', strpos($html, 'Bayar Sekarang') !== false && strpos($html, 'doku.com') !== false);
    $s = vd_pg_sync_status('doku', $onum2);
    clean_post_cache($oid2);
    check('tombol Cek status vdpg → pesanan ikut processing', get_post_meta($oid2, '_store_order_status', true) === 'processing');
    $GLOBALS['vs_ref'] = 0;
    add_action('wp_store_payment_refunded', function () { $GLOBALS['vs_ref']++; });
    $t = vd_pg_get_transaction('doku', $onum2);
    (new \WpStore\Domain\Payment\VdPaymentGateway())->handle_status_changed($t, 'refunded', []);
    clean_post_cache($oid2);
    check('refunded: status pesanan tetap, action wp_store_payment_refunded', $GLOBALS['vs_ref'] === 1 && get_post_meta($oid2, '_store_order_status', true) === 'processing');
    wp_delete_post($oid, true); wp_delete_post($oid2, true);
} else {
    echo "  (vd-store 1.5 tidak aktif, dilewati)\n";
}

echo "\nHasil: {$GLOBALS["pass"]} lulus, {$GLOBALS["fail"]} gagal\n";
