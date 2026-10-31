<?php
/**
 * Tes velocity-donasi 3.1 + DOKU lewat vd-payment-gateway, tanpa kredensial sandbox:
 * API DOKU di-mock (pre_http_request, signature request diverifikasi), notifikasi DOKU dikirim
 * bertanda tangan ke endpoint asli (butuh `php -S 127.0.0.1:8791`). Aman dijalankan berulang.
 * Jalankan lewat tests/run-tests.sh (lihat tests/README.md).
 */
use VdPaymentGateway\Gateway\Doku\DokuSignature;
global $wpdb;
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function check($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
$dsecret = 'SK-test-123'; $dclient = 'BRN-0001-TEST';
update_option('vdpg_doku', ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => $dclient, 'secret_key' => $dsecret, 'payment_due' => 60]);
$GLOBALS['cap'] = []; $GLOBALS['mails'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['mails'][] = $atts; return true; }, 10, 2);
add_filter('pre_http_request', function ($pre, $args, $url) use ($dsecret) {
    if (strpos($url, 'api-sandbox.doku.com') === false) return $pre;
    $h = $args['headers'];
    $GLOBALS['cap']['sig'] = DokuSignature::verify($h['Signature'], $h['Client-Id'], $h['Request-Id'], $h['Request-Timestamp'], parse_url($url, PHP_URL_PATH), $args['body'], $dsecret);
    $b = json_decode((string) $args['body'], true); $GLOBALS['cap']['doku'] = $b; $GLOBALS['cap']['n'] = ($GLOBALS['cap']['n'] ?? 0) + 1;
    if ((int) $b['order']['amount'] === 77777) return ['response' => ['code' => 400], 'headers' => [], 'cookies' => [], 'body' => '{"error":{"message":"Rahasia internal DOKU"}}'];
    $exp = (new DateTime('+1 hour', new DateTimeZone('Asia/Jakarta')))->format('YmdHis');
    $tok = 'tok-' . $b['order']['invoice_number'] . '-' . $GLOBALS['cap']['n'];
    return ['response' => ['code' => 200], 'headers' => [], 'cookies' => [], 'body' => json_encode(['message' => ['SUCCESS'], 'response' => [
        'order' => ['amount' => $b['order']['amount'], 'invoice_number' => $b['order']['invoice_number'], 'session_id' => 'sess-don'],
        'payment' => ['token_id' => $tok, 'url' => 'https://sandbox.doku.com/checkout-link-v2/' . $tok, 'expired_date' => $exp]]])];
}, 10, 3);
$dn = function ($invoice, $amount, $status = 'SUCCESS') use ($dclient, $dsecret) {
    $body = json_encode(['order' => ['invoice_number' => $invoice, 'amount' => $amount], 'transaction' => ['status' => $status], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BRI']]);
    $rid = wp_generate_uuid4(); $ts = DokuSignature::timestamp();
    $sig = DokuSignature::generate($dclient, $rid, $ts, '/wp-json/vd-pg/v1/notify/doku', $body, $dsecret);
    $r = wp_remote_post(rest_url('vd-pg/v1/notify/doku'), ['timeout' => 30, 'body' => $body, 'headers' => ['Client-Id' => $dclient, 'Request-Id' => $rid, 'Request-Timestamp' => $ts, 'Signature' => $sig, 'Content-Type' => 'application/json']]);
    return is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
};
// Invoice dibuat server; tes mengusulkan nomor lewat filter velocity_donasi_new_invoice. Nilai "invoice" di form diabaikan.
$submit = function (array $params) {
    $f = null;
    if (isset($params['invoice'])) {
        $want = (string) $params['invoice'];
        $f = function ($inv, $pid, $i) use ($want) { return $i === 0 ? $want : $inv; };
        add_filter('velocity_donasi_new_invoice', $f, 10, 3);
    }
    $params['invoice'] = $params['form_invoice'] ?? '111111111'; unset($params['form_invoice']);
    $req = new WP_REST_Request('POST', '/velocity-donasi/v1/submit');
    $req->set_body_params($params);
    $data = rest_do_request($req)->get_data();
    if ($f) { remove_filter('velocity_donasi_new_invoice', $f, 10); }
    return $data;
};
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'duitku.com') === false) return $pre;
    $b = json_decode((string) $args['body'], true); $GLOBALS['cap']['duitku'] = $b;
    return ['response' => ['code' => 200], 'headers' => [], 'cookies' => [], 'body' => json_encode(['merchantCode' => 'DS0001', 'reference' => 'REF-' . $b['merchantOrderId'], 'paymentUrl' => 'https://sandbox.duitku.com/pay/' . $b['merchantOrderId'], 'statusCode' => '00', 'statusMessage' => 'SUCCESS'])];
}, 10, 3);
$last_donasi = function () { return get_posts(['post_type' => 'donasi-masuk', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'post_status' => 'any'])[0]; };
$find = function ($invoice) { $p = get_posts(['post_type' => 'donasi-masuk', 'title' => $invoice, 'post_status' => 'any', 'numberposts' => 1]); return $p ? $p[0]->ID : 0; };

