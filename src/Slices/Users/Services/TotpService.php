<?php

namespace LaraSlice\Slices\Users\Services;

use Illuminate\Support\Facades\Cache;

/**
 * RFC 6238 time-based one-time passwords (30-second steps, 6 digits, SHA-1).
 */
class TotpService
{
    public const PERIOD = 30;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * Check a code against the secret within ±1 time step.
     *
     * When a replay key is given (normally the user id), a code is accepted only once:
     * the matched time step must be newer than the last one accepted for that key.
     */
    public function verify(?string $secret, string $code, string|int|null $replayKey = null): bool
    {
        $code = trim($code);
        if (empty($secret) || strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $current = intdiv(time(), self::PERIOD);
        $cacheKey = $replayKey === null ? null : "laraslice:totp:last-step:{$replayKey}";
        $lastStep = $cacheKey ? (int) Cache::get($cacheKey, 0) : 0;

        for ($drift = -1; $drift <= 1; $drift++) {
            $step = $current + $drift;
            if ($step <= $lastStep) {
                continue;
            }

            if (hash_equals($this->code($secret, $step), $code)) {
                if ($cacheKey) {
                    Cache::put($cacheKey, $step, self::PERIOD * 4);
                }

                return true;
            }
        }

        return false;
    }

    public function code(string $secret, int $timeStep): string
    {
        $key = $this->base32Decode($secret);
        $counter = pack('N*', 0).pack('N*', $timeStep);
        $hmac = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hmac[19]) & 0x0F;
        $value = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function provisioningUri(string $accountName, string $secret, string $issuer = 'LaraSlice'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($accountName)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&period='.self::PERIOD;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $bytes .= chr(bindec(substr($bits, $i, 8)));
        }

        return $bytes;
    }
}
