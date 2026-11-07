<?php
/**
 * Tes REST dashboard admin vd-payment-gateway (0.3.0): izin, nonce, secret tidak keluar, validasi,
 * filter transaksi, cek status, log, dan formulir lama (tanpa JS). Jalankan lewat tests/run-tests.sh.
 * Butuh server php -S di 127.0.0.1:8791 (disiapkan run-tests.sh) untuk tes nonce/cookie lewat HTTP.
 */
use VdPaymentGateway\Gateway\Doku\DokuSignature;
use VdPaymentGateway\Logger;
use VdPaymentGateway\Transaction\TransactionRepository;

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function ar_check($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
function ar_req($method, $route, $params = [], $json = null)
{
    $r = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) { $r->set_param($k, $v); }
    if ($json !== null) { $r->set_header('Content-Type', 'application/json'); $r->set_body(wp_json_encode($json)); }
    $res = rest_do_request($r);
    return [$res->get_status(), $res->get_data()];
}

$secret = 'SK-ADMINREST-' . wp_generate_password(20, false);
$keep = [];
foreach (['vdpg_doku', 'vdpg_duitku', Logger::OPTION] as $o) { $keep[$o] = get_option($o, null); }
$doku_opt = (new \VdPaymentGateway\Gateway\Doku\DokuGateway())->option_name();
$keep[$doku_opt] = get_option($doku_opt, null);
update_option($doku_opt, ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => 'BRN-ADMINREST', 'secret_key' => $secret, 'payment_due' => 60], false);

$admin = wp_insert_user(['user_login' => 'vdpg_ar_admin', 'user_pass' => wp_generate_password(24), 'user_email' => 'ar-admin@example.test', 'role' => 'administrator']);
$editor = wp_insert_user(['user_login' => 'vdpg_ar_editor', 'user_pass' => wp_generate_password(24), 'user_email' => 'ar-editor@example.test', 'role' => 'editor']);
// Plugin lain bisa mengganti peran user baru (mis. vd_member): tetapkan ulang.
(new WP_User($admin))->set_role('administrator');
(new WP_User($editor))->set_role('editor');
$repo = new TransactionRepository();
$ids = [];