$campaign = wp_insert_post(['post_type' => 'donasi', 'post_status' => 'publish', 'post_title' => 'Bantu Sekolah Desa']);
$form = ['bayar' => 'doku', 'first_name' => 'Budi Santoso', 'user_email' => 'budi@example.com', 'hp' => '0812-1111-2222', 'kodeunik' => '7', 'totaldonasi' => '50007', 'user_id' => '0', 'post_id' => (string) $campaign, 'pesan' => 'Semoga berkah'];

echo "== Form\n";
$html = do_shortcode('[velocity-donasi-button post_id="' . $campaign . '"]');
check('form donasi menampilkan pilihan DOKU', strpos($html, 'value="doku"') !== false && strpos($html, 'Payment Gateway (DOKU)') !== false);
$opt = get_option('vdpg_doku'); update_option('vdpg_doku', array_merge($opt, ['enabled' => '0']));
check('pilihan DOKU hilang bila gateway nonaktif', strpos(do_shortcode('[velocity-donasi-button post_id="' . $campaign . '"]'), 'value="doku"') === false);
$inv0 = (string) mt_rand(100000000, 999999999);
$res = $submit($form + ['invoice' => $inv0]);
check('submit DOKU saat gateway nonaktif ditolak, donasi Gagal', empty($res['success']) && get_post_meta($find($inv0), 'status', true) === 'Gagal', wp_strip_all_tags($res['html'] ?? ''));
update_option('vdpg_doku', $opt);

