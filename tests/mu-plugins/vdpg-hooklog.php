<?php
// Khusus WP uji (dipasang oleh tests/install.php): catat action yang dipicu di proses server
// ke wp-content/vdpg-hook.log supaya tests/e2e.php bisa memeriksanya.
add_action('vd_pg_payment_paid', function ($t) { file_put_contents(WP_CONTENT_DIR . '/vdpg-hook.log', 'vd_pg_payment_paid '.$t->invoice."\n", FILE_APPEND); });
add_action('velocity_duitku_callback', function ($p) { file_put_contents(WP_CONTENT_DIR . '/vdpg-hook.log', 'velocity_duitku_callback '.($p['merchantOrderId'] ?? '').' rc='.($p['resultCode'] ?? '')."\n", FILE_APPEND); });
add_action('wp_store_payment_completed', function ($order_id, $gateway, $n) { file_put_contents(WP_CONTENT_DIR . '/vdpg-hook.log', 'wp_store_payment_completed '.$n['invoice'].' '.$gateway."\n", FILE_APPEND); }, 10, 3);
