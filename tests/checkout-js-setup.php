<?php
/**
 * Pembantu tes checkout-js.mjs (bukan suite tersendiri). Dipanggil: wp eval-file checkout-js-setup.php <aksi> [skenario].
 * aksi "setup": siapkan halaman checkout, produk, pengaturan vd-store, keranjang admin; cetak JSON {url, cookies}.
 * aksi "orders": cetak jumlah pesanan store_order yang dibuat tes ini.
 * aksi "cleanup": hapus data tes, kembalikan pengaturan vd-store dan aktifkan lagi vd-marketplace bila tadi aktif.
 */
$action = $args[0] ?? '';
$scenario = $args[1] ?? '';
$backup_key = 'vdpg_test_checkout_js_backup';
global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$cleanup = function () use ($backup_key, $wpdb) {
    foreach (get_posts(['post_type' => ['store_product', 'store_order', 'page'], 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_vdpg_checkout_js', 'fields' => 'ids']) as $id) {
        wp_delete_post($id, true);
    }
    foreach (get_posts(['post_type' => 'store_order', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_store_order_email', 'meta_value' => 'checkout-js@example.com']) as $id) {
        wp_delete_post($id, true);
    }
    $backup = get_option($backup_key, null);
    if (is_array($backup)) {
        update_option('wp_store_settings', $backup['settings']);
        if (!empty($backup['marketplace'])) {
            activate_plugin('vd-marketplace/vd-marketplace.php', '', false, true);
        }
        delete_option($backup_key);
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}store_carts SET cart = %s WHERE user_id = 1", '[]'));
};

if ($action === 'cleanup') {
    $cleanup();
    echo "ok\n";
    return;
}

if ($action === 'orders') {
    echo count(get_posts(['post_type' => 'store_order', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_store_order_email', 'meta_value' => 'checkout-js@example.com'])), "\n";
    return;
}

if ($action !== 'setup' || !in_array($scenario, ['cod-off', 'shipping-off', 'all-digital'], true)) {
    fwrite(STDERR, "aksi/skenario tidak dikenal\n");
    return;
}

$cleanup();
// mu-plugin pendamping (mematikan wptexturize selama tes); disalin juga oleh install.php.
$mu = WP_CONTENT_DIR . '/mu-plugins';
if (!file_exists($mu . '/vdpg-checkout-js.php')) {
    wp_mkdir_p($mu);
    copy(__DIR__ . '/mu-plugins/vdpg-checkout-js.php', $mu . '/vdpg-checkout-js.php');
}
$settings = get_option('wp_store_settings', []);
$settings = is_array($settings) ? $settings : [];
// vd-marketplace mengambil alih shortcode [wp_store_checkout]; nonaktifkan sementara, cleanup mengaktifkannya lagi.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$marketplace = is_plugin_active('vd-marketplace/vd-marketplace.php');
update_option($backup_key, ['settings' => $settings, 'marketplace' => $marketplace], false);
if ($marketplace) {
    deactivate_plugins('vd-marketplace/vd-marketplace.php', true);
}

$mark = function ($id) { update_post_meta($id, '_vdpg_checkout_js', 1); return $id; };
$page = $mark(wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Checkout JS Test', 'post_content' => '[wp_store_checkout]']));
$digital = $scenario === 'all-digital';
$pid = $mark(wp_insert_post(['post_type' => 'store_product', 'post_status' => 'publish', 'post_title' => $digital ? 'E-book Tes' : 'Kaos Tes']));
update_post_meta($pid, '_store_price', 25000);
update_post_meta($pid, '_store_weight_kg', $digital ? 0 : 0.2);
update_post_meta($pid, '_store_product_type', $digital ? 'digital' : 'physical');
update_post_meta($pid, '_store_is_digital', $digital ? 1 : 0);
update_post_meta($pid, '_store_stock', 100);

$settings['page_checkout'] = $page;
$settings['payment_methods'] = ['bank_transfer', 'cod'];
$settings['shipping_mode'] = $scenario === 'shipping-off' ? 'off' : 'normal';
$settings['allow_cod'] = $scenario === 'cod-off' ? '0' : '1';
$settings['disable_shipping_for_digital'] = $digital ? 1 : 0;
update_option('wp_store_settings', $settings);

// Keranjang admin (user 1) lewat REST supaya bentuknya sama dengan dari browser.
wp_set_current_user(1);
$req = new WP_REST_Request('POST', '/wp-store/v1/cart');
$req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$req->set_header('Content-Type', 'application/json');
$req->set_body(wp_json_encode(['id' => $pid, 'qty' => 1]));
$res = rest_do_request($req);

$exp = time() + 3600;
echo wp_json_encode([
    'url' => get_permalink($page),
    'cart_status' => $res->get_status(),
    'cookies' => [
        ['name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie(1, $exp, 'auth'), 'path' => '/'],
        ['name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie(1, $exp, 'logged_in'), 'path' => '/'],
    ],
]), "\n";
