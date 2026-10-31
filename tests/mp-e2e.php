<?php
/**
 * Tes vd-marketplace 1.1 + DOKU (mock DOKU, checkout REST asli). Aman dijalankan berulang.
 * Jalankan lewat tests/run-tests.sh (lihat tests/README.md).
 */
use VdPaymentGateway\Gateway\Doku\DokuSignature;
$GLOBALS["pass"] = 0; $GLOBALS["fail"] = 0;
function check($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
$dsecret = 'SK-test-123'; $dclient = 'BRN-0001-TEST';
update_option('vdpg_doku', ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => $dclient, 'secret_key' => $dsecret, 'payment_due' => 60]);
$GLOBALS['cap'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) use ($dsecret) {
    if (strpos($url, 'api-sandbox.doku.com') === false) return $pre;
    $b = json_decode((string) $args['body'], true); $GLOBALS['cap']['doku'] = $b;
    $exp = (new DateTime('+1 hour', new DateTimeZone('Asia/Jakarta')))->format('YmdHis');
    return ['response' => ['code' => 200], 'headers' => [], 'cookies' => [], 'body' => json_encode(['message' => ['SUCCESS'], 'response' => [
        'order' => ['amount' => $b['order']['amount'], 'invoice_number' => $b['order']['invoice_number'], 'session_id' => 'sess-mp'],
        'payment' => ['token_id' => 'tok-mp', 'url' => 'https://sandbox.doku.com/checkout-link-v2/tok-mp', 'expired_date' => $exp]]])];
}, 10, 3);
$dn = function ($payload) use ($dclient, $dsecret) {
    $body = json_encode($payload); $rid = wp_generate_uuid4(); $ts = DokuSignature::timestamp();
    $sig = DokuSignature::generate($dclient, $rid, $ts, '/wp-json/vd-pg/v1/notify/doku', $body, $dsecret);
    $r = wp_remote_post(rest_url('vd-pg/v1/notify/doku'), ['timeout' => 30, 'body' => $body, 'headers' => ['Client-Id' => $dclient, 'Request-Id' => $rid, 'Request-Timestamp' => $ts, 'Signature' => $sig, 'Content-Type' => 'application/json']]);
    return is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
};

$s = get_option('wp_store_settings', []); $s = is_array($s) ? $s : [];
$s['payment_methods'] = ['bank_transfer', 'doku']; $s['shipping_mode'] = 'off'; $s['collect_address'] = '0';
update_option('wp_store_settings', $s);

echo "== vd-marketplace 1.1: pengaturan\n";
$methods = \VelocityMarketplace\Support\Settings::payment_methods();
check('DOKU ada di metode marketplace', in_array('doku', $methods, true), implode(',', $methods));
check('gateway_flags DOKU = true', !empty(\VelocityMarketplace\Support\Settings::gateway_flags()['doku']));
$o = get_option('vdpg_doku'); update_option('vdpg_doku', array_merge($o, ['enabled' => '0']));
check('DOKU nonaktif → hilang dari metode marketplace', !in_array('doku', \VelocityMarketplace\Support\Settings::payment_methods(), true));
update_option('vdpg_doku', $o);

