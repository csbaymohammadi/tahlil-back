<?php

namespace App\Providers;

use App\Media\MediaConsumers;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\NewsItem;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaConsumers::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('panel.trusted_proxies'));
        $this->app->make(MediaConsumers::class)->register('bonus',
            static fn (int $id, User $actor): bool => Bonus::whereKey($id)->exists(),
            static fn (int $id): bool => Bonus::public()->whereKey($id)->exists(),
        );
        Auth::viaRequest('panel-session', fn (Request $request) => $request->attributes->get('panel_user'));
        $this->app->make(MediaConsumers::class)->register(
            'blog-article',
            static fn (int $id, User $actor): bool => BlogArticle::query()->whereKey($id)->exists(),
            static fn (int $id): bool => BlogArticle::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'news-item',
            static fn (int $id, User $actor): bool => NewsItem::query()->whereKey($id)->exists(),
            static fn (int $id): bool => NewsItem::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'exchange',
            static fn (int $id, User $actor): bool => Exchange::query()->whereKey($id)->exists(),
            static fn (int $id): bool => Exchange::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'broker',
            static fn (int $id, User $actor): bool => Broker::query()->whereKey($id)->exists(),
            static fn (int $id): bool => Broker::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'stock-brokers',
            static fn (int $id, User $actor): bool => StockBrokerage::query()->whereKey($id)->exists(),
            static fn (int $id): bool => StockBrokerage::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'gold-platforms',
            static fn (int $id, User $actor): bool => GoldPlatform::query()->whereKey($id)->exists(),
            static fn (int $id): bool => GoldPlatform::public()->whereKey($id)->exists(),
        );
        $this->app->make(MediaConsumers::class)->register(
            'prop-firms',
            static fn (int $id, User $actor): bool => PropFirm::query()->whereKey($id)->exists(),
            static fn (int $id): bool => PropFirm::public()->whereKey($id)->exists(),
        );
    }
}
