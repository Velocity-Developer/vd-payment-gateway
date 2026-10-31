<?php
/**
 * Tes penyamaran log vd-payment-gateway (0.2.2). Jalankan lewat tests/run-tests.sh (lihat tests/README.md).
 */
use VdPaymentGateway\Logger;

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function lm_check($label, $ok, $info = '') { $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++; echo ($ok ? '  OK  ' : '  GAGAL ') . $label . ($info !== '' ? " — $info" : '') . "\n"; }
/** Lulus bila tidak ada satu pun nilai rahasia yang muncul utuh di hasil JSON. */
function lm_clean($masked, array $secrets) { $json = wp_json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); foreach ($secrets as $s) { if (strpos($json, (string) $s) !== false) { return "bocor: $s | $json"; } } return true; }
function lm_t($label, $data, array $secrets) { $r = lm_clean(Logger::mask($data), $secrets); lm_check($label, $r === true, $r === true ? '' : $r); }

echo "1. Nilai array / angka di bawah kunci PII\n";
lm_t('address:{street}', ['address' => ['street' => 'Jl Rahasia 5', 'city' => 'Kota Rahasia']], ['Jl Rahasia 5', 'Kota Rahasia']);
lm_t('email:[a,b]', ['email' => ['ani@b.com', 'cdx@d.com']], ['ani@b.com', 'cdx@d.com']);
lm_t('phone angka', ['phone' => 6281234567890], ['6281234567890']);
lm_t('customer:{phone:int}', ['customer' => ['phone' => 81234567890, 'name' => 'Budi Santoso']], ['81234567890', 'Budi', 'Santoso']);
$m = Logger::mask(['phone' => 6281234567890]);
lm_check('angka disamarkan dengan format nomor HP', $m['phone'] === '6281*****7890', (string) $m['phone']);
$m = Logger::mask(['billing_address' => ['first_name' => 'Siti', 'phone' => '081299998888', 'line1' => 'Jl Mawar 9']]);
lm_check('anak blok alamat memakai tipenya sendiri', $m['billing_address']['phone'] === '0812****8888' && $m['billing_address']['first_name'] === 'S***' && $m['billing_address']['line1'] === 'Jl ***', wp_json_encode($m));

echo "2. Variasi nama kunci & rantai induk\n";
lm_t('customer_email', ['customer_email' => 'budi@example.com'], ['budi@example.com']);
lm_t('billing_phone', ['billing_phone' => '0812-1111-2222'], ['0812-1111-2222', '081211112222']);
lm_t('payerName', ['payerName' => 'Budi Santoso'], ['Budi', 'Santoso']);
lm_t('merchantUserId (Duitku)', ['merchantUserId' => 'budi@example.com', 'x' => ['merchantUserId' => 'budisantoso99']], ['budi@example.com', 'budisantoso99']);
lm_t('mobileNumber / msisdn', ['mobileNumber' => '081211112222', 'msisdn' => '6281211112222'], ['081211112222', '6281211112222']);
lm_t('customerVaName / customer_va_name', ['customerVaName' => 'Budi Santoso', 'customer_va_name' => 'Ani Lestari'], ['Budi', 'Ani', 'Lestari']);
lm_t('customer_list[].name', ['customer_list' => [['name' => 'Budi Santoso'], ['name' => 'Ani Lestari']]], ['Budi', 'Ani']);
lm_t('customer.details[0].name (induk jauh)', ['customer' => ['details' => [['name' => 'Budi Santoso']]]], ['Budi']);
lm_t('additionalParam berisi email & HP', ['additionalParam' => 'nama=Budi;email=budi@example.com;hp=+62 812-1111-2222'], ['budi@example.com', '812-1111-2222']);
lm_t('productDetail berisi email & HP', ['productDetail' => 'Donasi dari ani@mail.id (08121112223)'], ['ani@mail.id', '08121112223']);
$m = Logger::mask(['order' => ['invoice_number' => 'INV-0812345678901', 'amount' => 175000, 'line_items' => [['name' => 'Kaos', 'price' => 75000]]], 'product_name' => 'Kaos', 'paid' => true, 'note' => null]);
lm_check('data non-pelanggan tetap utuh', $m['order']['invoice_number'] === 'INV-0812345678901' && $m['order']['amount'] === 175000 && $m['order']['line_items'][0]['name'] === 'Kaos' && $m['product_name'] === 'Kaos' && $m['paid'] === true && $m['note'] === null, wp_json_encode($m));

