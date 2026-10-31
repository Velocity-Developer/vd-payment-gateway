<?php

namespace VdPaymentGateway\Admin;

use VdPaymentGateway\Logger;
use VdPaymentGateway\PaymentService;
use VdPaymentGateway\Plugin;
use VdPaymentGateway\Transaction\TransactionRepository;

class SettingsPage
{
    const SLUG = 'vd-payment-gateway';
    const CAPABILITY = 'manage_options';
    /** Versi Alpine.js di assets/vendor/alpinejs (lihat README.md di folder itu). */
    const ALPINE_VERSION = '3.14.8';

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_vdpg_save', [$this, 'save']);
        add_action('admin_post_vdpg_sync', [$this, 'sync']);
        add_action('admin_post_vdpg_log', [$this, 'save_log']);
    }

    public function menu()
    {
        add_menu_page(
            __('Payment Gateway', 'vd-payment-gateway'),
            __('Payment Gateway', 'vd-payment-gateway'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-money-alt',
            58
        );
    }

    private function url($tab, array $extra = [])
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $extra), admin_url('admin.php'));
    }

    public function enqueue($hook)
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('vdpg-admin', VDPG_URL . 'assets/css/admin.css', [], VDPG_VERSION);

        // Alpine lokal, hanya di halaman ini. admin.js mendaftarkan komponen lewat event alpine:init,
        // jadi harus dimuat sebelum Alpine. Tanpa JS, semua formulir tetap tersimpan lewat admin-post.php.
        wp_register_script('vdpg-admin', VDPG_URL . 'assets/js/admin.js', [], VDPG_VERSION, ['in_footer' => true]);
        wp_register_script('vdpg-alpine', VDPG_URL . 'assets/vendor/alpinejs/alpine.min.js', ['vdpg-admin'], self::ALPINE_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        wp_localize_script('vdpg-admin', 'vdpgAdmin', [
            'restUrl' => esc_url_raw(rest_url(\VdPaymentGateway\Rest\AdminController::NS . '/admin/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'saving' => __('Menyimpan...', 'vd-payment-gateway'),
                'saved' => __('Pengaturan disimpan.', 'vd-payment-gateway'),
                'secretSaved' => __('•••••••• (tersimpan)', 'vd-payment-gateway'),
                'active' => __('Aktif', 'vd-payment-gateway'),
                'inactive' => __('Nonaktif', 'vd-payment-gateway'),
                'logOn' => __('Mencatat', 'vd-payment-gateway'),
                'logOff' => __('Mati', 'vd-payment-gateway'),
                'confirmClear' => __('Hapus semua log?', 'vd-payment-gateway'),
                'error' => __('Gagal terhubung ke server. Coba lagi, atau muat ulang halaman.', 'vd-payment-gateway'),
                'lines' => __('%d baris terakhir', 'vd-payment-gateway'),
                'page' => __('Halaman %1$d dari %2$d (%3$d transaksi)', 'vd-payment-gateway'),
            ],
        ]);
        wp_enqueue_script('vdpg-admin');
        wp_enqueue_script('vdpg-alpine');
    }

    /** Nilai pengaturan untuk browser: secret diganti penanda tersimpan/tidak, nilainya tidak pernah dikirim. */
    public static function public_settings($gateway)
    {
        $options = method_exists($gateway, 'options') ? $gateway->options() : (array) get_option($gateway->option_name(), []);
        $values = [];
        $secrets = [];
        foreach ($gateway->settings_fields() as $key => $field) {
            if ($field['type'] === 'secret') {
                $secrets[$key] = (string) ($options[$key] ?? '') !== '';
                continue;
            }
            $values[$key] = (string) ($options[$key] ?? '');
        }

        return [
            'gateway' => $gateway->id(),
            'settings' => $values,
            'secrets' => $secrets,
            'active' => (bool) $gateway->is_active(),
            'mode' => (string) ($options['mode'] ?? ''),
        ];
    }

    /**
     * Bersihkan masukan pengaturan gateway. Secret yang dikosongkan tetap memakai nilai tersimpan.
     * $strict (REST): nilai pilihan/angka yang tidak valid ditolak (WP_Error 400), bukan diganti bawaan.
     */
    public static function sanitize_settings($gateway, array $input, $strict = false)
    {
        $current = get_option($gateway->option_name(), []);
        $current = is_array($current) ? $current : [];
        $clean = [];

        foreach ($gateway->settings_fields() as $key => $field) {
            $raw = $input[$key] ?? '';
            if (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            if (!is_scalar($raw)) {
                if ($strict) {
                    return self::invalid($field['label']);
                }
                $raw = '';
            }
            $raw = (string) $raw;
            switch ($field['type']) {
                case 'checkbox':
                    if ($strict && !in_array($raw, ['', '0', '1'], true)) {
                        return self::invalid($field['label']);
                    }
                    $clean[$key] = $raw === '1' ? '1' : '0';
                    break;
                case 'select':
                    if ($strict && !array_key_exists($raw, $field['choices'])) {
                        return self::invalid($field['label']);
                    }
                    $clean[$key] = array_key_exists($raw, $field['choices']) ? $raw : (string) key($field['choices']);
                    break;
                case 'secret':
                    $raw = trim($raw);
                    $clean[$key] = $raw !== '' ? $raw : (string) ($current[$key] ?? '');
                    break;
                case 'number':
                    if ($strict && (!preg_match('/^\d+$/', trim($raw)) || (int) $raw < 1)) {
                        return self::invalid($field['label']);
                    }
                    $clean[$key] = max(1, (int) $raw);
                    break;
                default:
                    $clean[$key] = sanitize_text_field($raw);
            }
        }

        return $clean;
    }

    private static function invalid($label)
    {
        /* translators: %s: nama isian */
        return new \WP_Error('vdpg_invalid_setting', sprintf(__('Nilai "%s" tidak valid.', 'vd-payment-gateway'), $label), ['status' => 400]);
    }

    public function render()
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $gateways = Plugin::instance()->registry()->all();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($tab === '' || (!in_array($tab, ['transactions', 'log'], true) && !isset($gateways[$tab]))) {
            $tab = (string) key($gateways);
        }
        ?>
        <div class="wrap vdpg-wrap">
            <div class="vdpg-topnav">
                <div class="vdpg-topnav__brand">
                    <span class="vdpg-topnav__logo dashicons dashicons-money-alt" aria-hidden="true"></span>
                    <h1 class="vdpg-topnav__title"><?php esc_html_e('Payment Gateway', 'vd-payment-gateway'); ?></h1>
                    <span class="vdpg-topnav__version">v<?php echo esc_html(VDPG_VERSION); ?></span>
                </div>
                <nav class="vdpg-topnav__links" aria-label="<?php esc_attr_e('Navigasi Payment Gateway', 'vd-payment-gateway'); ?>">
                    <?php foreach ($gateways as $id => $gateway) : ?>
                        <a href="<?php echo esc_url($this->url($id)); ?>" class="vdpg-topnav__link<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
                            <span class="vdpg-dot<?php echo $gateway->is_active() ? ' is-on' : ''; ?>" data-vdpg-dot="<?php echo esc_attr($id); ?>" aria-hidden="true"></span>
                            <?php echo esc_html($gateway->label()); ?>
                        </a>
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url($this->url('transactions')); ?>" class="vdpg-topnav__link<?php echo $tab === 'transactions' ? ' is-active' : ''; ?>"<?php echo $tab === 'transactions' ? ' aria-current="page"' : ''; ?>>
                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                        <?php esc_html_e('Transaksi', 'vd-payment-gateway'); ?>
                    </a>
                    <a href="<?php echo esc_url($this->url('log')); ?>" class="vdpg-topnav__link<?php echo $tab === 'log' ? ' is-active' : ''; ?>"<?php echo $tab === 'log' ? ' aria-current="page"' : ''; ?>>
                        <span class="dashicons dashicons-media-text" aria-hidden="true"></span>
                        <?php esc_html_e('Log', 'vd-payment-gateway'); ?>
                    </a>
                </nav>
            </div>
            <hr class="wp-header-end">
            <?php $this->notice(); ?>
            <?php
            if ($tab === 'transactions') {
                $this->render_transactions();
            } elseif ($tab === 'log') {
                $this->render_log();
            } elseif (isset($gateways[$tab])) {
                $this->render_gateway($gateways[$tab]);
            }
            ?>
            <div class="vdpg-footer">
                <small>Powered by <a href="https://velocitydeveloper.com/" target="_blank" rel="noopener">velocitydeveloper.com</a></small>
            </div>
        </div>
        <?php
    }

    private function notice()
    {
        // Pemberitahuan dari aksi REST (Alpine). Tersembunyi tanpa JS (x-cloak).
        ?>
        <div x-data class="notice vdpg-live-notice" x-cloak x-show="$store.vdpgNotice.text !== ''"
            :class="$store.vdpgNotice.type === 'error' ? 'notice-error' : 'notice-success'" role="status" aria-live="polite">
            <p x-text="$store.vdpgNotice.text"></p>
        </div>
        <?php
        $message = isset($_GET['vdpg_msg']) ? sanitize_text_field(wp_unslash($_GET['vdpg_msg'])) : '';
        if ($message === '') {
            return;
        }
        $type = isset($_GET['vdpg_type']) && $_GET['vdpg_type'] === 'error' ? 'error' : 'success';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($message));
    }

    /** Kotak bergaya kartu dengan judul, subjudul, dan badge opsional. */
    private function section_open($title, $subtitle = '', array $badges = [])
    {
        ?>
        <div class="vdpg-section">
            <div class="vdpg-section__header">
                <div>
                    <h2 class="vdpg-section__title"><?php echo esc_html($title); ?></h2>
                    <?php if ($subtitle !== '') : ?>
                        <p class="vdpg-section__subtitle"><?php echo esc_html($subtitle); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($badges) : ?>
                    <div class="vdpg-section__badges">
                        <?php foreach ($badges as $badge) : ?>
                            <span class="vdpg-badge vdpg-badge--<?php echo esc_attr($badge[0]); ?>"<?php echo isset($badge[2]) ? ' ' . $badge[2] : ''; // phpcs:ignore -- atribut Alpine tetap dari kode ini ?>><?php echo esc_html($badge[1]); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="vdpg-section__body">
        <?php
    }

    private function section_close()
    {
        echo '</div></div>';
    }

    private function render_gateway($gateway)
    {
        $options = method_exists($gateway, 'options') ? $gateway->options() : (array) get_option($gateway->option_name(), []);
        $badges = [array_merge(
            $gateway->is_active() ? ['success', __('Aktif', 'vd-payment-gateway')] : ['muted', __('Nonaktif', 'vd-payment-gateway')],
            [':class="{\'vdpg-badge--success\': active, \'vdpg-badge--muted\': !active}" x-text="active ? i18n.active : i18n.inactive"']
        )];
        if (!empty($options['mode'])) {
            $badges[] = array_merge(
                $options['mode'] === 'production' ? ['info', 'Production'] : ['warning', 'Sandbox'],
                [':class="{\'vdpg-badge--info\': mode === \'production\', \'vdpg-badge--warning\': mode !== \'production\'}" x-text="mode === \'production\' ? \'Production\' : \'Sandbox\'"']
            );
        }
        $state = self::public_settings($gateway);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            x-data="vdpgSettings(<?php echo esc_attr(wp_json_encode(['gateway' => $state['gateway'], 'active' => $state['active'], 'mode' => $state['mode']])); ?>)"
            @submit.prevent="save($el)">
            <input type="hidden" name="action" value="vdpg_save">
            <input type="hidden" name="gateway" value="<?php echo esc_attr($gateway->id()); ?>">
            <?php wp_nonce_field('vdpg_save_' . $gateway->id()); ?>
            <?php $this->section_open(
                /* translators: %s: nama gateway */
                sprintf(__('Pengaturan %s', 'vd-payment-gateway'), $gateway->label()),
                __('Kredensial dan perilaku pembayaran. Gateway hanya dipakai bila aktif dan kredensial lengkap.', 'vd-payment-gateway'),
                $badges
            ); ?>
                <?php foreach ($gateway->settings_fields() as $key => $field) :
                    $name = 'vdpg[' . $key . ']';
                    $id = 'vdpg_' . $gateway->id() . '_' . $key;
                    $value = $options[$key] ?? '';
                    ?>
                    <div class="vdpg-form-group vdpg-form-group--<?php echo esc_attr($field['type']); ?>">
                        <div class="vdpg-form-left">
                            <label class="vdpg-form-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
                            <?php if (!empty($field['hint'])) : ?>
                                <small class="vdpg-form-hint"><?php echo esc_html($field['hint']); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="vdpg-form-right">
                            <?php switch ($field['type']) {
                                case 'checkbox':
                                    printf(
                                        '<label class="vdpg-switch"><input type="checkbox" id="%s" name="%s" value="1" %s><span class="vdpg-switch__slider" aria-hidden="true"></span></label>',
                                        esc_attr($id),
                                        esc_attr($name),
                                        checked($value, '1', false)
                                    );
                                    break;
                                case 'select':
                                    printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
                                    foreach ($field['choices'] as $choice => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($choice), selected($value, $choice, false), esc_html($label));
                                    }
                                    echo '</select>';
                                    break;
                                case 'secret':
                                    printf(
                                        '<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s" data-vdpg-secret>',
                                        esc_attr($id),
                                        esc_attr($name),
                                        $value !== '' ? esc_attr__('•••••••• (tersimpan)', 'vd-payment-gateway') : ''
                                    );
                                    break;
                                case 'number':
                                    printf('<input type="number" min="1" class="small-text" id="%s" name="%s" value="%s">', esc_attr($id), esc_attr($name), esc_attr($value));
                                    break;
                                default:
                                    printf('<input type="text" class="regular-text" id="%s" name="%s" value="%s">', esc_attr($id), esc_attr($name), esc_attr($value));
                            } ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php $this->section_close(); ?>
            <?php if (method_exists($gateway, 'notification_url')) : ?>
                <?php $this->section_open(__('Notification URL', 'vd-payment-gateway'), __('Isikan URL ini di Back Office gateway (menu Integration / Notification).', 'vd-payment-gateway')); ?>
                    <code class="vdpg-code"><?php echo esc_html($gateway->notification_url()); ?></code>
                <?php $this->section_close(); ?>
            <?php endif; ?>
            <div class="vdpg-actions">
                <?php submit_button(__('Simpan Perubahan', 'vd-payment-gateway'), 'primary', 'submit', false, [':disabled' => 'busy']); ?>
                <span class="spinner" :class="{'is-active': busy}" aria-hidden="true"></span>
            </div>
        </form>
        <?php
    }

    public function save()
    {
        $gateway = Plugin::instance()->registry()->get(isset($_POST['gateway']) ? wp_unslash($_POST['gateway']) : '');
        if (!$gateway || !current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Tidak diizinkan.', 'vd-payment-gateway'));
        }
        check_admin_referer('vdpg_save_' . $gateway->id());

        $input = isset($_POST['vdpg']) && is_array($_POST['vdpg']) ? wp_unslash($_POST['vdpg']) : [];
        $clean = self::sanitize_settings($gateway, $input);

        update_option($gateway->option_name(), $clean, false);

        wp_safe_redirect($this->url($gateway->id(), ['vdpg_msg' => __('Pengaturan disimpan.', 'vd-payment-gateway')]));
        exit;
    }

    public function sync()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Tidak diizinkan.', 'vd-payment-gateway'));
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('vdpg_sync_' . $id);

        $transaction = (new TransactionRepository())->find_by_id($id);
        $result = $transaction
            ? (new PaymentService())->sync_status($transaction->gateway, $transaction->invoice)
            : new \WP_Error('vdpg_not_found', __('Transaksi tidak ditemukan.', 'vd-payment-gateway'));

        $args = is_wp_error($result)
            ? ['vdpg_msg' => $result->get_error_message(), 'vdpg_type' => 'error']
            : ['vdpg_msg' => sprintf(__('Status %1$s: %2$s', 'vd-payment-gateway'), $transaction->invoice, $result->status)];

        wp_safe_redirect($this->url('transactions', $args));
        exit;
    }

    public function save_log()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Tidak diizinkan.', 'vd-payment-gateway'));
        }
        check_admin_referer('vdpg_log');

        if (isset($_POST['clear'])) {
            Logger::clear();
            $message = __('Log dihapus.', 'vd-payment-gateway');
        } else {
            update_option(Logger::OPTION, isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0', false);
            $message = __('Pengaturan log disimpan.', 'vd-payment-gateway');
        }

        wp_safe_redirect($this->url('log', ['vdpg_msg' => $message]));
        exit;
    }

    private function render_log()
    {
        $lines = Logger::tail(200);
        $enabled = Logger::enabled();
        ?>
        <div x-data="vdpgLog(<?php echo esc_attr(wp_json_encode(['enabled' => $enabled])); ?>)">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" @submit.prevent="submit($event)">
            <input type="hidden" name="action" value="vdpg_log">
            <?php wp_nonce_field('vdpg_log'); ?>
            <?php $this->section_open(
                __('Log debug', 'vd-payment-gateway'),
                '',
                [array_merge(
                    $enabled ? ['warning', __('Mencatat', 'vd-payment-gateway')] : ['muted', __('Mati', 'vd-payment-gateway')],
                    [':class="{\'vdpg-badge--warning\': saved, \'vdpg-badge--muted\': !saved}" x-text="saved ? i18n.logOn : i18n.logOff"']
                )]
            ); ?>
                <div class="vdpg-form-group vdpg-form-group--checkbox">
                    <div class="vdpg-form-left">
                        <label class="vdpg-form-label" for="vdpg_log_enabled"><?php esc_html_e('Catat permintaan ke API gateway, notifikasi masuk, dan perubahan status.', 'vd-payment-gateway'); ?></label>
                        <small class="vdpg-form-hint" id="vdpg_log_enabled_hint"><?php printf(esc_html__('Secret key dan signature disamarkan. Berkas disimpan %d hari di uploads/vdpg-logs/. Matikan lagi setelah selesai pengecekan.', 'vd-payment-gateway'), Logger::LOG_DAYS); ?></small>
                    </div>
                    <div class="vdpg-form-right">
                        <label class="vdpg-switch">
                            <input type="checkbox" id="vdpg_log_enabled" name="enabled" value="1" aria-describedby="vdpg_log_enabled_hint" x-model="enabled" <?php checked($enabled); ?>>
                            <span class="vdpg-switch__slider" aria-hidden="true"></span>
                        </label>
                    </div>
                </div>
            <?php $this->section_close(); ?>
            <div class="vdpg-actions">
                <?php submit_button(__('Simpan', 'vd-payment-gateway'), 'primary', 'submit', false, [':disabled' => 'busy']); ?>
                <?php submit_button(__('Hapus semua log', 'vd-payment-gateway'), 'delete', 'clear', false, ['onclick' => "return confirm('Hapus semua log?');", ':disabled' => 'busy']); ?>
                <span class="spinner" :class="{'is-active': busy}" aria-hidden="true"></span>
            </div>
        </form>
        <?php $this->section_open(sprintf(__('%d baris terakhir', 'vd-payment-gateway'), count($lines))); ?>
            <p class="vdpg-log-count" x-cloak x-show="count !== null" x-text="countText()"></p>
            <textarea readonly class="large-text code vdpg-log" rows="25" x-ref="log" aria-label="<?php esc_attr_e('Isi log', 'vd-payment-gateway'); ?>"><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
        <?php $this->section_close(); ?>
        </div>
        <?php
    }

    /** [kelas badge, label] untuk status transaksi (hanya tampilan). */
    public static function status_meta($status)
    {
        $map = [
            'paid' => ['success', __('Lunas', 'vd-payment-gateway')],
            'pending' => ['warning', __('Menunggu', 'vd-payment-gateway')],
            'expired' => ['muted', __('Kedaluwarsa', 'vd-payment-gateway')],
            'failed' => ['danger', __('Gagal', 'vd-payment-gateway')],
            'refunded' => ['info', __('Refund', 'vd-payment-gateway')],
        ];

        return $map[$status] ?? ['muted', (string) $status];
    }

    private function status_badge($status)
    {
        $badge = self::status_meta($status);
        printf(
            '<span class="vdpg-badge vdpg-badge--%s" title="%s">%s</span>',
            esc_attr($badge[0]),
            esc_attr($status),
            esc_html($badge[1])
        );
    }

    /** Data satu transaksi untuk tabel (tanpa payload, payment_url, dan data pelanggan). */
    public static function present_transaction($row)
    {
        $meta = self::status_meta($row->status);

        return [
            'id' => (int) $row->id,
            'invoice' => (string) $row->invoice,
            'gateway' => (string) $row->gateway,
            'source' => trim($row->source . ' #' . $row->ref_id, ' #'),
            'amount' => (int) $row->amount,
            'amount_text' => number_format_i18n((int) $row->amount),
            'status' => (string) $row->status,
            'status_label' => $meta[1],
            'status_tone' => $meta[0],
            'note' => (string) $row->note,
            'note_alert' => $row->note !== '' && $row->source !== 'legacy-import',
            'channel' => (string) $row->channel,
            'created_at' => (string) $row->created_at,
            'paid_at' => (string) $row->paid_at,
            'can_sync' => $row->status === 'pending',
        ];
    }

    /** Kartu statistik: jumlah dan nominal per status. */
    public static function present_stats(array $stats)
    {
        $out = [];
        foreach ($stats as $status => $item) {
            $meta = self::status_meta($status);
            $out[$status] = [
                'label' => $meta[1],
                'tone' => $meta[0],
                'count' => (int) $item['count'],
                'amount_text' => 'Rp' . number_format_i18n((int) $item['amount']),
            ];
        }

        return $out;
    }

    private function render_transactions()
    {
        $per_page = \VdPaymentGateway\Rest\AdminController::PER_PAGE;
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $filters = [
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'gateway' => isset($_GET['gateway']) ? sanitize_key(wp_unslash($_GET['gateway'])) : '',
        ];
        if (!in_array($filters['status'], TransactionRepository::STATUSES, true)) {
            $filters['status'] = '';
        }
        $repo = new TransactionRepository();
        $result = $repo->search($filters, $per_page, ($paged - 1) * $per_page);
        $rows = $result['rows'];
        $pages = max(1, (int) ceil($result['total'] / $per_page));
        $stats = self::present_stats($repo->stats());
        $gateways = Plugin::instance()->registry()->all();
        $columns = [
            'invoice' => __('Invoice', 'vd-payment-gateway'),
            'gateway' => __('Gateway', 'vd-payment-gateway'),
            'source' => __('Sumber', 'vd-payment-gateway'),
            'amount' => __('Jumlah', 'vd-payment-gateway'),
            'status' => __('Status', 'vd-payment-gateway'),
            'channel' => __('Channel', 'vd-payment-gateway'),
            'created' => __('Dibuat (UTC)', 'vd-payment-gateway'),
            'paid' => __('Lunas (UTC)', 'vd-payment-gateway'),
        ];
        $state = ['filters' => $filters, 'page' => $paged];
        ?>
        <div x-data="vdpgTransactions(<?php echo esc_attr(wp_json_encode($state)); ?>)">
        <div class="vdpg-stats">
            <?php foreach (['paid', 'pending', 'expired', 'failed'] as $status) : $item = $stats[$status]; ?>
                <div class="vdpg-stat vdpg-stat--<?php echo esc_attr($item['tone']); ?>">
                    <span class="vdpg-stat__label"><?php echo esc_html($item['label']); ?></span>
                    <strong class="vdpg-stat__count" x-text="stats ? stats.<?php echo esc_attr($status); ?>.count : <?php echo (int) $item['count']; ?>"><?php echo esc_html(number_format_i18n($item['count'])); ?></strong>
                    <span class="vdpg-stat__amount" x-text="stats ? stats.<?php echo esc_attr($status); ?>.amount_text : <?php echo esc_attr(wp_json_encode($item['amount_text'])); ?>"><?php echo esc_html($item['amount_text']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php $this->section_open(
            __('Transaksi', 'vd-payment-gateway'),
            __('Transaksi terbaru dari semua gateway.', 'vd-payment-gateway')
        ); ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="vdpg-filter" role="search" @submit.prevent="load(1)">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="transactions">
            <label class="screen-reader-text" for="vdpg_tx_search"><?php esc_html_e('Cari invoice atau referensi', 'vd-payment-gateway'); ?></label>
            <input type="search" id="vdpg_tx_search" name="s" maxlength="100" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Cari invoice / referensi', 'vd-payment-gateway'); ?>" x-model="filters.search" @input.debounce.400ms="load(1)">
            <label class="screen-reader-text" for="vdpg_tx_status"><?php esc_html_e('Status', 'vd-payment-gateway'); ?></label>
            <select id="vdpg_tx_status" name="status" x-model="filters.status" @change="load(1)">
                <option value=""><?php esc_html_e('Semua status', 'vd-payment-gateway'); ?></option>
                <?php foreach (TransactionRepository::STATUSES as $status) : ?>
                    <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html(self::status_meta($status)[1]); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="screen-reader-text" for="vdpg_tx_gateway"><?php esc_html_e('Gateway', 'vd-payment-gateway'); ?></label>
            <select id="vdpg_tx_gateway" name="gateway" x-model="filters.gateway" @change="load(1)">
                <option value=""><?php esc_html_e('Semua gateway', 'vd-payment-gateway'); ?></option>
                <?php foreach ($gateways as $id => $gateway) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filters['gateway'], $id); ?>><?php echo esc_html($gateway->label()); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Terapkan', 'vd-payment-gateway'); ?></button>
            <span class="spinner" :class="{'is-active': busy}" aria-hidden="true"></span>
        </form>
        <div class="vdpg-table-wrap">
            <table class="vdpg-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $key => $label) : ?>
                            <th scope="col" class="vdpg-col-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="vdpg-col-action"><span class="screen-reader-text"><?php esc_html_e('Aksi', 'vd-payment-gateway'); ?></span></th>
                    </tr>
                </thead>
                <?php /* Baris dari server: tampil tanpa JS, diganti baris Alpine setelah data REST pertama dimuat. */ ?>
                <tbody x-show="!ready">
                    <?php if (empty($rows)) : ?>
                        <tr class="vdpg-table__empty"><td colspan="9"><?php esc_html_e('Belum ada transaksi.', 'vd-payment-gateway'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td class="vdpg-col-invoice" data-label="<?php echo esc_attr($columns['invoice']); ?>"><strong><?php echo esc_html($row->invoice); ?></strong></td>
                            <td class="vdpg-col-gateway" data-label="<?php echo esc_attr($columns['gateway']); ?>"><span class="vdpg-chip"><?php echo esc_html($row->gateway); ?></span></td>
                            <td class="vdpg-col-source" data-label="<?php echo esc_attr($columns['source']); ?>"><?php echo esc_html(trim($row->source . ' #' . $row->ref_id, ' #')); ?></td>
                            <td class="vdpg-col-amount" data-label="<?php echo esc_attr($columns['amount']); ?>"><?php echo esc_html(number_format_i18n((int) $row->amount)); ?></td>
                            <td class="vdpg-col-status" data-label="<?php echo esc_attr($columns['status']); ?>">
                                <?php $this->status_badge($row->status); ?>
                                <?php if (!empty($row->note)) : ?>
                                    <span class="vdpg-note<?php echo $row->source === 'legacy-import' ? '' : ' vdpg-note--alert'; ?>"><?php echo esc_html($row->note); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="vdpg-col-channel" data-label="<?php echo esc_attr($columns['channel']); ?>"><?php echo esc_html($row->channel); ?></td>
                            <td class="vdpg-col-created" data-label="<?php echo esc_attr($columns['created']); ?>"><?php echo esc_html($row->created_at); ?></td>
                            <td class="vdpg-col-paid" data-label="<?php echo esc_attr($columns['paid']); ?>"><?php echo esc_html((string) $row->paid_at); ?></td>
                            <td class="vdpg-col-action">
                                <?php if ($row->status === 'pending') : ?>
                                    <a class="button button-small vdpg-btn-sync" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=vdpg_sync&id=' . (int) $row->id), 'vdpg_sync_' . (int) $row->id)); ?>">
                                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                        <?php esc_html_e('Cek status', 'vd-payment-gateway'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tbody x-cloak x-show="ready">
                    <tr class="vdpg-table__empty" x-show="rows.length === 0"><td colspan="9"><?php esc_html_e('Tidak ada transaksi yang cocok.', 'vd-payment-gateway'); ?></td></tr>
                    <template x-for="row in rows" :key="row.id">
                        <tr>
                            <td class="vdpg-col-invoice" data-label="<?php echo esc_attr($columns['invoice']); ?>"><strong x-text="row.invoice"></strong></td>
                            <td class="vdpg-col-gateway" data-label="<?php echo esc_attr($columns['gateway']); ?>"><span class="vdpg-chip" x-text="row.gateway"></span></td>
                            <td class="vdpg-col-source" data-label="<?php echo esc_attr($columns['source']); ?>" x-text="row.source"></td>
                            <td class="vdpg-col-amount" data-label="<?php echo esc_attr($columns['amount']); ?>" x-text="row.amount_text"></td>
                            <td class="vdpg-col-status" data-label="<?php echo esc_attr($columns['status']); ?>">
                                <span class="vdpg-badge" :class="'vdpg-badge--' + row.status_tone" :title="row.status" x-text="row.status_label"></span>
                                <span class="vdpg-note" :class="{'vdpg-note--alert': row.note_alert}" x-show="row.note !== ''" x-text="row.note"></span>
                            </td>
                            <td class="vdpg-col-channel" data-label="<?php echo esc_attr($columns['channel']); ?>" x-text="row.channel"></td>
                            <td class="vdpg-col-created" data-label="<?php echo esc_attr($columns['created']); ?>" x-text="row.created_at"></td>
                            <td class="vdpg-col-paid" data-label="<?php echo esc_attr($columns['paid']); ?>" x-text="row.paid_at"></td>
                            <td class="vdpg-col-action">
                                <button type="button" class="button button-small vdpg-btn-sync" x-show="row.can_sync" :disabled="syncing === row.id" @click="sync(row)">
                                    <span class="dashicons dashicons-update" :class="{'vdpg-spin': syncing === row.id}" aria-hidden="true"></span>
                                    <?php esc_html_e('Cek status', 'vd-payment-gateway'); ?>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <?php $this->section_close(); ?>
        <?php if ($pages > 1) : ?>
            <div class="vdpg-actions vdpg-pagination" x-show="!ready">
                <?php if ($paged > 1) : ?>
                    <a class="button" href="<?php echo esc_url($this->url('transactions', array_filter(['paged' => $paged - 1, 's' => $filters['search'], 'status' => $filters['status'], 'gateway' => $filters['gateway']]))); ?>">&laquo; <?php esc_html_e('Sebelumnya', 'vd-payment-gateway'); ?></a>
                <?php endif; ?>
                <?php if ($paged < $pages) : ?>
                    <a class="button" href="<?php echo esc_url($this->url('transactions', array_filter(['paged' => $paged + 1, 's' => $filters['search'], 'status' => $filters['status'], 'gateway' => $filters['gateway']]))); ?>"><?php esc_html_e('Berikutnya', 'vd-payment-gateway'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="vdpg-actions vdpg-pagination" x-cloak x-show="ready">
            <button type="button" class="button" x-show="page > 1" :disabled="busy" @click="load(page - 1)">&laquo; <?php esc_html_e('Sebelumnya', 'vd-payment-gateway'); ?></button>
            <span class="vdpg-pagination__info" x-text="pageText()"></span>
            <button type="button" class="button" x-show="page < pages" :disabled="busy" @click="load(page + 1)"><?php esc_html_e('Berikutnya', 'vd-payment-gateway'); ?> &raquo;</button>
        </div>
        </div>
        <?php
    }
}
