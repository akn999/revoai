<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Salla\SallaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

class TokenService
{
    public function __construct(private SallaClient $client) {}

    /**
     * Replace the merchant's token set from an authorize payload (tokens already decrypted).
     *
     * @param  array<string, mixed>  $data
     */
    public function store(Merchant $merchant, array $data): MerchantToken
    {
        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new InvalidArgumentException('Authorize payload has no access or refresh token.');
        }

        return $merchant->token()->updateOrCreate([], [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_type' => $data['token_type'] ?? 'bearer',
            'scope' => $data['scope'] ?? null,
            'expires_at' => isset($data['expires'])
                ? Date::createFromTimestamp((int) $data['expires'])
                : now()->addDays(14),
            'revoked_at' => null,
        ]);
    }

    /**
     * Refresh under a per-merchant lock so two workers never spend the same refresh token.
     */
    public function refresh(MerchantToken $token): MerchantToken
    {
        return Cache::lock("salla-token-refresh:{$token->merchant_id}", 30)->block(10, function () use ($token) {
            $token->refresh();

            if (! $token->isExpiring()) {
                return $token;
            }

            $response = $this->client->refreshToken($token->refresh_token);

            $token->update([
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'],
                'expires_at' => match (true) {
                    isset($response['expires_in']) => now()->addSeconds((int) $response['expires_in']),
                    isset($response['expires']) => Date::createFromTimestamp((int) $response['expires']),
                    default => now()->addDays(14),
                },
                'refreshed_at' => now(),
            ]);

            return $token;
        });
    }
}
