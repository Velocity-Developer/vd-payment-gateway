<?php
/**
 * Gateway Midtrans (Snap): body permintaan, header, notifikasi bertanda tangan (lewat HTTP ke endpoint asli),
 * pemetaan status, nominal tidak cocok, cek status. Mock API Midtrans lewat pre_http_request.
 * Jalankan lewat run-tests.sh (butuh php -S 127.0.0.1:8791).
 */
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function mcheck($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
$GLOBALS['php_warn'] = [];
set_error_handler(function ($no, $str, $file, $line) { if (strpos($file, 'vd-payment-gateway') !== false) { $GLOBALS['php_warn'][] = "$str @ " . basename($file) . ":$line"; } return false; });

$skey = 'SB-Mid-server-TEST123';
update_option('vdpg_midtrans', ['enabled' => '1', 'mode' => 'sandbox', 'merchant_id' => 'G000', 'client_key' => 'SB-Mid-client-X', 'server_key' => $skey, 'expiry' => 30, 'enabled_payments' => 'bca_va, gopay']);
update_option('vdpg_debug_log', '1');
$GLOBALS['cap'] = [];
$GLOBALS['status_reply'] = ['status_code' => '404', 'status_message' => "Transaction doesn't exist."];
add_filter('pre_http_request', function ($pre, $args, $url) use ($skey) {
    $resp = function ($code, $body) { return ['response' => ['code' => $code, 'message' => ''], 'body' => json_encode($body), 'headers' => [], 'cookies' => []]; };
    if (strpos($url, 'midtrans.com') === false) return $pre;
    $h = $args['headers'];
    $auth = ($h['Authorization'] ?? '') === 'Basic ' . base64_encode($skey . ':');
    if (strpos($url, '/snap/v1/transactions') !== false) {
        $GLOBALS['cap']['snap'] = ['url' => $url, 'auth' => $auth, 'headers' => $h, 'body' => json_decode($args['body'], true)];
        if (!$auth) return $resp(401, ['error_messages' => ['Access denied']]);
        return $resp(201, ['token' => 'tok-abc', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-abc']);
    }
    if (preg_match('#/v2/([^/]+)/status$#', parse_url($url, PHP_URL_PATH), $m)) {
        $GLOBALS['cap']['status'] = ['auth' => $auth, 'invoice' => rawurldecode($m[1])];
        return $resp((string) ($GLOBALS['status_reply']['status_code'] ?? '') === '404' ? 404 : 200, $GLOBALS['status_reply']);
    }
    return $pre;
}, 10, 3);

$run = strtoupper(substr(md5(uniqid('', true)), 0, 6));
$sig = function ($inv, $code, $gross) use ($skey) { return hash('sha512', $inv . $code . $gross . $skey); };
$notify = function (array $b) {
    $r = wp_remote_post(rest_url('vd-pg/v1/notify/midtrans'), ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($b), 'timeout' => 20]);
    return is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
};
$payload = function ($inv, $tstatus, $gross, $extra = []) use ($sig) {
    $code = in_array($tstatus, ['settlement', 'capture', 'refund', 'partial_refund'], true) ? '200' : ($tstatus === 'pending' ? '201' : '202');
    return array_merge(['order_id' => $inv, 'status_code' => $code, 'gross_amount' => $gross, 'transaction_status' => $tstatus,
        'transaction_id' => 'trx-' . $inv, 'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca', 'va_number' => '123']],
        'signature_key' => $sig($inv, $code, $gross)], $extra);
};
$st = function ($inv) { $t = vd_pg_get_transaction('midtrans', $inv); return $t ? $t->status : '-'; };

echo "== Midtrans: buat pembayaran\n";
mcheck('gateway midtrans terdaftar & aktif', function_exists('vd_pg_is_available') && vd_pg_is_available('midtrans'));
$inv = "MT-$run-1";
$r = vd_pg_create_payment('midtrans', ['invoice' => $inv, 'amount' => 175000, 'source' => 'vd-store', 'ref_id' => '42', 'return_url' => home_url('/thanks'),
    'customer' => ['name' => 'Budi Santoso', 'email' => 'budi@example.com', 'phone' => '0812-3456-789', 'address' => 'Jl. Mawar 1', 'city' => 'Sleman', 'postcode' => '55281'],
    'items' => [['name' => 'Kaos dengan nama produk yang sangat panjang sekali melebihi lima puluh karakter', 'price' => 75000, 'qty' => 2]]]);