try {
    echo "1. Rute terdaftar dan izin\n";
    $routes = rest_get_server()->get_routes();
    ar_check('rute admin terdaftar', isset($routes['/vd-pg/v1/admin/transactions'], $routes['/vd-pg/v1/admin/log']));
    foreach ($routes as $route => $handlers) {
        if (strpos($route, '/vd-pg/v1/admin') !== 0) { continue; }
        foreach ($handlers as $h) {
            ar_check("permission_callback bukan __return_true: $route", isset($h['permission_callback']) && $h['permission_callback'] !== '__return_true');
        }
    }
    $calls = [['GET', '/vd-pg/v1/admin/settings/doku'], ['POST', '/vd-pg/v1/admin/settings/doku'], ['GET', '/vd-pg/v1/admin/transactions'],
        ['POST', '/vd-pg/v1/admin/transactions/1/sync'], ['GET', '/vd-pg/v1/admin/log'], ['DELETE', '/vd-pg/v1/admin/log']];
    wp_set_current_user(0);
    foreach ($calls as $c) { list($st) = ar_req($c[0], $c[1], [], $c[0] === 'POST' ? ['settings' => ['mode' => 'production']] : null); ar_check("tamu ditolak $c[0] $c[1]", $st === 401, (string) $st); }
    wp_set_current_user($editor);
    foreach ($calls as $c) { list($st) = ar_req($c[0], $c[1], [], $c[0] === 'POST' ? ['settings' => ['mode' => 'production']] : null); ar_check("editor ditolak $c[0] $c[1]", $st === 403, (string) $st); }
    ar_check('opsi DOKU tidak berubah oleh editor', get_option($doku_opt)['mode'] === 'sandbox');

    echo "2. Secret tidak pernah dikirim ke browser\n";
    wp_set_current_user($admin);
    list($st, $d) = ar_req('GET', '/vd-pg/v1/admin/settings/doku');
    ar_check('GET settings 200', $st === 200, (string) $st);
    ar_check('secret tidak ada di respons GET', strpos(wp_json_encode($d), $secret) === false && !isset($d['settings']['secret_key']));
    ar_check('penanda secret tersimpan = true', !empty($d['secrets']['secret_key']));
    ar_check('client_id & status aktif terkirim', $d['settings']['client_id'] === 'BRN-ADMINREST' && $d['active'] === true && $d['mode'] === 'sandbox');
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/settings/doku', [], ['settings' => ['enabled' => '1', 'mode' => 'production', 'client_id' => 'BRN-ADMINREST-2', 'secret_key' => '', 'payment_due' => '30']]);
    $opt = get_option($doku_opt);
    ar_check('POST settings 200', $st === 200, (string) $st . ' ' . wp_json_encode($d));
    ar_check('secret kosong tidak menghapus key tersimpan', $opt['secret_key'] === $secret);
    ar_check('nilai lain tersimpan', $opt['mode'] === 'production' && $opt['client_id'] === 'BRN-ADMINREST-2' && (int) $opt['payment_due'] === 30, wp_json_encode(array_diff_key($opt, ['secret_key' => 1])));
    ar_check('secret tidak ada di respons POST', strpos(wp_json_encode($d), $secret) === false);
    $new = 'SK-NEW-' . wp_generate_password(16, false);
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/settings/doku', [], ['settings' => ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => 'BRN-ADMINREST', 'secret_key' => $new, 'payment_due' => '60']]);
    ar_check('secret baru tersimpan dan tidak dikembalikan', get_option($doku_opt)['secret_key'] === $new && strpos(wp_json_encode($d), $new) === false);
    update_option($doku_opt, array_merge(get_option($doku_opt), ['secret_key' => $secret]), false);
    list($st, $d) = ar_req('GET', '/vd-pg/v1/admin/settings/duitku');
    ar_check('Duitku: api_key tidak dikirim', $st === 200 && !isset($d['settings']['api_key']) && array_key_exists('api_key', $d['secrets']), wp_json_encode($d));

    echo "3. Nilai tidak valid ditolak\n";
    $before = get_option($doku_opt);
    $bad = [
        'mode tidak dikenal' => ['mode' => 'live'],
        'batas bayar 0' => ['payment_due' => '0'],
        'batas bayar bukan angka' => ['payment_due' => '1e3'],
        'enabled bukan 0/1' => ['enabled' => 'ya'],
        'nilai berupa array' => ['client_id' => ['x']],
    ];
    foreach ($bad as $label => $patch) {
        list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/settings/doku', [], ['settings' => array_merge(['enabled' => '1', 'mode' => 'sandbox', 'client_id' => 'X', 'payment_due' => '60'], $patch)]);
        ar_check("ditolak 400: $label", $st === 400 && ($d['code'] ?? '') === 'vdpg_invalid_setting', $st . ' ' . ($d['code'] ?? ''));
    }
    ar_check('opsi tidak berubah setelah nilai tidak valid', get_option($doku_opt) == $before);
    list($st) = ar_req('POST', '/vd-pg/v1/admin/settings/tidakada', [], ['settings' => []]);
    ar_check('gateway tidak dikenal 404', $st === 404, (string) $st);
    list($st) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['status' => 'lunas']);
    ar_check('status filter tidak dikenal 400', $st === 400, (string) $st);
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/settings/doku', [], ['settings' => ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => '<script>alert(1)</script>BRN', 'payment_due' => '60']]);
    ar_check('teks dibersihkan (tanpa tag)', strpos(get_option($doku_opt)['client_id'], '<') === false, get_option($doku_opt)['client_id']);
    update_option($doku_opt, array_merge(get_option($doku_opt), ['client_id' => 'BRN-ADMINREST']), false);

    echo "4. Transaksi: filter, statistik, data yang dikirim\n";
    $tag = 'ARTEST' . wp_rand(1000, 9999);
    foreach ([['doku', 'pending', 10000], ['doku', 'paid', 20000], ['duitku', 'pending', 30000], ['duitku', 'expired', 40000]] as $i => $t) {
        $id = $repo->upsert_pending($t[0], "$tag-$i", ['source' => 'vdpg-admin-test', 'ref_id' => (string) $i, 'amount' => $t[2], 'payment_url' => "https://pay.test/x?t=TOKENRAHASIA$i", 'request' => ['customer' => ['email' => "pelanggan$i@example.test"]]]);
        $ids[] = $id;
        if ($t[1] !== 'pending') { $repo->transition($id, $t[1]); }
    }
    $repo->set_note($ids[0], '<img src=x onerror=alert(1)>');
    list($st, $d) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['search' => $tag]);
    ar_check('cari invoice: 4 baris', $st === 200 && $d['total'] === 4 && count($d['rows']) === 4, $st . ' ' . ($d['total'] ?? ''));
    list($st, $d2) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['search' => $tag, 'status' => 'pending']);
    ar_check('filter status pending: 2', $d2['total'] === 2);
    list($st, $d3) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['search' => $tag, 'gateway' => 'duitku', 'status' => 'expired']);
    ar_check('filter gateway+status: 1', $d3['total'] === 1 && $d3['rows'][0]['invoice'] === "$tag-3");
    list($st, $d4) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['search' => '%']);
    ar_check('karakter % di pencarian tidak jadi wildcard', $st === 200 && $d4['total'] === 0, (string) ($d4['total'] ?? ''));
    list($st, $d5) = ar_req('GET', '/vd-pg/v1/admin/transactions', ['search' => strtolower($tag) . '-2']);
    ar_check('pencarian tidak peka huruf besar/kecil', $d5['total'] === 1 && $d5['rows'][0]['invoice'] === "$tag-2", (string) ($d5['total'] ?? ''));
    $json = wp_json_encode($d);
    ar_check('payload, payment_url, dan email pelanggan tidak dikirim', strpos($json, 'TOKENRAHASIA') === false && strpos($json, 'pelanggan0@') === false && !isset($d['rows'][0]['payment_url']) && !isset($d['rows'][0]['request_payload']));
    $row0 = array_values(array_filter($d['rows'], function ($r) use ($tag) { return $r['invoice'] === "$tag-0"; }))[0];
    ar_check('catatan dikirim apa adanya (di-escape oleh x-text di browser)', $row0['note'] === '<img src=x onerror=alert(1)>' && $row0['can_sync'] === true && $row0['amount_text'] !== '');
    ar_check('statistik ada untuk tiap status', isset($d['stats']['paid']['count'], $d['stats']['pending']['amount_text']) && $d['stats']['paid']['count'] >= 1);
    $paid_before = $d['stats']['paid']['count'];

    echo "5. Cek status lewat REST\n";
    add_filter('pre_http_request', $mock = function ($pre, $args, $url) use ($secret) {
        if (strpos($url, 'api-sandbox.doku.com') === false) { return $pre; }
        $h = $args['headers']; $path = parse_url($url, PHP_URL_PATH);
        $ok = DokuSignature::verify($h['Signature'], $h['Client-Id'], $h['Request-Id'], $h['Request-Timestamp'], $path, $args['body'], $secret);
        $body = $ok ? ['order' => ['invoice_number' => basename($path), 'amount' => 10000], 'transaction' => ['status' => 'SUCCESS'], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA']] : ['error' => 'sig'];
        return ['response' => ['code' => $ok ? 200 : 401], 'body' => wp_json_encode($body), 'headers' => [], 'cookies' => []];
    }, 10, 3);
    list($st, $d) = ar_req('POST', "/vd-pg/v1/admin/transactions/{$ids[0]}/sync");
    ar_check('sync 200, baris jadi lunas', $st === 200 && $d['row']['status'] === 'paid' && $d['row']['can_sync'] === false, $st . ' ' . wp_json_encode($d));
    ar_check('statistik ikut diperbarui', ($d['stats']['paid']['count'] ?? 0) === $paid_before + 1);
    ar_check('respons sync tanpa secret', strpos(wp_json_encode($d), $secret) === false);
    remove_filter('pre_http_request', $mock, 10);
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/transactions/999999999/sync');
    ar_check('sync id tidak ada: 404', $st === 404, (string) $st);
    update_option('vdpg_duitku', ['enabled' => '0'], false);
    list($st, $d) = ar_req('POST', "/vd-pg/v1/admin/transactions/{$ids[2]}/sync");
    ar_check('sync gagal di gateway: status HTTP error (bukan 200)', $st >= 400, (string) $st);

    echo "6. Log\n";
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/log', [], ['enabled' => true]);
    ar_check('log dinyalakan', $st === 200 && $d['enabled'] === true && Logger::enabled());
    Logger::log('admin-rest.test', ['secret_key' => $secret, 'n' => 1]);
    list($st, $d) = ar_req('GET', '/vd-pg/v1/admin/log');
    ar_check('baris log terbaca, secret disamarkan', $st === 200 && $d['count'] >= 1 && strpos(wp_json_encode($d), 'admin-rest.test') !== false && strpos(wp_json_encode($d), $secret) === false);
    list($st) = ar_req('POST', '/vd-pg/v1/admin/log', [], ['enabled' => 'mungkin']);
    ar_check('nilai enabled tidak valid 400', $st === 400, (string) $st);
    list($st, $d) = ar_req('DELETE', '/vd-pg/v1/admin/log');
    ar_check('log dihapus', $st === 200 && $d['count'] === 0);
    list($st, $d) = ar_req('POST', '/vd-pg/v1/admin/log', [], ['enabled' => false]);
    ar_check('log dimatikan', $d['enabled'] === false && !Logger::enabled());

    echo "7. Lewat HTTP: cookie login tanpa nonce ditolak, halaman admin tanpa secret, formulir lama tetap jalan\n";
    $exp = time() + 3600;
    $token = WP_Session_Tokens::get_instance($admin)->create($exp);
    $logged = wp_generate_auth_cookie($admin, $exp, 'logged_in', $token);
    $auth = wp_generate_auth_cookie($admin, $exp, 'auth', $token);
    $cookies = [new WP_Http_Cookie(['name' => LOGGED_IN_COOKIE, 'value' => $logged]), new WP_Http_Cookie(['name' => AUTH_COOKIE, 'value' => $auth])];
    $_COOKIE[LOGGED_IN_COOKIE] = $logged;
    wp_set_current_user($admin);
    $nonce = wp_create_nonce('wp_rest');
    $url = rest_url('vd-pg/v1/admin/settings/doku');
    $r = wp_remote_get($url, ['cookies' => $cookies, 'timeout' => 20]);
    ar_check('cookie admin tanpa X-WP-Nonce ditolak (401)', wp_remote_retrieve_response_code($r) === 401, (string) wp_remote_retrieve_response_code($r));
    $r = wp_remote_get($url, ['cookies' => $cookies, 'headers' => ['X-WP-Nonce' => 'salah123'], 'timeout' => 20]);
    ar_check('nonce salah ditolak (403, kode rest_cookie_invalid_nonce untuk pesan "muat ulang" di admin.js)', wp_remote_retrieve_response_code($r) === 403 && strpos(wp_remote_retrieve_body($r), '"code":"rest_cookie_invalid_nonce"') !== false, (string) wp_remote_retrieve_response_code($r));
    $r = wp_remote_get($url, ['cookies' => $cookies, 'headers' => ['X-WP-Nonce' => $nonce], 'timeout' => 20]);
    ar_check('cookie + nonce benar: 200 tanpa secret', wp_remote_retrieve_response_code($r) === 200 && strpos(wp_remote_retrieve_body($r), $secret) === false, (string) wp_remote_retrieve_response_code($r));

    $menu = wp_remote_retrieve_body(wp_remote_get(admin_url('index.php'), ['cookies' => $cookies, 'timeout' => 20]));
    $slugs = ['doku' => 'vd-payment-gateway', 'duitku' => 'vd-payment-gateway-duitku', 'midtrans' => 'vd-payment-gateway-midtrans',
        'transactions' => 'vd-payment-gateway-transactions', 'log' => 'vd-payment-gateway-log'];
    foreach ($slugs as $tab => $slug) {
        ar_check("submenu $tab ada di menu admin", strpos($menu, 'admin.php?page=' . $slug . '"') !== false || strpos($menu, 'admin.php?page=' . $slug . "'") !== false);
    }
    $r = wp_remote_get(admin_url('admin.php?page=vd-payment-gateway&tab=transactions&status=paid'), ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20]);
    $loc = (string) wp_remote_retrieve_header($r, 'location');
    ar_check('URL lama ?tab=transactions dialihkan ke submenu, filter ikut', wp_remote_retrieve_response_code($r) === 302
        && strpos($loc, 'page=vd-payment-gateway-transactions') !== false && strpos($loc, 'status=paid') !== false && strpos($loc, 'tab=') === false, $loc);
    $r = wp_remote_get(admin_url('admin.php?page=vd-payment-gateway&tab=log&x%26evil=1&s=a%20b%26c'), ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20]);
    $loc = (string) wp_remote_retrieve_header($r, 'location');
    ar_check('redirect URL lama meng-encode kunci & nilai (tidak ada parameter sisipan)', strpos($loc, '&evil=') === false && strpos($loc, 'x%26evil=1') !== false && strpos($loc, 's=a%20b%26c') !== false, $loc);
    $r = wp_remote_get(admin_url('admin.php?page=vd-payment-gateway&tab=doku'), ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20]);
    ar_check('URL lama ?tab=doku (tab pertama) tidak dialihkan', wp_remote_retrieve_response_code($r) === 200);
    $html = wp_remote_retrieve_body(wp_remote_get(admin_url('admin.php?page=vd-payment-gateway-midtrans'), ['cookies' => $cookies, 'timeout' => 20]));
    ar_check('submenu Midtrans menampilkan pengaturan Midtrans', strpos($html, 'Pengaturan Midtrans') !== false && strpos($html, 'Pengaturan DOKU') === false);

    foreach ($slugs as $tab => $slug) {
        $r = wp_remote_get(admin_url('admin.php?page=' . $slug), ['cookies' => $cookies, 'timeout' => 20]);
        $html = wp_remote_retrieve_body($r);
        ar_check("halaman tab $tab 200, tanpa secret, Alpine lokal dimuat", wp_remote_retrieve_response_code($r) === 200 && strpos($html, $secret) === false
            && strpos($html, 'vd-payment-gateway/assets/vendor/alpinejs/alpine.min.js') !== false && strpos($html, 'cdn.jsdelivr') === false && strpos($html, 'x-html') === false,
            (string) wp_remote_retrieve_response_code($r));
        if ($tab === 'transactions') {
            ar_check('catatan berbahaya di-escape di HTML server', strpos($html, '<img src=x onerror') === false && strpos($html, '&lt;img src=x onerror') !== false);
        }
    }
    $r = wp_remote_get(admin_url('index.php'), ['cookies' => $cookies, 'timeout' => 20]);
    ar_check('Alpine & admin.js vdpg tidak dimuat di halaman admin lain', wp_remote_retrieve_response_code($r) === 200 && strpos(wp_remote_retrieve_body($r), 'vd-payment-gateway/assets/') === false);

    // Formulir lama (tanpa JS) tetap menyimpan, dan secret kosong tetap tidak menghapus key.
    $r = wp_remote_post(admin_url('admin-post.php'), ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20, 'body' => [
        'action' => 'vdpg_save', 'gateway' => 'doku', '_wpnonce' => wp_create_nonce('vdpg_save_doku'),
        'vdpg' => ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => 'BRN-TANPA-JS', 'secret_key' => '', 'payment_due' => '45'],
    ]]);
    wp_cache_delete('alloptions', 'options'); wp_cache_delete($doku_opt, 'options');
    $opt = get_option($doku_opt);
    ar_check('formulir tanpa JS: tersimpan + redirect', wp_remote_retrieve_response_code($r) === 302 && $opt['client_id'] === 'BRN-TANPA-JS' && (int) $opt['payment_due'] === 45 && $opt['secret_key'] === $secret, wp_remote_retrieve_response_code($r) . ' ' . $opt['client_id']);
    $r = wp_remote_post(admin_url('admin-post.php'), ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20, 'body' => [
        'action' => 'vdpg_log', '_wpnonce' => wp_create_nonce('vdpg_log'), 'enabled' => '1',
    ]]);
    wp_cache_delete('alloptions', 'options'); wp_cache_delete(Logger::OPTION, 'options');
    ar_check('formulir log tanpa JS: tersimpan', wp_remote_retrieve_response_code($r) === 302 && get_option(Logger::OPTION) === '1');
    WP_Session_Tokens::get_instance($admin)->destroy($token);
} finally {
    global $wpdb;
    foreach ($ids as $id) { $wpdb->delete(\VdPaymentGateway\Transaction\Installer::table(), ['id' => (int) $id]); }
    foreach ($keep as $o => $v) { $v === null ? delete_option($o) : update_option($o, $v, false); }
    Logger::clear();
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ([$admin, $editor] as $u) { if ($u && !is_wp_error($u)) { wp_delete_user($u); } }
    unset($_COOKIE[LOGGED_IN_COOKIE]);
}

echo "\nHasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
