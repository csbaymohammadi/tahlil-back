<?php

namespace App\Exchanges;

use App\Models\Exchange;
use App\Models\ExchangeSlugOwnership;

class ResolveExchangePublicItem
{
    /** @return array{type: 'exchange', exchange: Exchange}|array{type: 'redirect', path: string}|array{type: 'not_found'} */
    public function resolve(string $slug): array
    {
        $ownership = ExchangeSlugOwnership::query()->where('slug', $slug)->first();
        if (! $ownership) {
            return ['type' => 'not_found'];
        }
        $exchange = Exchange::public()->withCount('visibleComments as comment_count')->with(['author', 'category'])->find($ownership->exchange_id);
        if (! $exchange) {
            return ['type' => 'not_found'];
        }
        if ($exchange->slug === $slug) {
            return ['type' => 'exchange', 'exchange' => $exchange];
        }
        if ($ownership->was_public) {
            return ['type' => 'redirect', 'path' => '/exchanges/'.$exchange->slug];
        }

        return ['type' => 'not_found'];
    }
}
