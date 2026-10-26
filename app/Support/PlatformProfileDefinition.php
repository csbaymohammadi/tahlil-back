<?php

namespace App\Support;

use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\BrokerSlugOwnership;
use App\Models\GoldPlatform;
use App\Models\GoldPlatformCategory;
use App\Models\GoldPlatformSlugOwnership;
use App\Models\PlatformCategory;
use App\Models\PlatformProfile;
use App\Models\PlatformSlugOwnership;
use App\Models\PropFirm;
use App\Models\PropFirmCategory;
use App\Models\PropFirmSlugOwnership;
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
     * @param  class-string<BrokerSpecifications|StockBrokerSpecifications|GoldPlatformSpecifications|PropFirmSpecifications>  $specifications
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
        public ?string $kind,
        public string $platformLabel,
        public string $featuredIdsConfig,
        public string $categoryCountKey,
        public string $affectedCountKey,
        public string $movedCountKey,
        public bool $fillUnconfiguredFeatured,
    ) {}

    public static function forex(): self
    {
        return new self('broker', 'brokers', 'broker', 200, 'broker_id', 'broker_audit_events', 'broker_outbox', 'broker_slug_ownership', 'broker_categories', 'broker_legacy_stock_paths', '/brokers/', 'BROKER_NOT_FOUND', 'broker', 'broker', 'broker', 'broker_id', Broker::class, BrokerCategory::class, BrokerSlugOwnership::class, BrokerSpecifications::class, ['platforms', 'markets', 'access', 'account_currency'], true, true, true, null, 'Forex Broker', 'platform_profiles.broker_featured_ids', 'broker_count', 'affected_broker_count', 'moved_broker_count', true);
    }

    public static function stock(): self
    {
        return new self('stock-brokers', 'stock-brokers', 'stock-brokers', 120, 'stock_brokerage_id', 'stock_brokerage_audit_events', 'stock_brokerage_outbox', 'stock_brokerage_slug_ownership', 'stock_brokerage_categories', 'broker_legacy_stock_paths', '/stock-brokers/', 'STOCK_BROKER_NOT_FOUND', 'stock brokerage', 'stock_broker', 'stock_broker', 'stock_brokerage_id', StockBrokerage::class, StockBrokerageCategory::class, StockBrokerageSlugOwnership::class, StockBrokerSpecifications::class, [], false, false, false, 'stock-brokers', 'Stock Brokerage', 'platform_profiles.stock_broker_featured_ids', 'brokerage_count', 'affected_brokerage_count', 'moved_brokerage_count', true);
    }

    public static function gold(): self
    {
        return new self('gold-platforms', 'gold-platforms', 'gold-platforms', 120, 'gold_platform_id', 'gold_platform_audit_events', 'gold_platform_outbox', 'gold_platform_slug_ownership', 'gold_platform_categories', '', '/gold-platforms/', 'GOLD_PLATFORM_NOT_FOUND', 'gold platform', 'gold_platform', 'gold_platform', 'gold_platform_id', GoldPlatform::class, GoldPlatformCategory::class, GoldPlatformSlugOwnership::class, GoldPlatformSpecifications::class, [], false, false, false, 'gold-platforms', 'Gold Platform', 'platform_profiles.gold_platform_featured_ids', 'gold_platform_count', 'affected_gold_platform_count', 'moved_gold_platform_count', false);
    }

    public static function propFirms(): self
    {
        return new self('prop-firms', 'prop-firms', 'prop-firms', 120, 'prop_firm_id', 'prop_firm_audit_events', 'prop_firm_outbox', 'prop_firm_slug_ownership', 'prop_firm_categories', '', '/prop-firms/', 'PROP_FIRM_NOT_FOUND', 'prop firm', 'prop_firm', 'prop_firm', 'prop_firm_id', PropFirm::class, PropFirmCategory::class, PropFirmSlugOwnership::class, PropFirmSpecifications::class, [], false, false, false, 'prop-firms', 'Prop Firm', 'platform_profiles.prop_firm_featured_ids', 'prop_firm_count', 'affected_prop_firm_count', 'moved_prop_firm_count', false);
    }

    public static function for(string $key): self
    {
        return match ($key) {
            'broker' => self::forex(),
            'stock-brokers' => self::stock(),
            'gold-platforms' => self::gold(),
            'prop-firms' => self::propFirms(),
            default => throw new InvalidArgumentException('Unknown platform profile type.'),
        };
    }

    /** @return list<string> */
    public function editableFields(): array
    {
        return ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', ...$this->summaryFields, 'specifications', 'category_id', 'content', 'meta_description', 'status'];
    }
}
