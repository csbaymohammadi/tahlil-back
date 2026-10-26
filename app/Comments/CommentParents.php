<?php

namespace App\Comments;

use App\Exceptions\PanelAuthException;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\NewsItem;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommentParents
{
    public const MAP = [
        'blog' => [BlogArticle::class, '/blog/', 'Blog'],
        'news' => [NewsItem::class, '/news/', 'News'],
        'exchanges' => [Exchange::class, '/exchanges/', 'Cryptocurrency Exchanges'],
        'brokers' => [Broker::class, '/brokers/', 'Forex Brokers'],
        'stock-brokers' => [StockBrokerage::class, '/stock-brokers/', 'Stock Brokerages'],
        'gold-platforms' => [GoldPlatform::class, '/gold-platforms/', 'Gold Platforms'],
        'prop-firms' => [PropFirm::class, '/prop-firms/', 'Prop Firms'],
        'bonus' => [Bonus::class, '/bonuses/', 'Bonuses'],
    ];

    public function assertSection(string $section): void
    {
        if (! isset(self::MAP[$section])) {
            throw ValidationException::withMessages(['section' => ['Choose a supported comment section.']]);
        }
    }

    public function section(Model $parent): string
    {
        foreach (self::MAP as $section => [$class]) {
            if ($parent instanceof $class) {
                return $section;
            }
        }
        throw new \InvalidArgumentException('Unsupported comment parent model.');
    }

    public function find(string $section, int $id, string $lock = 'none'): ?Model
    {
        $this->assertSection($section);
        $class = self::MAP[$section][0];
        $query = $class::withTrashed()->whereKey($id);
        if ($lock === 'update') {
            $query->lockForUpdate();
        } elseif ($lock === 'shared') {
            $query->sharedLock();
        }

        return $query->first();
    }

    public function publicParent(string $section, int $id, string $lock = 'none'): Model
    {
        $parent = $this->find($section, $id, $lock);
        if ($this->visibility($parent) !== 'public') {
            throw new PanelAuthException(404, 'CONTENT_NOT_FOUND', 'The public content was not found.');
        }

        return $parent;
    }

    public function visibility(?Model $parent): string
    {
        if (! $parent || $parent->getAttribute('deleted_at') !== null) {
            return 'deleted';
        }

        return $parent->getAttribute('status') === 'published' ? 'public' : 'draft';
    }
}
