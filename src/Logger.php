<?php

namespace VdPaymentGateway;

/**
 * Log debug gateway (permintaan API, notifikasi, perubahan status). Mati secara bawaan; dinyalakan
 * dari tab "Log". Berkas harian di uploads/vdpg-logs/ dengan nama acak, disimpan LOG_DAYS hari.
 * Nilai rahasia (secret key, signature, dll.) disamarkan sebelum ditulis.
 */
class Logger
{
    const OPTION = 'vdpg_debug_log';
    const LOG_DAYS = 14;
    const SENSITIVE = ['secret', 'signature', 'password', 'api_key', 'apikey', 'merchantkey', 'merchant_key', 'authorization', 'token'];

    public static function enabled()
    {
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * @param string $event   mis. doku.request, notify.doku, status.changed
     * @param array  $context data tambahan; nilai sensitif disamarkan otomatis
     */
    public static function log($event, array $context = [])
    {
        if (!self::enabled()) {
            return;
        }

        $dir = self::dir();
        if ($dir === '') {
            return;
        }

        $line = gmdate('Y-m-d H:i:s') . ' UTC ' . $event . ' ' . wp_json_encode(self::mask($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX);
        self::cleanup();
    }

    /** Kunci data pelanggan (dibandingkan tanpa "_"/"-", huruf kecil). "name" hanya di dalam blok pelanggan. */
    const PII = ['email', 'phone', 'phonenumber', 'mobilephone', 'customervaname', 'firstname', 'lastname', 'fullname', 'address', 'merchantuserinfo'];
    const PII_PARENTS = ['customer', 'customerdetail', 'billingaddress', 'shippingaddress'];

    public static function mask($data, $key = '', $parent = '')
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::mask($v, (string) $k, (string) $key);
            }

            return $out;
        }

        $k = strtolower(str_replace('-', '_', $key));
        foreach (self::SENSITIVE as $needle) {
            if ($k !== '' && strpos($k, $needle) !== false) {
                $value = (string) $data;

                return strlen($value) > 12 ? substr($value, 0, 4) . '…' . substr($value, -4) : ($value === '' ? '' : '***');
            }
        }

        $plain = str_replace('_', '', $k);
        $plain_parent = strtolower(str_replace(['_', '-'], '', $parent));
        if (is_string($data) && $data !== '' && (in_array($plain, self::PII, true) || ($plain === 'name' && in_array($plain_parent, self::PII_PARENTS, true)))) {
            return self::mask_pii($plain, $data);
        }

        return $data;
    }

    /** bu***@example.com, 6281****6789, B*** S***, Jl.*** */
    public static function mask_pii($type, $value)
    {
        if (strpos($value, '@') !== false) {
            [$user, $domain] = explode('@', $value, 2);

            return mb_substr($user, 0, 2) . '***@' . $domain;
        }
        if (strpos($type, 'phone') !== false) {
            $digits = preg_replace('/\D+/', '', $value);

            return strlen($digits) > 8 ? substr($digits, 0, 4) . str_repeat('*', strlen($digits) - 8) . substr($digits, -4) : '***';
        }
        if ($type === 'address') {
            return mb_substr($value, 0, 3) . '***';
        }

        return implode(' ', array_map(function ($word) {
            return mb_substr($word, 0, 1) . '***';
        }, preg_split('/\s+/', trim($value))));
    }

    public static function dir()
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }

        // Bisa dipindah ke luar folder publik lewat filter vdpg_log_dir.
        $dir = untrailingslashit((string) apply_filters('vdpg_log_dir', trailingslashit($uploads['basedir']) . 'vdpg-logs'));
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        return is_dir($dir) ? $dir : '';
    }

    /** Nama berkas memakai hash salt situs supaya tidak bisa ditebak dari luar (nginx tidak membaca .htaccess). */
    public static function file($date = null)
    {
        $date = $date ?: gmdate('Y-m-d');

        return self::dir() . '/vdpg-' . $date . '-' . substr(wp_hash('vdpg-log'), 0, 12) . '.log';
    }

    /** @return string[] path berkas log, terbaru dulu */
    public static function files()
    {
        $dir = self::dir();
        $files = $dir !== '' ? (array) glob($dir . '/vdpg-*.log') : [];
        rsort($files);

        return $files;
    }

    public static function tail($lines = 200)
    {
        $out = [];
        foreach (self::files() as $file) {
            $content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $out = array_merge(is_array($content) ? $content : [], $out);
            if (count($out) >= $lines) {
                break;
            }
        }

        return array_slice($out, -$lines);
    }

    public static function clear()
    {
        foreach (self::files() as $file) {
            @unlink($file);
        }
    }

    private static function cleanup()
    {
        if (get_transient('vdpg_log_cleanup')) {
            return;
        }
        set_transient('vdpg_log_cleanup', 1, DAY_IN_SECONDS);

        $limit = time() - self::LOG_DAYS * DAY_IN_SECONDS;
        foreach (self::files() as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
