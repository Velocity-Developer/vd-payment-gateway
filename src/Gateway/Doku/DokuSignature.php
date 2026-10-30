<?php

namespace VdPaymentGateway\Gateway\Doku;

/**
 * Signature DOKU Non-SNAP.
 * Lihat https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap
 */
class DokuSignature
{
    public static function digest($body)
    {
        return base64_encode(hash('sha256', (string) $body, true));
    }

    /**
     * @param string      $body JSON body persis seperti dikirim/diterima; null untuk GET.
     */
    public static function generate($client_id, $request_id, $timestamp, $target, $body, $secret_key)
    {
        $component = 'Client-Id:' . $client_id . "\n"
            . 'Request-Id:' . $request_id . "\n"
            . 'Request-Timestamp:' . $timestamp . "\n"
            . 'Request-Target:' . $target;

        if ($body !== null && $body !== '') {
            $component .= "\n" . 'Digest:' . self::digest($body);
        }

        return 'HMACSHA256=' . base64_encode(hash_hmac('sha256', $component, (string) $secret_key, true));
    }

    public static function verify($signature, $client_id, $request_id, $timestamp, $target, $body, $secret_key)
    {
        if ($signature === '' || $secret_key === '') {
            return false;
        }

        $expected = self::generate($client_id, $request_id, $timestamp, $target, $body, $secret_key);

        return hash_equals($expected, trim((string) $signature));
    }

    /** Timestamp ISO 8601 UTC, mis. 2020-09-22T01:51:00Z. */
    public static function timestamp($time = null)
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time === null ? time() : (int) $time);
    }
}
