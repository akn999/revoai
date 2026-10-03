<?php

namespace App\Salla;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SallaClient
{
    /**
     * Fetch the merchant profile with the merchant's access token.
     *
     * @return array<string, mixed>
     */
    public function userInfo(string $accessToken): array
    {
        return $this->request()
            ->withToken($accessToken)
            ->get(config('salla.oauth_url').'/user/info')
            ->throw()
            ->json('data') ?? [];
    }

    /**
     * Exchange a refresh token for a new token pair.
     *
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->request()
            ->asForm()
            ->post(config('salla.oauth_url').'/token', [
                'grant_type' => 'refresh_token',
                'client_id' => config('salla.client_id'),
                'client_secret' => config('salla.client_secret'),
                'refresh_token' => $refreshToken,
            ])
            ->throw()
            ->json();
    }

    /**
     * Verify an embedded-SDK session token; null when Salla rejects it.
     *
     * @return array{merchant_id: int, user_id: int|null}|null
     */
    public function introspect(string $token): ?array
    {
        $response = $this->request()
            ->withHeaders(['S-Source' => (string) config('salla.app_id')])
            ->post(config('salla.introspect_url'), [
                'env' => 'prod',
                'token' => $token,
                'iss' => 'merchant-dashboard',
                'subject' => 'embedded-page',
            ]);

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('Salla introspect rejected the embedded token', [
                'status' => $response->status(),
                'error' => $response->json('error') ?? $response->json('message'),
            ]);

            return null;
        }

        $merchantId = $response->json('data.merchant_id');

        return is_numeric($merchantId)
            ? ['merchant_id' => (int) $merchantId, 'user_id' => $response->json('data.user_id')]
            : null;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->retry(
            2,
            200,
            when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        );
    }
}
