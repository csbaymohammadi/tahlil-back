<?php

namespace App\Ratings;

use App\Exceptions\PanelAuthException;
use App\Models\PlatformVoterContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class PlatformRatingContexts
{
    public const COOKIE = 'tahlil-platform-voter-v1';

    public function resolve(?string $token): ?PlatformVoterContext
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return PlatformVoterContext::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    /** @return array{context: PlatformVoterContext, token: string} */
    public function create(): array
    {
        $token = bin2hex(random_bytes(32));
        $context = new PlatformVoterContext;
        $context->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds(config('platform_ratings.voter_seconds')), 'created_at' => now()])->save();

        return ['context' => $context, 'token' => $token];
    }

    /** @return array{csrf_token: string, csrf_expires_at: string} */
    public function csrf(PlatformVoterContext $context): array
    {
        $expires = now()->addSeconds(min(900, (int) config('platform_ratings.csrf_seconds')))->min($context->expires_at);
        $token = Crypt::encryptString(json_encode(['purpose' => 'platform-rating', 'context' => $context->token_hash,
            'nonce' => bin2hex(random_bytes(32)), 'expires' => $expires->timestamp], JSON_THROW_ON_ERROR));

        return ['csrf_token' => $token, 'csrf_expires_at' => $expires->utc()->toISOString()];
    }

    public function assertCsrf(PlatformVoterContext $context, ?string $token): void
    {
        try {
            $payload = is_string($token) && strlen($token) <= 4096 ? json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|JsonException) {
            $payload = null;
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'platform-rating' ||
            ! is_string($payload['context'] ?? null) || ! hash_equals($context->token_hash, $payload['context']) ||
            ! is_int($payload['expires'] ?? null) || $payload['expires'] <= now()->timestamp ||
            $payload['expires'] > now()->addSeconds(900)->timestamp || ! is_string($payload['nonce'] ?? null)) {
            throw new PanelAuthException(403, 'CSRF_INVALID', 'Read a fresh rating context before retrying.');
        }
    }
}