mcheck('buat pembayaran Midtrans', !is_wp_error($r) && $r['payment_url'] === 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-abc', is_wp_error($r) ? $r->get_error_message() : '');
$c = $GLOBALS['cap']['snap'] ?? [];
$b = $c['body'] ?? [];
mcheck('URL sandbox Snap + Basic auth server key', strpos($c['url'] ?? '', 'https://app.sandbox.midtrans.com/') === 0 && !empty($c['auth']));
mcheck('X-Override-Notification ke endpoint plugin', ($c['headers']['X-Override-Notification'] ?? '') === rest_url('vd-pg/v1/notify/midtrans'));
mcheck('order_id & gross_amount', ($b['transaction_details'] ?? []) === ['order_id' => $inv, 'gross_amount' => 175000]);
$items = $b['item_details'] ?? [];
mcheck('total item_details = gross_amount (ongkir jadi baris tambahan)', array_sum(array_map(function ($l) { return $l['price'] * $l['quantity']; }, $items)) === 175000 && count($items) === 2);
mcheck('nama item dipotong 50 karakter', mb_strlen($items[0]['name'] ?? '') === 50);
mcheck('expiry 30 menit dari pengaturan', ($b['expiry']['duration'] ?? 0) === 30 && ($b['expiry']['unit'] ?? '') === 'minute' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \+0700$/', $b['expiry']['start_time'] ?? ''));
mcheck('callbacks.finish = return_url', ($b['callbacks']['finish'] ?? '') === home_url('/thanks'));
mcheck('enabled_payments dari pengaturan', ($b['enabled_payments'] ?? []) === ['bca_va', 'gopay']);
mcheck('customer_details first/last name + alamat', ($b['customer_details']['first_name'] ?? '') === 'Budi' && ($b['customer_details']['last_name'] ?? '') === 'Santoso' && ($b['customer_details']['billing_address']['city'] ?? '') === 'Sleman');
mcheck('transaksi tercatat pending', $st($inv) === 'pending');
$r2 = vd_pg_create_payment('midtrans', ['invoice' => 'INV/001', 'amount' => 1000, 'source' => 'uji', 'ref_id' => '1']);
mcheck('invoice dengan karakter tidak sah ditolak sebelum memanggil API', is_wp_error($r2) && $r2->get_error_code() === 'vdpg_invalid_invoice');
$items_over = vd_pg_create_payment('midtrans', ['invoice' => "MT-$run-D", 'amount' => 50000, 'source' => 'uji', 'ref_id' => '9', 'items' => [['name' => 'A', 'price' => 60000, 'qty' => 1]]]);
mcheck('item > amount (diskon): item_details tidak dikirim', !is_wp_error($items_over) && !isset($GLOBALS['cap']['snap']['body']['item_details']));

echo "== Midtrans: notifikasi\n";
$p = $payload($inv, 'settlement', '175000.00');
$bad = $p; $bad['signature_key'] = str_repeat('a', 128);
mcheck('signature salah ditolak 401', $notify($bad) === 401 && $st($inv) === 'pending');
$tampered = $p; $tampered['gross_amount'] = '1000.00';
mcheck('gross_amount diubah tanpa signature baru ditolak 401', $notify($tampered) === 401 && $st($inv) === 'pending');
mcheck('pending tetap pending', $notify($payload($inv, 'pending', '175000.00')) === 200 && $st($inv) === 'pending');
mcheck('deny tetap pending (bisa coba metode lain)', $notify($payload($inv, 'deny', '175000.00')) === 200 && $st($inv) === 'pending');
mcheck('settlement → paid', $notify($p) === 200 && $st($inv) === 'paid');
$t = vd_pg_get_transaction('midtrans', $inv);
mcheck('channel bank_transfer:bca tercatat', $t && $t->channel === 'bank_transfer:bca', $t ? $t->channel : '');
mcheck('notifikasi ganda tetap 200, status paid', $notify($p) === 200 && $st($inv) === 'paid');
mcheck('expire setelah paid tidak menurunkan status', $notify($payload($inv, 'expire', '175000.00')) === 200 && $st($inv) === 'paid');
mcheck('partial_refund: tetap paid', $notify($payload($inv, 'partial_refund', '175000.00')) === 200 && $st($inv) === 'paid');
mcheck('refund → refunded', $notify($payload($inv, 'refund', '175000.00')) === 200 && $st($inv) === 'refunded');

$inv2 = "MT-$run-2";
(new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('midtrans', $inv2, ['source' => 'uji', 'ref_id' => '2', 'amount' => 50000]);
mcheck('settlement nominal beda: tidak lunas', $notify($payload($inv2, 'settlement', '40000.00')) === 200 && $st($inv2) === 'pending');
mcheck('capture + challenge tetap pending', $notify($payload($inv2, 'capture', '50000.00', ['payment_type' => 'credit_card', 'fraud_status' => 'challenge'])) === 200 && $st($inv2) === 'pending');
mcheck('capture + accept → paid', $notify($payload($inv2, 'capture', '50000.00', ['payment_type' => 'credit_card', 'fraud_status' => 'accept', 'bank' => 'bni'])) === 200 && $st($inv2) === 'paid');

$inv3 = "MT-$run-3";
(new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('midtrans', $inv3, ['source' => 'uji', 'ref_id' => '3', 'amount' => 20000]);
mcheck('expire → expired', $notify($payload($inv3, 'expire', '20000.00')) === 200 && $st($inv3) === 'expired');
mcheck('invoice tak dikenal → 404', $notify($payload("MT-$run-X", 'settlement', '1.00')) === 404);
mcheck('body bukan JSON → 400', (int) wp_remote_retrieve_response_code(wp_remote_post(rest_url('vd-pg/v1/notify/midtrans'), ['body' => 'x=1', 'timeout' => 20])) === 400);

$opt = get_option('vdpg_midtrans'); $opt['signature_key'] = 'SIG-SECRET-xyz'; update_option('vdpg_midtrans', $opt);
$inv5 = "MT-$run-5";
(new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('midtrans', $inv5, ['source' => 'uji', 'ref_id' => '5', 'amount' => 15000]);
$p5 = $payload($inv5, 'settlement', '15000.00'); $p5['signature_key'] = hash('sha512', $inv5 . '200' . '15000.00' . 'SIG-SECRET-xyz');
mcheck('notifikasi bertanda Signature Secret Key diterima', $notify($p5) === 200 && $st($inv5) === 'paid');
$p6 = $payload("MT-$run-6", 'settlement', '1.00'); $p6['signature_key'] = hash('sha512', "MT-$run-6" . '200' . '1.00' . 'kunci-lain');
mcheck('signature dengan kunci lain tetap ditolak', $notify($p6) === 401);

echo "== Midtrans: cek status\n";
$inv4 = "MT-$run-4";
(new \VdPaymentGateway\Transaction\TransactionRepository())->upsert_pending('midtrans', $inv4, ['source' => 'uji', 'ref_id' => '4', 'amount' => 30000]);
$s = vd_pg_sync_status('midtrans', $inv4);
mcheck('status 404 (belum dibayar) → tetap pending, bukan error', !is_wp_error($s) && $st($inv4) === 'pending' && !empty($GLOBALS['cap']['status']['auth']), is_wp_error($s) ? $s->get_error_message() : '');
$GLOBALS['status_reply'] = ['status_code' => '200', 'transaction_status' => 'settlement', 'order_id' => $inv4, 'gross_amount' => '30000.00', 'payment_type' => 'qris', 'issuer' => 'gopay', 'transaction_id' => 't4'];
$s = vd_pg_sync_status('midtrans', $inv4);
mcheck('cek status settlement → paid, channel qris:gopay', !is_wp_error($s) && $st($inv4) === 'paid' && vd_pg_get_transaction('midtrans', $inv4)->channel === 'qris:gopay');

$GLOBALS['status_reply'] = ['status_code' => '401', 'status_message' => 'Unauthorized'];
$s = vd_pg_sync_status('midtrans', $inv4);
mcheck('cek status tanpa transaction_status → WP_Error', is_wp_error($s));
$GLOBALS['status_reply'] = ['status_code' => '200', 'transaction_status' => 'expire', 'order_id' => 'LAIN-1', 'gross_amount' => '30000.00'];
mcheck('cek status order_id lain → WP_Error', is_wp_error(vd_pg_sync_status('midtrans', $inv4)));

echo "== Midtrans: log & pengaturan\n";
$logf = \VdPaymentGateway\Logger::files();
$log = $logf ? (string) file_get_contents($logf[0]) : '';
mcheck('server key & header Authorization tidak tertulis di log', strpos($log, $skey) === false && strpos($log, base64_encode($skey . ':')) === false && stripos($log, '"authorization"') === false && strpos($log, 'Basi') === false);
$pub = \VdPaymentGateway\Admin\SettingsPage::public_settings(vd_pg_gateway('midtrans'));
mcheck('server key & signature key tidak dikirim ke browser', strpos(wp_json_encode($pub), $skey) === false && strpos(wp_json_encode($pub), 'SIG-SECRET-xyz') === false);
update_option('vdpg_midtrans', ['enabled' => '1', 'mode' => 'sandbox', 'server_key' => '']);
mcheck('tanpa server key → tidak aktif', !vd_pg_gateway('midtrans')->is_active());

mcheck('tanpa PHP warning/notice', empty($GLOBALS['php_warn']), implode('; ', array_slice($GLOBALS['php_warn'], 0, 3)));
echo "Hasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
