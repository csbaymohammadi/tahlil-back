<?php

namespace App\Actions\StockBrokers;

use App\Exceptions\PanelAuthException;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageLegacyPath;

class ResolveLegacyStockRoute
{
    /** @return array{source_path: string, target_path: string} */
    public function handle(string $sourcePath): array
    {
        $mapping = StockBrokerageLegacyPath::query()->where('source_slug', substr($sourcePath, strlen('/brokers/')))->first();
        $target = $mapping ? StockBrokerage::public()->find($mapping->stock_brokerage_id) : null;
        if (! $target) {
            throw new PanelAuthException(404, 'LEGACY_ROUTE_NOT_FOUND', 'The public legacy route was not found.');
        }

        return ['source_path' => $sourcePath, 'target_path' => '/stock-brokers/'.rawurlencode($target->slug)];
    }
}
