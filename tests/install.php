<?php
/**
 * Siapkan WordPress uji untuk tests/run-tests.sh: pasang WP (bila belum), siteurl 127.0.0.1:8791,
 * permalink, mu-plugin pencatat hook, dan aktifkan plugin yang tersedia.
 * Jalankan dari folder WordPress uji: wp eval-file <repo>/tests/install.php --skip-plugins --skip-themes
 * (WP sudah terpasang) atau `php <repo>/tests/install.php <folder-wordpress>` (WP belum terpasang).
 */
if (!defined('ABSPATH')) {
    define('WP_INSTALLING', true);
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8791';
    require rtrim($argv[1], '/') . '/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) {
    wp_install('VDPG Uji', 'admin', 'admin@example.com', true, '', wp_generate_password(20));
}
update_option('siteurl', 'http://127.0.0.1:8791');
update_option('home', 'http://127.0.0.1:8791');
update_option('permalink_structure', '/%postname%/');

$mu = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p($mu);
copy(__DIR__ . '/mu-plugins/vdpg-hooklog.php', $mu . '/vdpg-hooklog.php');
copy(__DIR__ . '/mu-plugins/vdpg-checkout-js.php', $mu . '/vdpg-checkout-js.php');

foreach (['vd-payment-gateway', 'velocity-toko', 'velocity-donasi', 'vd-store', 'vd-marketplace'] as $slug) {
    $file = $slug . '/' . $slug . '.php';
    if (file_exists(WP_PLUGIN_DIR . '/' . $file) && !is_plugin_active($file)) {
        $r = activate_plugin($file);
        echo $file . ': ' . (is_wp_error($r) ? $r->get_error_message() : 'aktif') . "\n";
    }
}
echo "siap\n";
