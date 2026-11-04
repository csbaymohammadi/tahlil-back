<?php

namespace App\Ratings;

use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Support\PlatformProfileDefinition;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PlatformRatings
{
    public const TYPES = ['exchanges' => Exchange::class, 'brokers' => Broker::class,
        'stock-brokers' => StockBrokerage::class, 'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class];

    public static function supports(Model $record): bool
    {
        return in_array($record::class, self::TYPES, true);
    }

    public static function kind(Model $record): string
    {
        $kind = array_search($record::class, self::TYPES, true);
        if ($kind === false) {
            throw new InvalidArgumentException('Unsupported platform rating kind.');
        }

        return $kind;
    }

    /** @return array{model: class-string<Model>, foreign_key: string, audit: string, outbox: string, target: string, not_found: string} */
    public static function definition(string $kind): array
    {
        if (! isset(self::TYPES[$kind])) {
            throw new InvalidArgumentException('Unsupported platform rating kind.');
        }
        if ($kind === 'exchanges') {
            return ['model' => Exchange::class, 'foreign_key' => 'exchange_id', 'audit' => 'exchange_audit_events',
                'outbox' => 'exchange_outbox', 'target' => 'exchange', 'not_found' => 'EXCHANGE_NOT_FOUND'];
        }
        $definition = PlatformProfileDefinition::for($kind === 'brokers' ? 'broker' : $kind);

        return ['model' => $definition->profileModel, 'foreign_key' => $definition->profileForeignKey,
            'audit' => $definition->auditTable, 'outbox' => $definition->outboxTable,
            'target' => $definition->auditTarget, 'not_found' => $definition->notFoundCode];
    }

    /** @return array{rating_average: float|null, rating_count: int, rating_revision: int} */
    public static function projection(Model $record): array
    {
        return $record->ratingAggregate?->projection() ?? ['rating_average' => null, 'rating_count' => 0, 'rating_revision' => 0];
    }
}
