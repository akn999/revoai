<?php

namespace App\Sync;

use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Services\TokenService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The Salla Admin API v2 client. 429 and 5xx responses are retried with exponential backoff
 * (honoring Retry-After), a 401 is retried once after refreshing the token (FR-SYN-008).
 */
class SallaApi
{
    public function __construct(private TokenService $tokens) {}

    /**
     * @return array{products: array<int, array<string, mixed>>, total_pages: int}
     */
    public function listProducts(Merchant $merchant, int $page): array
    {
        $response = $this->send($merchant, fn (PendingRequest $request) => $request->get('products', [
            'per_page' => (int) config('revo.salla.page_size'),
            'page' => $page,
        ]))->throw();

        return [
            'products' => (array) $response->json('data', []),
            'total_pages' => max(1, (int) $response->json('pagination.totalPages', 1)),
        ];
    }

    /**
     * One product in one language.
     *
     * @return array<string, mixed>|null
     */
    public function getProduct(Merchant $merchant, int $productId, string $language): ?array
    {
        $response = $this->send($merchant, fn (PendingRequest $request) => $request
            ->withHeaders(['Accept-Language' => $language])
            ->get("products/{$productId}"));

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json('data');
    }

    /**
     * Partial update: only the given fields are sent, in the given language.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateProduct(Merchant $merchant, int $productId, array $body, string $language): array
    {
        return (array) $this->send($merchant, fn (PendingRequest $request) => $request
            ->withHeaders(['Accept-Language' => $language])
            ->put("products/{$productId}", $body))->throw()->json();
    }

    /**
     * Upload an image file to a product (multipart: photo, alt, optional main and sort).
     *
     * @param  array<string, scalar>  $fields
     * @return array<string, mixed>
     */
    public function uploadProductImage(Merchant $merchant, int $productId, string $contents, string $filename, array $fields, string $language): array
    {
        return (array) $this->send($merchant, fn (PendingRequest $request) => $request
            ->withHeaders(['Accept-Language' => $language])
            ->attach('photo', $contents, $filename)
            ->post("products/{$productId}/images", $fields))->throw()->json('data');
    }

    /**
     * Run a request for a merchant with the retry policy applied.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    public function send(Merchant $merchant, callable $call): Response
    {
        $attempt = 0;
        $refreshed = false;
        $maxAttempts = max(1, (int) config('revo.salla.retry_attempts'));

        while (true) {
            $response = $call($this->client($merchant));

            if ($response->status() === 401 && ! $refreshed) {
                $this->tokens->refresh($this->token($merchant), force: true);
                $refreshed = true;

                continue;
            }

            if (($response->status() === 429 || $response->serverError()) && ++$attempt < $maxAttempts) {
                Sleep::for($this->delay($response, $attempt))->seconds();

                continue;
            }

            return $response;
        }
    }

    private function client(Merchant $merchant): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('revo.salla.api_url'), '/').'/')
            ->withToken($this->token($merchant)->access_token)
            ->acceptJson()
            ->timeout(30);
    }

    private function token(Merchant $merchant): MerchantToken
    {
        $token = $merchant->token()->first();

        if (! $token || $token->revoked_at) {
            throw new MissingToken($merchant->merchant_id);
        }

        return $token;
    }

    private function delay(Response $response, int $attempt): int
    {
        $retryAfter = (int) $response->header('Retry-After');

        return $retryAfter > 0 ? $retryAfter : (int) min(60, 2 ** ($attempt - 1));
    }
}
