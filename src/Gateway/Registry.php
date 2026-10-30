<?php

namespace VdPaymentGateway\Gateway;

use VdPaymentGateway\Gateway\Doku\DokuGateway;

class Registry
{
    private $gateways = null;

    /** @return GatewayInterface[] */
    public function all()
    {
        if ($this->gateways !== null) {
            return $this->gateways;
        }

        $gateways = [
            'doku' => new DokuGateway(),
        ];

        $gateways = apply_filters('vd_pg_gateways', $gateways);

        $this->gateways = [];
        foreach ((array) $gateways as $gateway) {
            if ($gateway instanceof GatewayInterface) {
                $this->gateways[sanitize_key($gateway->id())] = $gateway;
            }
        }

        return $this->gateways;
    }

    /** @return GatewayInterface|null */
    public function get($id)
    {
        $all = $this->all();
        $id = sanitize_key((string) $id);

        return $all[$id] ?? null;
    }

    /** @return GatewayInterface[] */
    public function active()
    {
        return array_filter($this->all(), function ($gateway) {
            return $gateway->is_active();
        });
    }
}
