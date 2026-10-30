<?php

namespace VdPaymentGateway\Gateway;

interface GatewayInterface
{
    /** Kode unik gateway, mis. "doku". */
    public function id();

    public function label();

    /** Gateway diaktifkan dan kredensial lengkap. */
    public function is_active();

    /**
     * Membuat tagihan di gateway.
     *
     * $args: invoice, amount, source, ref_id, description, return_url, expiry (menit),
     *        customer [name, email, phone, address, city, postcode],
     *        items [[name, price, qty, sku]]
     *
     * @return array|\WP_Error [payment_url, token, reference, expires_at, transaction_id, raw]
     */
    public function create_payment(array $args);

    /**
     * Memproses notifikasi dari gateway.
     *
     * @return array|\WP_Error [invoice, status, amount, reference, channel, raw]
     */
    public function parse_notification(\WP_REST_Request $request);

    /**
     * @return array|\WP_Error [invoice, status, amount, reference, channel, raw]
     */
    public function check_status($invoice);

    /** Nama opsi settings gateway. */
    public function option_name();

    /** Definisi field untuk halaman pengaturan. */
    public function settings_fields();
}
