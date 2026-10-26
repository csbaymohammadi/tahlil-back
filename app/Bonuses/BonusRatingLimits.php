<?php

namespace App\Bonuses;

use App\Exceptions\PanelAuthException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class BonusRatingLimits
{
    public function hit(string $operation, string $identityType, string $identity): void
    {
        $store = Cache::store(config('panel.rate_store'));
        $key = 'bonus:rating:'.$operation.':'.$identityType.':'.hash_hmac('sha256', $identity, config('app.key'));
        $maximum = (int) config('bonuses.rating_'.$operation.'_'.$identityType.'_limit');
        $store->lock($key.':lock', 5)->block(3, function () use ($store, $key, $maximum): void {
            $limiter = new RateLimiter($store);
            if ($limiter->tooManyAttempts($key, $maximum)) {
                throw new PanelAuthException(429, 'RATE_LIMITED', 'Please wait before trying again.', retryAfter: max(1, $limiter->availableIn($key)));
            }
            $limiter->hit($key, (int) config('bonuses.rate_window'));
        });
    }
}
