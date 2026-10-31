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
                            <span class="vdpg-dot<?php echo $gateway->is_active() ? ' is-on' : ''; ?>" aria-hidden="true"></span>
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
                            <span class="vdpg-badge vdpg-badge--<?php echo esc_attr($badge[0]); ?>"><?php echo esc_html($badge[1]); ?></span>
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
        $badges = [$gateway->is_active() ? ['success', __('Aktif', 'vd-payment-gateway')] : ['muted', __('Nonaktif', 'vd-payment-gateway')]];
        if (!empty($options['mode'])) {
            $badges[] = $options['mode'] === 'production' ? ['info', 'Production'] : ['warning', 'Sandbox'];
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
                                        '<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s">',
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
                <?php submit_button(__('Simpan Perubahan', 'vd-payment-gateway'), 'primary', 'submit', false); ?>
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
        $current = get_option($gateway->option_name(), []);
        $current = is_array($current) ? $current : [];
        $clean = [];

        foreach ($gateway->settings_fields() as $key => $field) {
            $raw = $input[$key] ?? '';
            switch ($field['type']) {
                case 'checkbox':
                    $clean[$key] = $raw === '1' ? '1' : '0';
                    break;
                case 'select':
                    $clean[$key] = array_key_exists($raw, $field['choices']) ? $raw : (string) key($field['choices']);
                    break;
                case 'secret':
                    $raw = trim((string) $raw);
                    $clean[$key] = $raw !== '' ? $raw : (string) ($current[$key] ?? '');
                    break;
                case 'number':
                    $clean[$key] = max(1, (int) $raw);
                    break;
                default:
                    $clean[$key] = sanitize_text_field((string) $raw);
            }
        }

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
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="vdpg_log">
            <?php wp_nonce_field('vdpg_log'); ?>
            <?php $this->section_open(
                __('Log debug', 'vd-payment-gateway'),
                '',
                [Logger::enabled() ? ['warning', __('Mencatat', 'vd-payment-gateway')] : ['muted', __('Mati', 'vd-payment-gateway')]]
            ); ?>
                <div class="vdpg-form-group vdpg-form-group--checkbox">
                    <div class="vdpg-form-left">
                        <label class="vdpg-form-label" for="vdpg_log_enabled"><?php esc_html_e('Catat permintaan ke API gateway, notifikasi masuk, dan perubahan status.', 'vd-payment-gateway'); ?></label>
                        <small class="vdpg-form-hint" id="vdpg_log_enabled_hint"><?php printf(esc_html__('Secret key dan signature disamarkan. Berkas disimpan %d hari di uploads/vdpg-logs/. Matikan lagi setelah selesai pengecekan.', 'vd-payment-gateway'), Logger::LOG_DAYS); ?></small>
                    </div>
                    <div class="vdpg-form-right">
                        <label class="vdpg-switch">
                            <input type="checkbox" id="vdpg_log_enabled" name="enabled" value="1" aria-describedby="vdpg_log_enabled_hint" <?php checked(Logger::enabled()); ?>>
                            <span class="vdpg-switch__slider" aria-hidden="true"></span>
                        </label>
                    </div>
                </div>
            <?php $this->section_close(); ?>
            <div class="vdpg-actions">
                <?php submit_button(__('Simpan', 'vd-payment-gateway'), 'primary', 'submit', false); ?>
                <?php submit_button(__('Hapus semua log', 'vd-payment-gateway'), 'delete', 'clear', false, ['onclick' => "return confirm('Hapus semua log?');"]); ?>
            </div>
        </form>
        <?php $this->section_open(sprintf(__('%d baris terakhir', 'vd-payment-gateway'), count($lines))); ?>
            <textarea readonly class="large-text code vdpg-log" rows="25" aria-label="<?php esc_attr_e('Isi log', 'vd-payment-gateway'); ?>"><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
        <?php $this->section_close(); ?>
        <?php
    }

    /** Kelas badge dan label untuk status transaksi (hanya tampilan). */
    private function status_badge($status)
    {
        $map = [
            'paid' => ['success', __('Lunas', 'vd-payment-gateway')],
            'pending' => ['warning', __('Menunggu', 'vd-payment-gateway')],
            'expired' => ['muted', __('Kedaluwarsa', 'vd-payment-gateway')],
            'failed' => ['danger', __('Gagal', 'vd-payment-gateway')],
            'refunded' => ['info', __('Refund', 'vd-payment-gateway')],
        ];
        $badge = $map[$status] ?? ['muted', (string) $status];
        printf(
            '<span class="vdpg-badge vdpg-badge--%s" title="%s">%s</span>',
            esc_attr($badge[0]),
            esc_attr($status),
            esc_html($badge[1])
        );
    }

    private function render_transactions()
    {
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per_page = 50;
        $rows = (new TransactionRepository())->latest($per_page, ($paged - 1) * $per_page);
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
        $this->section_open(
            __('Transaksi', 'vd-payment-gateway'),
            /* translators: %d: nomor halaman */
            sprintf(__('Transaksi terbaru dari semua gateway. Halaman %d.', 'vd-payment-gateway'), $paged)
        );
        ?>
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
                <tbody>
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
            </table>
        </div>
        <?php $this->section_close(); ?>
        <?php if ($paged > 1 || count($rows) === $per_page) : ?>
            <div class="vdpg-actions vdpg-pagination">
                <?php if ($paged > 1) : ?>
                    <a class="button" href="<?php echo esc_url($this->url('transactions', ['paged' => $paged - 1])); ?>">&laquo; <?php esc_html_e('Sebelumnya', 'vd-payment-gateway'); ?></a>
                <?php endif; ?>
                <?php if (count($rows) === $per_page) : ?>
                    <a class="button" href="<?php echo esc_url($this->url('transactions', ['paged' => $paged + 1])); ?>"><?php esc_html_e('Berikutnya', 'vd-payment-gateway'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }
}
