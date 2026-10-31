<?php
/**
 * Plugin Name: VDPG test - checkout-js
 * Description: Hanya untuk situs uji. Selama tes checkout-js berjalan (opsi cadangan tes ada), wptexturize dimatikan:
 *              tema blok (Twenty Twenty-Five) men-texturize seluruh HTML template sehingga kutip atribut Alpine
 *              di checkout vd-store berubah jadi kutip miring dan memunculkan error JS yang bukan objek tes.
 *              Notice PHP juga tidak ditampilkan (WP_DEBUG_DISPLAY situs uji) agar respons REST tetap JSON valid.
 */
if (get_option('vdpg_test_checkout_js_backup')) {
    add_filter('run_wptexturize', '__return_false');
    @ini_set('display_errors', '0');
}
