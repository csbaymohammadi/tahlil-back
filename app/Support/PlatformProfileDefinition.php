<?php

namespace App\Support;

use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\BrokerSlugOwnership;
use App\Models\PlatformCategory;
use App\Models\PlatformProfile;
use App\Models\PlatformSlugOwnership;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\StockBrokerageSlugOwnership;
use InvalidArgumentException;

final readonly class PlatformProfileDefinition
{
    /**
     * @param  class-string<PlatformProfile>  $profileModel
     * @param  class-string<PlatformCategory>  $categoryModel
     * @param  class-string<PlatformSlugOwnership>  $slugModel
     * @param  class-string<BrokerSpecifications|StockBrokerSpecifications>  $specifications
     * @param  list<string>  $summaryFields
     */
    private function __construct(
        public string $key,
        public string $slug,
        public string $mediaType,
        public int $nameLimit,
        public string $profileForeignKey,
        public string $auditTable,
        public string $outboxTable,
        public string $slugTable,
        public string $categoryTable,
        public string $legacyPathTable,
        public string $publicPath,
        public string $notFoundCode,
        public string $profileLabel,
        public string $eventPrefix,
        public string $auditTarget,
        public string $identityKey,
        public string $profileModel,
        public string $categoryModel,
        public string $slugModel,
        public string $specifications,
        public array $summaryFields,
        public bool $logoRequired,
        public bool $websiteRequired,
        public bool $hasCategoryKeys,
    ) {}

    public static function forex(): self
    {
        return new self('broker', 'brokers', 'broker', 200, 'broker_id', 'broker_audit_events', 'broker_outbox', 'broker_slug_ownership', 'broker_categories', 'broker_legacy_stock_paths', '/brokers/', 'BROKER_NOT_FOUND', 'broker', 'broker', 'broker', 'broker_id', Broker::class, BrokerCategory::class, BrokerSlugOwnership::class, BrokerSpecifications::class, ['platforms', 'markets', 'access', 'account_currency'], true, true, true);
    }

    public static function stock(): self
    {
        return new self('stock-brokers', 'stock-brokers', 'stock-brokers', 120, 'stock_brokerage_id', 'stock_brokerage_audit_events', 'stock_brokerage_outbox', 'stock_brokerage_slug_ownership', 'stock_brokerage_categories', 'broker_legacy_stock_paths', '/stock-brokers/', 'STOCK_BROKER_NOT_FOUND', 'stock brokerage', 'stock_broker', 'stock_broker', 'stock_brokerage_id', StockBrokerage::class, StockBrokerageCategory::class, StockBrokerageSlugOwnership::class, StockBrokerSpecifications::class, [], false, false, false);
    }

    public static function for(string $key): self
    {
        return match ($key) {
            'broker' => self::forex(),
            'stock-brokers' => self::stock(),
            default => throw new InvalidArgumentException('Unknown platform profile type.'),
        };
    }

    /** @return list<string> */
    public function editableFields(): array
    {
        return ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', ...$this->summaryFields, 'specifications', 'category_id', 'content', 'meta_description', 'status'];
    }
}
