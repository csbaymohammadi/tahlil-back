<?php

namespace App\Comments;

use App\Exceptions\PanelAuthException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class CommentSubmissionLimits
{
    public function hit(string $ip): void
    {
        $store = Cache::store(config('panel.rate_store'));
        $store->lock('comments:submission:rate-lock', 5)->block(3, function () use ($store, $ip): void {
            $limiter = new RateLimiter($store);
            $keys = ['comments:submission:global' => (int) config('comments.submission_global_limit'),
                'comments:submission:ip:'.hash_hmac('sha256', $ip, config('app.key')) => (int) config('comments.submission_ip_limit')];
            foreach ($keys as $key => $maximum) {
                if ($limiter->tooManyAttempts($key, $maximum)) {
                    throw new PanelAuthException(429, 'RATE_LIMITED', 'Please wait before trying again.', retryAfter: max(1, $limiter->availableIn($key)));
                }
            }
            foreach ($keys as $key => $maximum) {
                $limiter->hit($key, (int) config('comments.submission_window'));
            }
        });
    }
}
