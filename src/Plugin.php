<?php

namespace VdPaymentGateway;

use VdPaymentGateway\Admin\SettingsPage;
use VdPaymentGateway\Compat\LegacyDuitku;
use VdPaymentGateway\Gateway\Registry;
use VdPaymentGateway\Rest\AdminController;
use VdPaymentGateway\Rest\NotificationController;
use VdPaymentGateway\Transaction\Installer;

class Plugin
{
    private static $instance = null;

    private $registry = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot()
    {
        Installer::maybe_upgrade();
        LegacyDuitku::boot();

        add_action('rest_api_init', [new NotificationController(), 'register']);
        add_action('rest_api_init', [new AdminController(), 'register']);

        if (is_admin()) {
            (new SettingsPage())->register();
        }

        do_action('vd_pg_loaded', $this);
    }

    public function registry()
    {
        if ($this->registry === null) {
            $this->registry = new Registry();
        }

        return $this->registry;
    }
}