echo "3. Teks mentah\n";
$raw = '<html>Error for budi@example.com phone 081211112222 ' . str_repeat('x', 3000) . '</html>';
$e = Logger::excerpt($raw);
lm_check('excerpt: panjang asli + maks 200 karakter', $e['length'] === strlen($raw) && mb_strlen($e['excerpt']) <= 200, $e['length'] . '/' . mb_strlen($e['excerpt']));
lm_check('excerpt: email & HP disamarkan', strpos($e['excerpt'], 'budi@example.com') === false && strpos($e['excerpt'], '081211112222') === false && strpos($e['excerpt'], 'bu***@example.com') !== false, $e['excerpt']);
lm_check('scrub: +62 812 1111 2222', strpos(Logger::scrub('hp +62 812 1111 2222.'), '1111') === false, Logger::scrub('hp +62 812 1111 2222.'));
lm_check('scrub: angka panjang lain tidak diubah', Logger::scrub('VA 8889940812345678 ts 20261030101010') === 'VA 8889940812345678 ts 20261030101010');

echo "4. Kunci sensitif bernilai array\n";
lm_t('signature:{...}', ['signature' => ['value' => 'HMACSHA256=abcdefghijklmnopqrstu', 'alg' => 'sha256-long-value-x']], ['abcdefghijklmnopqrstu', 'sha256-long-value-x']);
lm_t('token:[..] & secret_key', ['token' => ['tok_1234567890abcdef'], 'secret_key' => 'SK-test-123'], ['tok_1234567890abcdef', 'SK-test-123']);
lm_t('Api-Key (tanda hubung)', ['Api-Key' => 'duitku-key-xyz-123456'], ['duitku-key-xyz-123456']);

echo "5. Header & IP notifikasi\n";
$long = str_repeat('A', 5000);
$v = \VdPaymentGateway\Rest\NotificationController::header_value('<script>x</script>' . $long);
lm_check('header disaring & dibatasi 255', mb_strlen($v) === 255 && strpos($v, '<script>') === false, mb_strlen($v));
update_option('vdpg_debug_log', '1');
$file = Logger::file();
@unlink($file);
$req = new WP_REST_Request('POST', '/vd-pg/v1/notify/tidakada');
$req->set_url_params(['gateway' => 'tidakada']);
$req->set_header('user_agent', $long);
$req->set_header('x_forwarded_for', $long);
$req->set_header('request_id', '<b>RID</b>');
$req->set_body('email=; raw budi@example.com 081211112222 ' . $long);
$req->set_header('content_type', 'text/plain');
(new \VdPaymentGateway\Rest\NotificationController())->handle($req);
$line = (string) @file_get_contents($file);
lm_check('entri notifikasi kecil (< 1500 byte)', $line !== '' && strlen($line) < 1500, strlen($line) . ' byte');
lm_check('entri notifikasi tanpa email/HP utuh & tag', strpos($line, 'budi@example.com') === false && strpos($line, '081211112222') === false && strpos($line, '<b>') === false, substr($line, 0, 300));

echo "6. (0.2.3) Awalan nama baru\n";
lm_t('virtualAccountName / virtual_account_name', ['virtualAccountName' => 'Budi Santoso', 'virtual_account_name' => 'Ani Lestari', 'vaName' => 'Rina Putri'], ['Budi', 'Ani', 'Rina']);
lm_t('cardholderName / senderName / contactName', ['cardholderName' => 'Budi Santoso', 'senderName' => 'Ani Lestari', 'contact_name' => 'Rina Putri'], ['Budi', 'Ani', 'Rina']);

