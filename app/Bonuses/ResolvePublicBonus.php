<?php

namespace App\Bonuses;

use App\Models\Bonus;
use Illuminate\Support\Facades\DB;

class ResolvePublicBonus
{
    /** @return array<string, mixed> */
    public function handle(string $slug): array
    {
        $ownership = DB::table('bonus_slug_ownership')->where('slug', $slug)->first();
        $bonus = $ownership ? Bonus::public()->with(['category', 'campaign.exchange', 'ratingAggregate'])->find($ownership->bonus_id) : null;
        if (! $bonus) {
            return ['type' => 'not_found'];
        }
        if ($bonus->slug === $slug) {
            return ['type' => 'bonus', 'bonus' => $bonus];
        }

        return $ownership->was_public ? ['type' => 'redirect', 'status' => 301, 'path' => '/bonuses/'.$bonus->slug] : ['type' => 'not_found'];
    }
}
