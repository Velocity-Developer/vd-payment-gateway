<?php

namespace VdPaymentGateway\Admin;

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
        add_action('admin_post_vdpg_save', [$this, 'save']);
        add_action('admin_post_vdpg_sync', [$this, 'sync']);
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

    public function render()
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $gateways = Plugin::instance()->registry()->all();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($tab === '' || ($tab !== 'transactions' && !isset($gateways[$tab]))) {
            $tab = (string) key($gateways);
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Gateway', 'vd-payment-gateway'); ?></h1>
            <?php $this->notice(); ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ($gateways as $id => $gateway) : ?>
                    <a href="<?php echo esc_url($this->url($id)); ?>" class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($gateway->label()); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url($this->url('transactions')); ?>" class="nav-tab <?php echo $tab === 'transactions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Transaksi', 'vd-payment-gateway'); ?>
                </a>
            </nav>
            <?php
            if ($tab === 'transactions') {
                $this->render_transactions();
            } elseif (isset($gateways[$tab])) {
                $this->render_gateway($gateways[$tab]);
            }
            ?>
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

    private function render_gateway($gateway)
    {
        $options = method_exists($gateway, 'options') ? $gateway->options() : (array) get_option($gateway->option_name(), []);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="vdpg_save">
            <input type="hidden" name="gateway" value="<?php echo esc_attr($gateway->id()); ?>">
            <?php wp_nonce_field('vdpg_save_' . $gateway->id()); ?>
            <table class="form-table" role="presentation">
                <?php foreach ($gateway->settings_fields() as $key => $field) :
                    $name = 'vdpg[' . $key . ']';
                    $id = 'vdpg_' . $gateway->id() . '_' . $key;
                    $value = $options[$key] ?? '';
                    ?>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label></th>
                        <td>
                            <?php switch ($field['type']) {
                                case 'checkbox':
                                    printf('<input type="checkbox" id="%s" name="%s" value="1" %s>', esc_attr($id), esc_attr($name), checked($value, '1', false));
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
                            }
                            if (!empty($field['hint'])) {
                                printf('<p class="description">%s</p>', esc_html($field['hint']));
                            } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (method_exists($gateway, 'notification_url')) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Notification URL', 'vd-payment-gateway'); ?></th>
                        <td>
                            <code><?php echo esc_html($gateway->notification_url()); ?></code>
                            <p class="description"><?php esc_html_e('Isikan URL ini di Back Office gateway (menu Integration / Notification).', 'vd-payment-gateway'); ?></p>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php submit_button(__('Simpan Perubahan', 'vd-payment-gateway')); ?>
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

    private function render_transactions()
    {
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per_page = 50;
        $rows = (new TransactionRepository())->latest($per_page, ($paged - 1) * $per_page);
        ?>
        <table class="widefat striped" style="margin-top:1em">
            <thead>
                <tr>
                    <th><?php esc_html_e('Invoice', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Gateway', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Sumber', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Jumlah', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Status', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Channel', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Dibuat (UTC)', 'vd-payment-gateway'); ?></th>
                    <th><?php esc_html_e('Lunas (UTC)', 'vd-payment-gateway'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Belum ada transaksi.', 'vd-payment-gateway'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->invoice); ?></td>
                        <td><?php echo esc_html($row->gateway); ?></td>
                        <td><?php echo esc_html(trim($row->source . ' #' . $row->ref_id, ' #')); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $row->amount)); ?></td>
                        <td><strong><?php echo esc_html($row->status); ?></strong></td>
                        <td><?php echo esc_html($row->channel); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html((string) $row->paid_at); ?></td>
                        <td>
                            <?php if ($row->status === 'pending') : ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=vdpg_sync&id=' . (int) $row->id), 'vdpg_sync_' . (int) $row->id)); ?>">
                                    <?php esc_html_e('Cek status', 'vd-payment-gateway'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <?php if ($paged > 1) : ?>
                <a class="button" href="<?php echo esc_url($this->url('transactions', ['paged' => $paged - 1])); ?>">&laquo;</a>
            <?php endif; ?>
            <?php if (count($rows) === $per_page) : ?>
                <a class="button" href="<?php echo esc_url($this->url('transactions', ['paged' => $paged + 1])); ?>">&raquo;</a>
            <?php endif; ?>
        </p>
        <?php
    }
}
