<?php

/**
 * Plugin Name: VD Payment Gateway
 * Description: Payment gateway terpusat (DOKU, Duitku) untuk plugin dan theme Velocity Developer.
 * Version:     0.2.4
 * Author:      Dev Team Velocitydeveloper.com
 * Author URI:  https://velocitydeveloper.com
 * Text Domain: vd-payment-gateway
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VDPG_VERSION', '0.2.4');
define('VDPG_FILE', __FILE__);
define('VDPG_PATH', plugin_dir_path(__FILE__));
define('VDPG_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'VdPaymentGateway\\';
    $base_dir = VDPG_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once VDPG_PATH . 'src/functions.php';

register_activation_hook(__FILE__, ['\VdPaymentGateway\Transaction\Installer', 'install']);

add_action('plugins_loaded', function () {
    \VdPaymentGateway\Plugin::instance()->boot();
});
