<?php

namespace VdPaymentGateway\Transaction;

class Installer
{
    const DB_VERSION = '2';
    const OPTION = 'vdpg_db_version';

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'vdpg_transactions';
    }

    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        // dbDelta butuh format persis: dua spasi sebelum definisi PRIMARY KEY.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gateway varchar(32) NOT NULL,
            invoice varchar(64) NOT NULL,
            source varchar(64) NOT NULL DEFAULT '',
            ref_id varchar(64) NOT NULL DEFAULT '',
            amount bigint(20) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            reference varchar(128) NOT NULL DEFAULT '',
            channel varchar(64) NOT NULL DEFAULT '',
            payment_url text NULL,
            request_payload longtext NULL,
            response_payload longtext NULL,
            notification_payload longtext NULL,
            note varchar(255) NOT NULL DEFAULT '',
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY gateway_invoice (gateway,invoice),
            KEY source_ref (source,ref_id),
            KEY status (status)
        ) $charset;";

        dbDelta($sql);

        update_option(self::OPTION, self::DB_VERSION, false);

        \VdPaymentGateway\Compat\DuitkuImporter::run();
    }

    public static function maybe_upgrade()
    {
        if (get_option(self::OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }
}
