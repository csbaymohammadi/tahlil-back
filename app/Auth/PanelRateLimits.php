<?php

namespace App\Auth;

use App\Exceptions\PanelAuthException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class PanelRateLimits
{
    public function limiter(): RateLimiter
    {
        return new RateLimiter(Cache::store(config('panel.rate_store')));
    }

    public function check(string $key, int $maximum): void
    {
        $limiter = $this->limiter();
        if ($limiter->tooManyAttempts($key, $maximum)) {
            throw new PanelAuthException(429, 'RATE_LIMITED', 'Please wait before trying again.', retryAfter: max(1, $limiter->availableIn($key)));
        }
    }

    public function hit(string $key): void
    {
        $this->limiter()->hit($key, config('panel.rate_window'));
    }

    public function clear(string $key): void
    {
        $this->limiter()->clear($key);
    }

    public function loginKey(string $username, string $ip): string
    {
        return 'panel:login:'.hash('sha256', $username.'|'.$ip);
    }
}
