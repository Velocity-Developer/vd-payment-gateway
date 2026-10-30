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
    /** Dicocokkan per segmen kata kunci (snake/kebab/camelCase), bukan substring bebas: lihat is_sensitive(). */
    const SENSITIVE = ['secret', 'signature', 'password', 'apikey', 'merchantkey', 'authorization', 'token', 'privatekey', 'serverkey', 'clientkey',
        'sharedkey', 'encryptionkey', 'sessionkey', 'passwd', 'pwd', 'pin', 'otp', 'cvv', 'cardnumber', 'hmac'];
    /** Akhiran setelah kata sensitif dalam satu segmen tanpa pemisah: secretkey, tokenvalue, passwordhash, tokens, password1. */
    const SENSITIVE_TAILS = ['key', 'id', 'value', 'hash', 'code', 'string', 'sha', 'header', 'base64', 'confirm', 'confirmation', 's', 'keys', 'ids', 'values'];
    /** Kunci berakhiran ini berisi keterangan, bukan datanya: authorizationStatus, tokenCount, addressType. */
    const META_SUFFIXES = ['status', 'type', 'count', 'length', 'verified', 'enabled', 'required', 'format'];

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

    /*
     * Kunci data pelanggan, dibandingkan setelah dinormalkan (huruf kecil, tanpa "_"/"-"):
     * - mengandung email/phone/mobile/msisdn/address → customer_email, billingPhone, dst.
     * - berakhiran "name" dengan awalan di PII_NAME_PREFIXES → payerName, customer_va_name, first_name.
     * - "name" polos hanya bila salah satu induknya (sampai ke atas) blok pelanggan → customer_list[0].name.
     */
    const PII_CONTAINS = ['email' => 'email', 'phone' => 'phone', 'mobile' => 'phone', 'msisdn' => 'phone', 'address' => 'address'];
    const PII_EXACT = ['merchantuserinfo' => 'name', 'merchantuserid' => 'id'];
    const PII_NAME_PREFIXES = ['customer', 'customerva', 'virtualaccount', 'va', 'cardholder', 'sender', 'contact', 'payer', 'buyer', 'first', 'last', 'full', 'middle', 'billing', 'shipping', 'recipient', 'receiver', 'account', 'holder', 'user'];
    const PII_PARENTS = ['customer', 'payer', 'buyer', 'billing', 'shipping', 'recipient', 'receiver'];
    /** Kunci teks bebas yang bisa memuat email/nomor HP pelanggan; isinya disaring dengan regex. */
    const FREE_TEXT = ['additionalparam', 'productdetail', 'productdetails', 'description', 'note', 'remarks'];

    /**
     * @param mixed    $data
     * @param string   $key     kunci nilai ini
     * @param string[] $parents kunci induk (ternormalkan), dari atas
     * @param string   $inherit tipe penyamaran dari induk ('secret', 'email', 'phone', ...): semua daun di bawahnya ikut disamarkan
     */
    public static function mask($data, $key = '', $parents = [], $inherit = '')
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        $plain = self::normalize_key($key);
        $parents = (array) $parents;

        $type = $inherit === 'secret' || self::is_sensitive($key, is_array($data)) ? 'secret' : (self::pii_type($plain, $parents) ?: $inherit);

        if (is_array($data)) {
            $out = [];
            $chain = $plain !== '' && !is_numeric($key) ? array_merge($parents, [$plain]) : $parents;
            foreach ($data as $k => $v) {
                $out[$k] = self::mask($v, (string) $k, $chain, $type);
            }

            return $out;
        }
        if ($data === null || is_bool($data)) {
            return $data;
        }
        if ($type === 'secret') {
            $value = (string) $data;

            return strlen($value) > 12 ? substr($value, 0, 4) . '…' . substr($value, -4) : ($value === '' ? '' : '***');
        }
        if ($type !== '') {
            return (string) $data === '' ? '' : self::mask_pii($type, (string) $data);
        }
        if (is_string($data) && in_array($plain, self::FREE_TEXT, true)) {
            return self::scrub($data);
        }

        return $data;
    }

    private static function normalize_key($key)
    {
        return strtolower(str_replace(['_', '-', ' '], '', (string) $key));
    }

    private static function is_meta($plain)
    {
        foreach (self::META_SUFFIXES as $suffix) {
            if ($plain !== $suffix && substr($plain, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rahasia bila salah satu segmen (atau dua segmen berurutan, mis. api+key) sama dengan / berakhiran kata di SENSITIVE,
     * atau berupa kata itu + key/id (secretkey). Jadi access_token, x-signature, clientSecret, apikey kena;
     * tokenizer, secretary, authorizationStatus, tokenCount tidak.
     */
    private static function is_sensitive($key, $is_container = false)
    {
        $plain = self::normalize_key($key);
        // Kunci keterangan (tokenStatus) hanya dibiarkan bila nilainya skalar; isi array-nya tetap diperiksa sebagai rahasia.
        if ($plain === '' || is_numeric($plain) || (!$is_container && self::is_meta($plain))) {
            return false;
        }
        $segments = array_values(array_filter(array_map('strtolower', preg_split('/[_\-\s.]+|(?<=[a-z0-9])(?=[A-Z])/', (string) $key))));
        $words = $segments;
        for ($i = 1; $i < count($segments); $i++) {
            $words[] = $segments[$i - 1] . $segments[$i];
        }
        foreach ($words as $word) {
            foreach (self::SENSITIVE as $needle) {
                if ($word === $needle || substr($word, -strlen($needle)) === $needle || substr($word, -strlen($needle) - 1) === $needle . 's'
                    || (strpos($word, $needle) === 0 && self::is_sensitive_tail(substr($word, strlen($needle))))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function is_sensitive_tail($tail)
    {
        return in_array($tail, self::SENSITIVE_TAILS, true) || ctype_digit($tail);
    }

    /** @return string tipe penyamaran, atau '' bila bukan data pelanggan */
    private static function pii_type($plain, array $parents)
    {
        if ($plain === '' || is_numeric($plain) || self::is_meta($plain)) {
            return '';
        }
        if (isset(self::PII_EXACT[$plain])) {
            return self::PII_EXACT[$plain];
        }
        foreach (self::PII_CONTAINS as $needle => $type) {
            if (strpos($plain, $needle) !== false) {
                return $type;
            }
        }
        if (substr($plain, -4) === 'name') {
            $prefix = substr($plain, 0, -4);
            if ($prefix !== '' && in_array($prefix, self::PII_NAME_PREFIXES, true)) {
                return 'name';
            }
            if ($prefix === '') {
                foreach ($parents as $parent) {
                    foreach (self::PII_PARENTS as $needle) {
                        if (strpos($parent, $needle) !== false) {
                            return 'name';
                        }
                    }
                }
            }
        }

        return '';
    }

    /** bu***@example.com, 6281****6789, B*** S***, Jl.***, us*** */
    public static function mask_pii($type, $value)
    {
        $value = (string) $value;
        if (strpos($value, '@') !== false) {
            [$user, $domain] = explode('@', $value, 2);

            return mb_substr($user, 0, 2) . '***@' . $domain;
        }
        if ($type === 'phone') {
            $digits = preg_replace('/\D+/', '', $value);

            return strlen($digits) > 8 ? substr($digits, 0, 4) . str_repeat('*', strlen($digits) - 8) . substr($digits, -4) : '***';
        }
        if ($type === 'address' || $type === 'id') {
            return mb_substr($value, 0, $type === 'id' ? 2 : 3) . '***';
        }

        return implode(' ', array_map(function ($word) {
            return mb_substr($word, 0, 1) . '***';
        }, preg_split('/\s+/', trim($value))));
    }

    /** Samarkan alamat email dan nomor HP Indonesia (08…, 628…, +628…) di dalam teks bebas. */
    public static function scrub($text)
    {
        $text = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', function ($m) {
            return self::mask_pii('email', $m[0]);
        }, (string) $text);

        return preg_replace_callback('/(?<![\d\w])(?:\+?62[\s.\-]?|0)8(?:[\s.\-]?\d){7,12}(?!\d)/', function ($m) {
            return self::mask_pii('phone', $m[0]);
        }, $text);
    }

    /** Teks mentah (bukan JSON/form) untuk log: panjang asli + potongan awal yang sudah disamarkan. */
    public static function excerpt($raw, $max = 200)
    {
        $raw = (string) $raw;

        // Disaring sebelum dipotong supaya email/nomor HP di tepi potongan tidak tersisa setengah utuh.
        return ['length' => strlen($raw), 'excerpt' => mb_substr(self::scrub(mb_substr($raw, 0, $max + 100)), 0, $max)];
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