echo "== Donasi DOKU sampai lunas\n";
$inv = (string) mt_rand(100000000, 999999999);
$GLOBALS['cap'] = []; $GLOBALS['mails'] = [];
$res = $submit(array_merge($form, ['invoice' => $inv]));
$pid = $find($inv);
check('submit sukses + tombol bayar DOKU', !empty($res['success']) && strpos($res['html'], 'sandbox.doku.com/checkout-link-v2/') !== false, wp_strip_all_tags($res['html'] ?? ''));
check('signature request DOKU valid', !empty($GLOBALS['cap']['sig']));
$b = $GLOBALS['cap']['doku'];
check('tagihan: invoice, nominal 50.007, item kampanye', $b['order']['invoice_number'] === $inv && (int) $b['order']['amount'] === 50007 && ($b['order']['line_items'][0]['name'] ?? '') === 'Bantu Sekolah Desa', wp_json_encode($b['order']));
check('callback_url ke halaman donatur ?inv=', strpos($b['order']['callback_url'] ?? '', 'inv=' . $inv) !== false, $b['order']['callback_url'] ?? '');
check('data donatur ke DOKU (nama, email, HP 62…)', ($b['customer']['name'] ?? '') === 'Budi Santoso' && ($b['customer']['email'] ?? '') === 'budi@example.com' && ($b['customer']['phone'] ?? '') === '6281211112222', wp_json_encode($b['customer'] ?? []));
check('donasi-masuk: status Menunggu Pembayaran, metode doku, link tersimpan', get_post_meta($pid, 'status', true) === 'Menunggu Pembayaran' && get_post_meta($pid, 'metode_bayar', true) === 'doku' && strpos(get_post_meta($pid, 'pg_payment_url', true), 'doku.com') !== false);
$t = vd_pg_get_transaction('doku', $inv);
check('transaksi vdpg: source=velocity-donasi, ref_id=donasi-masuk', $t && $t->source === 'velocity-donasi' && (int) $t->ref_id === $pid);
check('email ke donatur berisi invoice & link', count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0]['to'] === 'budi@example.com' && strpos($GLOBALS['mails'][0]['message'], $inv) !== false);
ob_start(); DaftarDonasiMasuk($pid); $row = ob_get_clean();
check('riwayat donasi: tombol "Bayar via DOKU", tanpa tombol Konfirmasi', strpos($row, 'Bayar via DOKU') !== false && strpos($row, 'act=konfirmasi') === false);
check('notifikasi nominal salah tidak melunasi', $dn($inv, 7) === 200 && get_post_meta($pid, 'status', true) === 'Menunggu Pembayaran', get_post_meta($pid, 'status', true));
$GLOBALS['paid_hook'] = 0; add_action('velocity_donasi_payment_paid', function () { $GLOBALS['paid_hook']++; });
$c1 = $dn($inv, 50007); $c2 = $dn($inv, 50007);
wp_cache_flush();
check('notifikasi SUCCESS → donasi Sukses', $c1 === 200 && get_post_meta($pid, 'status', true) === 'Sukses' && get_post_meta($pid, 'pg_channel', true) === 'VIRTUAL_ACCOUNT_BRI', get_post_meta($pid, 'status', true));
check('notifikasi ganda tidak mengubah apa pun', $c2 === 200 && get_post_meta($pid, 'status', true) === 'Sukses');
ob_start(); DaftarDonasiMasuk($pid); $row = ob_get_clean();
check('riwayat donasi lunas: tanpa tombol bayar', strpos($row, 'Bayar via DOKU') === false);
check('dihitung di total donasi kampanye', in_array($pid, wp_list_pluck(get_posts(['post_type' => 'donasi-masuk', 'numberposts' => -1, 'meta_query' => [['key' => 'donasi_id', 'value' => $campaign], ['key' => 'status', 'value' => 'Sukses']]]), 'ID')));

echo "== Refund & kedaluwarsa\n";
$dn($inv, 50007, 'REFUNDED'); wp_cache_flush();
check('REFUNDED → donasi Refund', get_post_meta($pid, 'status', true) === 'Refund', get_post_meta($pid, 'status', true));
$inv2 = (string) mt_rand(100000000, 999999999);
$submit(array_merge($form, ['invoice' => $inv2])); $pid2 = $find($inv2);
$inv9 = (string) mt_rand(100000000, 999999999);
$submit(array_merge($form, ['invoice' => $inv9])); $pid9 = $find($inv9);
$dn($inv9, 50007, 'EXPIRED'); wp_cache_flush();
check('EXPIRED terlambat saat link (invoice sama) masih berlaku: diabaikan', get_post_meta($pid9, 'status', true) === 'Menunggu Pembayaran' && get_post_meta($pid9, 'pg_expired', true) === '' && velocity_donasi_pg_can_pay($pid9), get_post_meta($pid9, 'status', true));
update_post_meta($pid2, 'pg_expires_at', time() - 10);
$dn($inv2, 50007, 'EXPIRED'); wp_cache_flush();
check('EXPIRED → donasi Gagal + penanda pg_expired', get_post_meta($pid2, 'status', true) === 'Gagal' && get_post_meta($pid2, 'pg_expired', true) === '1', get_post_meta($pid2, 'status', true));
$inv8 = (string) mt_rand(100000000, 999999999);
$submit(array_merge($form, ['invoice' => $inv8])); $pid8 = $find($inv8);
update_post_meta($pid8, 'status', 'Gagal'); update_post_meta($pid8, 'pg_expires_at', time() - 10); $n = $GLOBALS['cap']['n'];
$dn($inv8, 50007, 'EXPIRED'); wp_cache_flush(); delete_option('velocity_donasi_pay_regen_' . $pid8);
ob_start(); DaftarDonasiMasuk($pid8); $row = ob_get_clean();
check('dibatalkan admin lalu EXPIRED datang: tetap Gagal, tanpa tombol, link tidak dibuat', get_post_meta($pid8, 'status', true) === 'Gagal' && get_post_meta($pid8, 'pg_expired', true) === '' && vd_pg_get_transaction('doku', $inv8)->status === 'expired' && !velocity_donasi_pg_can_pay($pid8) && strpos($row, 'Bayar via DOKU') === false && $GLOBALS['cap']['n'] === $n);

