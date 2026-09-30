<?php

namespace App\Salla;

use Illuminate\Support\Facades\Crypt;

class PayloadVault
{
    /** @var list<string> */
    private const TOKEN_KEYS = ['access_token', 'refresh_token'];

    /**
     * Encrypt the OAuth tokens inside a webhook body so no plain token is stored or logged.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function seal(array $body): array
    {
        foreach (self::TOKEN_KEYS as $key) {
            $value = data_get($body, "data.$key");

            if (is_string($value) && $value !== '') {
                data_set($body, "data.$key", Crypt::encryptString($value));
            }
        }

        return $body;
    }

    /**
     * Decrypt the tokens of an event's `data` array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reveal(array $data): array
    {
        foreach (self::TOKEN_KEYS as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = Crypt::decryptString($data[$key]);
            }
        }

        return $data;
    }
}