echo "== checkout marketplace (REST asli) dengan DOKU\n";
$seller = get_user_by('login', 'seller-tes') ?: get_user_by('id', wp_create_user('seller-tes', wp_generate_password(), 'seller-tes@example.com'));
$buyer = get_user_by('login', 'buyer-tes') ?: get_user_by('id', wp_create_user('buyer-tes', wp_generate_password(), 'buyer-tes@example.com'));
$pid = wp_insert_post(['post_type' => 'store_product', 'post_status' => 'publish', 'post_title' => 'Produk MP Tes', 'post_author' => $seller->ID]);
update_post_meta($pid, '_store_product_type', 'physical'); update_post_meta($pid, '_store_price', 60000); update_post_meta($pid, '_store_stock', 10); update_post_meta($pid, '_store_weight_kg', 0.5);
wp_set_current_user($buyer->ID);
$cart = new \VelocityMarketplace\Modules\Cart\CartRepository(); $cart->clear();
$up = $cart->upsert_item($pid, 2);
check('produk masuk keranjang', !is_wp_error($up), is_wp_error($up) ? $up->get_error_message() : '');
$req = new WP_REST_Request('POST', '/velocity-marketplace/v1/checkout');
$req->set_header('Content-Type', 'application/json');
$req->set_body(wp_json_encode(['name' => 'Rina Pembeli', 'email' => 'rina@example.com', 'phone' => '081233334444', 'address' => 'Jl. Tes 1', 'destination_province_id' => '1', 'destination_province_name' => 'Bali', 'destination_city_id' => '17', 'destination_city_name' => 'Badung', 'destination_subdistrict_id' => '258', 'destination_subdistrict_name' => 'Kuta', 'payment_method' => 'doku']));
$res = (new \VelocityMarketplace\Modules\Checkout\CheckoutController())->process_order($req);
$data = $res instanceof WP_REST_Response ? $res->get_data() : (is_wp_error($res) ? ['message' => $res->get_error_message()] : []);
$status = $res instanceof WP_REST_Response ? $res->get_status() : 0;
$oid = (int) ($data['order_id'] ?? $data['id'] ?? 0);
if (!$oid) { $q = get_posts(['post_type' => 'store_order', 'posts_per_page' => 1, 'fields' => 'ids', 'author' => $buyer->ID, 'orderby' => 'ID', 'order' => 'DESC']); $oid = (int) ($q[0] ?? 0); }
$inv = (string) get_post_meta($oid, 'vmp_invoice', true);
check('checkout berhasil & redirect ke halaman bayar DOKU', $status < 300 && strpos(wp_json_encode($data), 'doku.com') !== false, $status . ' ' . substr(wp_json_encode($data), 0, 200));
check('meta gateway marketplace terisi (doku, url, pending)', get_post_meta($oid, 'vmp_gateway_name', true) === 'doku' && strpos(get_post_meta($oid, 'vmp_gateway_payment_url', true), 'doku.com') !== false && get_post_meta($oid, 'vmp_status', true) === 'pending_payment');
check('tagihan DOKU = 120.000, invoice = invoice marketplace', (int) ($GLOBALS['cap']['doku']['order']['amount'] ?? 0) === 120000 && ($GLOBALS['cap']['doku']['order']['invoice_number'] ?? '') === $inv, $inv);
$t = vd_pg_get_transaction('doku', $inv);
check('transaksi vdpg source=vd-store, ref_id=order', $t && $t->source === 'vd-store' && (int) $t->ref_id === $oid);
// Link kedaluwarsa dibuka dari halaman thanks/tracking vd-store: tidak boleh dibuat ulang dengan total penuh.
$url0 = get_post_meta($oid, '_store_order_payment_url', true); $exp0 = get_post_meta($oid, '_store_order_payment_expires_at', true);
update_post_meta($oid, '_store_order_payment_expires_at', time() - 10); delete_transient('wp_store_pay_regen_' . $oid); $GLOBALS['cap']['doku'] = null;
$lk = \WpStore\Domain\Payment\VdPaymentGateway::payment_link($oid);
check('link kedaluwarsa pesanan marketplace tidak dibuat ulang (tanpa tagihan total penuh)', is_wp_error($lk) && $lk->get_error_code() === 'wp_store_pay_link_expired' && empty($GLOBALS['cap']['doku']) && get_post_meta($oid, '_store_order_payment_url', true) === $url0, is_wp_error($lk) ? $lk->get_error_code() : (string) $lk);
update_post_meta($oid, '_store_order_payment_expires_at', $exp0);
$ok = ['order' => ['invoice_number' => $inv, 'amount' => 120000], 'transaction' => ['status' => 'SUCCESS'], 'channel' => ['id' => 'QRIS']];
$c1 = $dn($ok); $c2 = $dn($ok);
clean_post_cache($oid); wp_cache_flush();
check('notifikasi DOKU diterima (200, 200)', $c1 === 200 && $c2 === 200, "$c1 $c2");
check('pesanan marketplace → processing', get_post_meta($oid, 'vmp_status', true) === 'processing' && get_post_meta($oid, 'vmp_gateway_status', true) === 'paid', get_post_meta($oid, 'vmp_status', true) . '/' . get_post_meta($oid, 'vmp_gateway_status', true));
$g = get_post_meta($oid, 'vmp_shipping_groups', true); $gs = is_array($g) ? array_unique(array_column($g, 'status')) : []; check('grup pengiriman (bila ada) ikut processing; ongkir off = tanpa grup', $gs === [] || $gs === ['processing'], wp_json_encode($gs));
$notes = get_user_meta($buyer->ID, \VelocityMarketplace\Modules\Notification\NotificationRepository::USER_META_KEY, true);
$note = is_array($notes) ? wp_json_encode($notes, JSON_UNESCAPED_UNICODE) : '';
check('notifikasi pembeli menyebut DOKU', $note && strpos($note, 'dikonfirmasi oleh DOKU') !== false, substr($note, 0, 160));
check('stok berkurang 1x (10 → 8)', (int) get_post_meta($pid, '_store_stock', true) === 8, get_post_meta($pid, '_store_stock', true));

wp_delete_post($oid, true); wp_delete_post($pid, true);
echo "\nHasil: {$GLOBALS["pass"]} lulus, {$GLOBALS["fail"]} gagal\n";