echo "== Link kedaluwarsa & invoice\n";
$inv3 = (string) mt_rand(100000000, 999999999);
$submit(array_merge($form, ['invoice' => $inv3])); $pid3 = $find($inv3);
$old = get_post_meta($pid3, 'pg_payment_url', true);
check('link masih berlaku dipakai apa adanya', velocity_donasi_pg_payment_link($pid3) === $old);
update_post_meta($pid3, 'pg_expires_at', time() - 10);
$new = velocity_donasi_pg_payment_link($pid3);
check('link kedaluwarsa dibuat ulang, invoice sama', is_string($new) && $new !== $old && $GLOBALS['cap']['doku']['order']['invoice_number'] === $inv3, is_wp_error($new) ? $new->get_error_message() : $new);
update_post_meta($pid3, 'pg_expires_at', time() - 10); $n = $GLOBALS['cap']['n'];
check('buat ulang kedua dalam 5 menit ditahan', is_wp_error(velocity_donasi_pg_payment_link($pid3)) && $GLOBALS['cap']['n'] === $n);
update_option('velocity_donasi_pay_regen_' . $pid3, time() - 400);
check('kunci basi (> 5 menit) tidak menahan buat ulang', is_string(velocity_donasi_pg_payment_link($pid3)) && $GLOBALS['cap']['n'] === $n + 1);
delete_option('velocity_donasi_pay_regen_' . $pid3);