echo "7. (0.2.3) Pesan error disaring\n";
$req = new WP_REST_Request('POST', '/vd-pg/v1/notify/doku');
$req->set_url_params(['gateway' => 'doku']);
$m = new ReflectionMethod(\VdPaymentGateway\Rest\NotificationController::class, 'error_response'); $m->setAccessible(true);
@unlink($file);
$m->invoke(new \VdPaymentGateway\Rest\NotificationController(), new WP_Error('x', 'Gagal untuk budi@example.com / 081211112222', ['status' => 400]), 'doku', []);
$line = (string) @file_get_contents($file);
lm_check('result error notifikasi tanpa email/HP utuh', $line !== '' && strpos($line, 'budi@example.com') === false && strpos($line, '081211112222') === false && strpos($line, 'bu***@example.com') !== false, trim($line));
@unlink($file);
$f = function () { return new WP_Error('http_request_failed', 'cURL error for ani@mail.id 6281211112222'); };
add_filter('pre_http_request', $f);
update_option('vdpg_doku', ['enabled' => '1', 'mode' => 'sandbox', 'client_id' => 'BRN-0001-TEST', 'secret_key' => 'SK-test-123', 'payment_due' => 60]);
\VdPaymentGateway\Plugin::instance()->registry()->get('doku')->create_payment(['invoice' => 'LM-' . wp_rand(), 'amount' => 10000, 'customer' => ['name' => 'Budi', 'email' => 'budi@example.com'], 'items' => []]);
remove_filter('pre_http_request', $f);
$line = (string) @file_get_contents($file);
lm_check('error HTTP DOKU tanpa email/HP utuh', strpos($line, '"error":') !== false && strpos($line, 'ani@mail.id') === false && strpos($line, '6281211112222') === false, substr(trim($line), -160));

echo "8. (0.2.3) Kunci keterangan tidak disamarkan, rahasia tetap\n";
$m = Logger::mask(['authorizationStatus' => 'AUTHORIZED', 'authorization_status' => 'OK', 'tokenCount' => 3, 'tokenizer' => 'v2', 'secretary' => 'Rina', 'addressType' => 'HOME', 'signatureType' => 'HMAC']);
lm_check('authorizationStatus, tokenCount, tokenizer, secretary, addressType utuh', $m === ['authorizationStatus' => 'AUTHORIZED', 'authorization_status' => 'OK', 'tokenCount' => 3, 'tokenizer' => 'v2', 'secretary' => 'Rina', 'addressType' => 'HOME', 'signatureType' => 'HMAC'], wp_json_encode($m));
lm_t('rahasia tetap tersamar', ['Authorization' => 'Bearer abcdefghijklmnop123', 'access_token' => 'atk_abcdefghijk123', 'accessToken' => 'atk2_abcdefghijk', 'clientSecret' => 'cs_abcdefghijklmn', 'secretkey' => 'SK-xyzxyzxyz', 'secret_key' => 'SK-test-123', 'x-api-key' => 'APIKEY-abcdefgh', 'apikey' => 'APIK2-abcdefgh', 'merchantKey' => 'MK-abcdefghij', 'tokenId' => 'tid_abcdefghijk', 'x-signature' => 'HMACSHA256=zzzzzzzzzz', 'password' => 'rahasia99'],
    ['abcdefghijklmnop123', 'atk_abcdefghijk123', 'atk2_abcdefghijk', 'cs_abcdefghijklmn', 'SK-xyzxyzxyz', 'SK-test-123', 'APIKEY-abcdefgh', 'APIK2-abcdefgh', 'MK-abcdefghij', 'tid_abcdefghijk', 'HMACSHA256=zzzzzzzzzz', 'rahasia99']);

echo "9. (0.2.4) Rahasia huruf kecil tanpa pemisah, jamak, kunci baru, meta bernilai array\n";
$keys = ['tokenvalue', 'secretvalue', 'secretcode', 'passwordhash', 'passwordconfirm', 'signaturevalue', 'signaturestring', 'authorizationheader', 'signaturebase64', 'password1', 'token2', 'secret2',
    'tokens', 'secrets', 'passwords', 'signatures', 'accesstokens', 'apikeys', 'merchantkeys', 'secretsha',
    'privatekey', 'private_key', 'serverKey', 'clientkey', 'sharedkey', 'encryptionkey', 'sessionkey', 'passwd', 'pwd', 'pin', 'otp', 'cvv', 'cardnumber', 'card_number', 'hmac'];
