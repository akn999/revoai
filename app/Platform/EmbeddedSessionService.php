<?php

namespace App\Platform;

use App\Models\EmbeddedSession;
use Illuminate\Support\Str;

class EmbeddedSessionService
{
    /**
     * @return array{token: string, session: EmbeddedSession}
     */
    public function start(int $merchantId, ?int $sallaUserId): array
    {
        $token = Str::random(64);

        $session = EmbeddedSession::query()->create([
            'token_hash' => $this->hash($token),
            'merchant_id' => $merchantId,
            'salla_user_id' => $sallaUserId,
            'last_used_at' => now(),
            'expires_at' => now()->addHours((int) config('revo.limits.session_absolute_hours')),
        ]);

        return ['token' => $token, 'session' => $session];
    }

    /**
     * Resolve a bearer token to a live session, or null when unknown, revoked, idle or past the absolute limit.
     */
    public function authenticate(?string $token): ?EmbeddedSession
    {
        if (! $token) {
            return null;
        }

        $session = EmbeddedSession::query()->where('token_hash', $this->hash($token))->first();

        if (! $session || $session->revoked_at || $session->expires_at->isPast()) {
            return null;
        }

        $idleLimit = now()->subMinutes((int) config('revo.limits.session_idle_minutes'));

        if ($session->last_used_at->lt($idleLimit)) {
            return null;
        }

        $session->forceFill(['last_used_at' => now()])->save();

        return $session;
    }

    public function revokeForMerchant(int $merchantId): int
    {
        return EmbeddedSession::query()
            ->where('merchant_id', $merchantId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function pruneExpired(): int
    {
        return EmbeddedSession::query()
            ->where(fn ($query) => $query->where('expires_at', '<', now())->orWhereNotNull('revoked_at'))
            ->where('updated_at', '<', now()->subDay())
            ->delete();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