$res = $submit(array_merge($form, ['invoice' => $inv]));
$dup = get_posts(['post_type' => 'donasi-masuk', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC'])[0];
check('invoice kembar dari form diganti invoice baru (transaksi lama tidak tertimpa)', !empty($res['success']) && $dup->post_title !== $inv && vd_pg_get_transaction('doku', $inv)->ref_id === (string) $pid && vd_pg_get_transaction('doku', $dup->post_title)->ref_id === (string) $dup->ID, $dup->post_title);
$inv4 = 'VSD-ABC123';
$res = $submit(array_merge($form, ['invoice' => $inv4]));
$dup = get_posts(['post_type' => 'donasi-masuk', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC'])[0];
check('invoice bukan angka dari form diganti', preg_match('/^\d{9}$/', $dup->post_title) === 1, $dup->post_title);
$res = $submit(array_merge($form, ['invoice' => (string) mt_rand(100000000, 999999999), 'totaldonasi' => '500']));
check('nominal < 10.000 ditolak', empty($res['success']), wp_strip_all_tags($res['html'] ?? ''));

$res = $submit(array_merge($form, ['invoice' => (string) mt_rand(100000000, 999999999), 'totaldonasi' => '15000.9']));
check('nominal bukan bilangan bulat ditolak', empty($res['success']));
$res = $submit(array_merge($form, ['invoice' => (string) mt_rand(100000000, 999999999), 'user_email' => 'bukan-email']));
check('email tidak valid ditolak', empty($res['success']) && strpos($res['html'], 'email') !== false, wp_strip_all_tags($res['html'] ?? ''));
$inv6 = (string) mt_rand(100000000, 999999999);
$res = $submit(array_merge($form, ['invoice' => $inv6, 'bayar' => 'DOKU']));
check('bayar=DOKU (huruf besar) dinormalkan ke doku', !empty($res['success']) && get_post_meta($find($inv6), 'metode_bayar', true) === 'doku', wp_strip_all_tags($res['html'] ?? ''));
$inv7 = (string) mt_rand(100000000, 999999999);
$res = $submit(array_merge($form, ['invoice' => $inv7, 'totaldonasi' => '77777']));
check('error DOKU tidak ditampilkan ke publik, disimpan untuk admin', empty($res['success']) && strpos($res['html'], 'Rahasia internal') === false && strpos(get_post_meta($find($inv7), 'pg_error', true), 'Rahasia internal') !== false, wp_strip_all_tags($res['html'] ?? ''));

echo "== Transfer bank tidak berubah\n";
$inv5 = (string) mt_rand(100000000, 999999999); $n = $GLOBALS['cap']['n'];
$res = $submit(array_merge($form, ['invoice' => $inv5, 'bayar' => 'bank']));
check('donasi bank: Pending, DOKU tidak dipanggil', !empty($res['success']) && get_post_meta($find($inv5), 'status', true) === 'Pending' && $GLOBALS['cap']['n'] === $n);
$pid5 = $find($inv5);
check('donasi bank: invoice & link detail bertoken tampil dan ada di email', strpos($res['html'], $inv5) !== false && strpos($res['html'], 't=' . velocity_donasi_invoice_token($pid5)) !== false
    && (bool) array_filter($GLOBALS['mails'], function ($m) use ($inv5) { return strpos($m['subject'], '#' . $inv5) !== false && strpos($m['message'], 'Invoice: <strong>' . $inv5) !== false; }));

echo "== Invoice dibuat di server\n";
check('form donasi tidak lagi mengirim invoice', strpos(do_shortcode('[velocity-donasi-button post_id="' . $campaign . '"]'), 'name="invoice"') === false);
$req = new WP_REST_Request('POST', '/velocity-donasi/v1/submit'); $req->set_body_params(array_merge($form, ['bayar' => 'bank', 'invoice' => $inv5]));
$res = rest_do_request($req)->get_data(); $d = $last_donasi();
check('invoice dari form (milik donasi lain) diabaikan: invoice baru 9 digit', !empty($res['success']) && $d->post_title !== $inv5 && preg_match('/^\d{9}$/', $d->post_title) === 1 && count(get_posts(['post_type' => 'donasi-masuk', 'title' => $inv5, 'post_status' => 'any', 'numberposts' => -1])) === 1, $d->post_title);
$taken_trx = (string) mt_rand(100000000, 999999999);
$wpdb->insert($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'duitku', 'invoice' => $taken_trx, 'source' => 'velocity-toko', 'ref_id' => '1', 'amount' => 1000, 'status' => 'pending', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
check('invoice yang sudah dipakai transaksi Duitku lain tidak dipakai', velocity_donasi_invoice_taken($taken_trx) && velocity_donasi_pg_unique_invoice('doku', $taken_trx, 0) !== $taken_trx);
$wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'duitku', 'invoice' => $taken_trx]);

echo "== Duitku: invoice server & pencarian invoice callback\n";
$invd = (string) mt_rand(100000000, 999999999);
$res = $submit(array_merge($form, ['invoice' => $invd, 'bayar' => 'duitku', 'form_invoice' => $inv5]));
$pidd = $find($invd);
check('donasi Duitku: merchantOrderId = invoice server (bukan dari form)', !empty($res['success']) && ($GLOBALS['cap']['duitku']['merchantOrderId'] ?? '') === $invd && get_post_meta($pidd, 'status', true) === 'Menunggu Pembayaran', wp_strip_all_tags($res['html'] ?? ''));
// Data lama: donasi bank lebih baru dengan invoice kembar tidak boleh ikut berubah.
$old = wp_insert_post(['post_type' => 'donasi-masuk', 'post_status' => 'publish', 'post_title' => $invd]);
update_post_meta($old, 'metode_bayar', 'bank'); update_post_meta($old, 'status', 'Pending'); update_post_meta($old, 'donasi_id', $campaign);
do_action('velocity_duitku_callback', ['merchantOrderId' => $invd, 'resultCode' => '00', 'amount' => '49000', 'reference' => 'R1']);
check('callback Duitku nominal beda: tidak Sukses', get_post_meta($pidd, 'status', true) === 'Menunggu Pembayaran');
do_action('velocity_duitku_callback', ['merchantOrderId' => $invd, 'resultCode' => '00', 'amount' => '50007.00', 'reference' => 'R1']);
check('callback Duitku: donasi Duitku Sukses, donasi bank berinvoice sama tidak tersentuh', get_post_meta($pidd, 'status', true) === 'Sukses' && get_post_meta($old, 'status', true) === 'Pending' && get_post_meta($old, 'metode_bayar', true) === 'bank');
do_action('velocity_duitku_callback', ['merchantOrderId' => $invd, 'resultCode' => '01', 'amount' => '50007']);
check('callback gagal yang terlambat tidak menurunkan Sukses', get_post_meta($pidd, 'status', true) === 'Sukses');
wp_trash_post($pidd); update_post_meta($pidd, 'status', 'Pending');
do_action('velocity_duitku_callback', ['merchantOrderId' => $invd, 'resultCode' => '00', 'amount' => '50007']);
check('donasi di tong sampah tidak dicari', get_post_meta($pidd, 'status', true) === 'Pending' && velocity_donasi_find_by_invoice($invd, 'duitku') === null);
check('find_by_invoice tanpa metode: terbaru yang terbit', velocity_donasi_find_by_invoice($invd)->ID === $old && velocity_donasi_find_by_invoice('') === null);
wp_delete_post($old, true); wp_delete_post($pidd, true);
$wpdb->delete($wpdb->prefix . 'vdpg_transactions', ['gateway' => 'duitku', 'invoice' => $invd]);

echo "== Bayar ulang donasi Gagal karena kedaluwarsa\n";
update_post_meta($pid2, 'pg_expires_at', time() - 10); delete_option('velocity_donasi_pay_regen_' . $pid2);
ob_start(); DaftarDonasiMasuk($pid2); $row = ob_get_clean(); clean_post_cache($pid2); wp_cache_delete($pid2, 'post_meta');
$t2 = vd_pg_get_transaction('doku', $inv2);
check('Gagal (expired): tombol bayar muncul, link baru, status kembali Menunggu Pembayaran', strpos($row, 'Bayar via DOKU') !== false && get_post_meta($pid2, 'status', true) === 'Menunggu Pembayaran' && $t2->status === 'pending' && $GLOBALS['cap']['doku']['order']['invoice_number'] === $inv2, get_post_meta($pid2, 'status', true) . '/' . $t2->status);
check('bayar ulang tidak membuat invoice/transaksi dobel', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}vdpg_transactions WHERE invoice = %s", $inv2)) === 1 && $find($inv2) === $pid2);
check('bayar ulang menghapus penanda pg_expired', get_post_meta($pid2, 'pg_expired', true) === '');
update_post_meta($pid2, 'pg_expires_at', time() - 10); $n = $GLOBALS['cap']['n'];
update_post_meta($pid2, 'status', 'Gagal'); update_post_meta($pid2, 'pg_expired', 1); $wpdb->update($wpdb->prefix . 'vdpg_transactions', ['status' => 'expired'], ['invoice' => $inv2]);
check('bayar ulang kedua dalam 5 menit ditahan', is_wp_error(velocity_donasi_pg_payment_link($pid2)) && $GLOBALS['cap']['n'] === $n);
delete_option('velocity_donasi_pay_regen_' . $pid2);
update_post_meta($pid2, 'status', 'Pending'); update_post_meta($pid2, 'status', 'Gagal');
ob_start(); DaftarDonasiMasuk($pid2); $row = ob_get_clean();
check('status diubah admin ke Gagal (penanda terhapus, transaksi expired): tanpa tombol, link tidak dibuat', strpos($row, 'Bayar via DOKU') === false && $GLOBALS['cap']['n'] === $n);
update_post_meta($pid, 'status', 'Gagal'); update_post_meta($pid, 'pg_expires_at', time() - 10);
$wpdb->update($wpdb->prefix . 'vdpg_transactions', ['status' => 'paid'], ['invoice' => $inv]);
ob_start(); DaftarDonasiMasuk($pid); $row = ob_get_clean();
check('transaksi lunas tidak pernah dibuat ulang', strpos($row, 'Bayar via DOKU') === false && !velocity_donasi_pg_can_pay($pid) && $GLOBALS['cap']['n'] === $n);

echo "== Data donatur & XSS ?inv=\n";
$tok = velocity_donasi_invoice_token($pid3);
update_post_meta($pid3, 'pg_expires_at', time() - 10); delete_option('velocity_donasi_pay_regen_' . $pid3); velocity_donasi_pg_payment_link($pid3);
$mail3 = array_values(array_filter($GLOBALS['mails'], function ($m) use ($inv3) { return strpos($m['subject'], $inv3) !== false; }));
check('return_url DOKU & email donatur memuat token', strpos($GLOBALS['cap']['doku']['order']['callback_url'] ?? '', 't=' . $tok) !== false && $mail3 && strpos($mail3[0]['message'], 't=' . $tok) !== false, $GLOBALS['cap']['doku']['order']['callback_url'] ?? '');
unset($_GET['t']);
ob_start(); DaftarDonasiMasuk($pid3); $row = ob_get_clean();
check('tanpa token: nama/email/HP tersamar', strpos($row, 'budi@example.com') === false && strpos($row, 'Budi Santoso') === false && strpos($row, '0812-1111-2222') === false && strpos($row, 'bu***@example.com') !== false && strpos($row, '0812****2222') !== false);
$_GET['t'] = 'salah' . $tok;
ob_start(); DaftarDonasiMasuk($pid3); $row = ob_get_clean();
check('token salah: tetap tersamar', strpos($row, 'budi@example.com') === false);
$_GET['t'] = $tok;
ob_start(); DaftarDonasiMasuk($pid3); $row = ob_get_clean();
check('email bagian lokal pendek tetap tersamar', velocity_donasi_mask('email', 'ab@x.id') === 'a***@x.id' && velocity_donasi_mask('email', 'a@x.id') === '***@x.id', velocity_donasi_mask('email', 'ab@x.id'));
$hr = wp_remote_head(add_query_arg(['inv' => $inv3, 't' => $tok], home_url('/')), ['timeout' => 20, 'redirection' => 0]);
check('halaman bertoken: Referrer-Policy no-referrer', wp_remote_retrieve_header($hr, 'referrer-policy') === 'no-referrer', is_wp_error($hr) ? $hr->get_error_message() : (string) wp_remote_retrieve_header($hr, 'referrer-policy'));
check('token benar: data utuh', strpos($row, 'budi@example.com') !== false && strpos($row, 'Budi Santoso') !== false);
unset($_GET['t']);
$xss = wp_insert_post(['post_type' => 'donasi-masuk', 'post_status' => 'publish', 'post_title' => '123123123']);
update_post_meta($xss, 'nama_donatur', '<img src=x onerror=alert(1)>'); update_post_meta($xss, 'donasi_id', $campaign); update_post_meta($xss, 'metode_bayar', 'bank');
$_GET['t'] = velocity_donasi_invoice_token($xss);
ob_start(); DaftarDonasiMasuk($xss); $row = ob_get_clean(); unset($_GET['t']); wp_delete_post($xss, true);
check('nama donatur di-escape', strpos($row, '<img src=x') === false && strpos($row, '&lt;img') !== false);
$_GET['inv'] = '"><script>alert(1)</script>';
$page = do_shortcode('[halaman-donatur]'); unset($_GET['inv']);
check('?inv= berisi skrip di-escape', strpos($page, '<script>alert(1)') === false && strpos($page, 'Tidak ada data') !== false);

foreach (get_posts(['post_type' => 'donasi-masuk', 'numberposts' => -1, 'post_status' => 'any', 'meta_key' => 'donasi_id', 'meta_value' => $campaign]) as $p) { wp_delete_post($p->ID, true); }
wp_delete_post($campaign, true);
echo "\nHasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