$data = []; $vals = [];
foreach ($keys as $i => $k) { $data[$k] = 'RHS' . $i . '-abcdefghijklmnop'; $vals[] = $data[$k]; }
lm_t(count($keys) . ' kunci rahasia tersamar', $data, $vals);
lm_t('tokenStatus bernilai array diperiksa', ['tokenStatus' => ['value' => 'tok_zzzzzzzzzzzzzzzz']], ['tok_zzzzzzzzzzzzzzzz']);
$keep = ['secretary' => 'x', 'tokenizer' => 'x', 'authorizationStatus' => 'AUTHORIZED', 'tokenStatus' => 'ACTIVE', 'shipping' => 'JNE', 'spinner' => 'x', 'pinned' => 'x', 'mapping' => 'x', 'options' => 'x', 'tokeninfo' => 'x', 'secretname' => 'x'];
$m = Logger::mask($keep);
lm_check('kunci bukan rahasia tetap utuh', $m === $keep, wp_json_encode(array_diff_assoc($m, $keep)));

echo "10. (0.2.8) Token di query URL (callback_url berisi ?t=)\n";
$u = 'https://toko.test/akun/?invoice=INV123&t=abcdef0123456789abcd&lang=id';
$m = Logger::mask(['order' => ['callback_url' => $u, 'callback_url_result' => $u]]);
lm_check('nilai t= disamarkan, invoice & parameter lain utuh', $m['order']['callback_url'] === 'https://toko.test/akun/?invoice=INV123&t=***&lang=id', $m['order']['callback_url']);
$m = Logger::mask(['return_url' => 'https://x.test/?access_token=zzzzzzzz&page=2', 'url' => 'https://x.test/a?b=1']);
lm_check('parameter sensitif lain (access_token) disamarkan, URL biasa utuh', $m['return_url'] === 'https://x.test/?access_token=***&page=2' && $m['url'] === 'https://x.test/a?b=1', wp_json_encode($m));

echo "11. (0.2.9) Token URL di dalam teks bebas (scrub/excerpt)\n";
$raw = 'Redirect ke https://toko.test/akun/?invoice=INV9&t=abcdef0123456789abcd selesai, lalu http://a.test/x?access_token=zzzzzzzz.';
$sc = Logger::scrub($raw);
lm_check('URL dalam teks: t= dan access_token disamarkan, invoice utuh', strpos($sc, 'abcdef0123456789abcd') === false && strpos($sc, 'zzzzzzzz') === false && strpos($sc, 'invoice=INV9&t=***') !== false, $sc);
$json = wp_json_encode(['order' => ['callback_url' => 'https://toko.test/akun/?invoice=INV9&t=abcdef0123456789abcd']]);
$ex = Logger::excerpt('<html>' . $json . '</html>');
lm_check('cuplikan respons mentah (JSON https:\\/\\/): t= disamarkan', strpos($ex['excerpt'], 'abcdef0123456789abcd') === false && strpos($ex['excerpt'], 't=***') !== false, $ex['excerpt']);
$u = 'https://toko.test/?invoice=INV9\\u0026t=abcdef0123456789abcd';
lm_check('pemisah JSON \\u0026 dan HTML &amp; dikenali', strpos(Logger::scrub('"' . $u . '"'), 'abcdef0123456789abcd') === false && strpos(Logger::scrub('<a href="https://x.test/?a=1&amp;t=abcdef0123456789abcd">'), 'abcdef0123456789abcd') === false, Logger::scrub('"' . $u . '"'));
lm_check('teks tanpa token tidak berubah', Logger::scrub('lihat https://x.test/a?b=1&c=2 ya') === 'lihat https://x.test/a?b=1&c=2 ya');

echo "\nHasil: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
