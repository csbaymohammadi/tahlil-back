<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogArticleController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BonusCategoryController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\BrokerCategoryController;
use App\Http\Controllers\Admin\BrokerController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\ContentImportController;
use App\Http\Controllers\Admin\DashboardHomeController;
use App\Http\Controllers\Admin\ExchangeCategoryController;
use App\Http\Controllers\Admin\ExchangeItemController;
use App\Http\Controllers\Admin\GoldPlatformCategoryController;
use App\Http\Controllers\Admin\GoldPlatformController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\NewsCategoryController;
use App\Http\Controllers\Admin\NewsItemController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PropFirmCategoryController;
use App\Http\Controllers\Admin\PropFirmController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StockBrokerageCategoryController;
use App\Http\Controllers\Admin\StockBrokerageController;
use App\Http\Controllers\Admin\TutorialCategoryController;
use App\Http\Controllers\Admin\TutorialController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\BonusRatingController;
use App\Http\Controllers\PageSettingsController;
use App\Http\Controllers\PageViewController;
use App\Http\Controllers\PublicBlogController;
use App\Http\Controllers\PublicCommentController;
use App\Http\Controllers\PublicSearchController;
use App\Http\Controllers\SitemapEntriesController;
use App\Http\Middleware\BonusRatingRequest;
use App\Http\Middleware\PanelRequest;
use App\Http\Middleware\PresentationRequest;
use App\Http\Middleware\PublicCommentsRequest;
use App\Http\Middleware\PublicReadRequest;
use App\Http\Middleware\StrictCommentQuery;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->name('panel.')->middleware([PanelRequest::class, PresentationRequest::class])->group(function (): void {
    Route::get('page-settings', [App\Http\Controllers\Admin\PageSettingsController::class, 'show'])->name('page-settings.show');
    Route::put('page-settings', [App\Http\Controllers\Admin\PageSettingsController::class, 'update'])->name('page-settings.update');
    Route::get('page-settings/options/{area}/{key}', [App\Http\Controllers\Admin\PageSettingsController::class, 'options'])->whereIn('area', ['home', 'menu'])->name('page-settings.options.index');
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('dashboard/home', [DashboardHomeController::class, 'show'])->name('dashboard.home');
});

Route::middleware(PresentationRequest::class)->group(function (): void {
    Route::post('v1/analytics/page-views', [PageViewController::class, 'store'])->name('analytics.page-views');
    Route::options('v1/analytics/page-views', fn () => response()->noContent())->name('analytics.options');
});

Route::prefix('v1/admin/auth')->name('panel.auth.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('csrf', [AuthController::class, 'csrf'])->name('csrf');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::post('password', [AuthController::class, 'password'])->name('password');
});

Route::prefix('v1/admin')->name('panel.')->middleware(PanelRequest::class)->group(function (): void {
    Route::apiResource('users', UserController::class)->parameters(['users' => 'id'])->except('update');
    Route::patch('users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::apiResource('roles', RoleController::class)->parameters(['roles' => 'id'])->except('update');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->name('roles.update');
});

Route::prefix('v1/admin/media')->name('panel.media.')->middleware(PanelRequest::class)->group(function (): void {
    Route::post('/', [MediaController::class, 'store'])->name('store');
    Route::get('{id}', [MediaController::class, 'show'])->name('show');
    Route::get('{id}/preview', [MediaController::class, 'preview'])->name('preview');
    Route::delete('{id}', [MediaController::class, 'destroy'])->name('destroy');
});

Route::prefix('v1/admin/blog')->name('panel.blog.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('articles', [BlogArticleController::class, 'index'])->name('articles.index');
    Route::get('articles/{id}', [BlogArticleController::class, 'show'])->name('articles.show');
    Route::post('articles', [BlogArticleController::class, 'store'])->name('articles.store');
    Route::patch('articles/{id}', [BlogArticleController::class, 'update'])->name('articles.update');
    Route::delete('articles/{id}', [BlogArticleController::class, 'destroy'])->name('articles.destroy');
    Route::get('categories', [BlogCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [BlogCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [BlogCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [BlogCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [BlogCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/news')->name('panel.news.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [NewsItemController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [NewsItemController::class, 'show'])->name('items.show');
    Route::post('items', [NewsItemController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [NewsItemController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [NewsItemController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [NewsCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [NewsCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [NewsCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [NewsCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [NewsCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/exchanges')->name('panel.exchanges.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [ExchangeItemController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [ExchangeItemController::class, 'show'])->name('items.show');
    Route::post('items', [ExchangeItemController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [ExchangeItemController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [ExchangeItemController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [ExchangeCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [ExchangeCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [ExchangeCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [ExchangeCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [ExchangeCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/brokers')->name('panel.brokers.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [BrokerController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [BrokerController::class, 'show'])->name('items.show');
    Route::post('items', [BrokerController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [BrokerController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [BrokerController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [BrokerCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [BrokerCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [BrokerCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [BrokerCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [BrokerCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/stock-brokers')->name('panel.stock-brokers.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [StockBrokerageController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [StockBrokerageController::class, 'show'])->name('items.show');
    Route::post('items', [StockBrokerageController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [StockBrokerageController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [StockBrokerageController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [StockBrokerageCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [StockBrokerageCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [StockBrokerageCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [StockBrokerageCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [StockBrokerageCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/gold-platforms')->name('panel.gold-platforms.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [GoldPlatformController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [GoldPlatformController::class, 'show'])->name('items.show');
    Route::post('items', [GoldPlatformController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [GoldPlatformController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [GoldPlatformController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [GoldPlatformCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [GoldPlatformCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [GoldPlatformCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [GoldPlatformCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [GoldPlatformCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin/prop-firms')->name('panel.prop-firms.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [PropFirmController::class, 'index'])->name('items.index');
    Route::get('items/{id}', [PropFirmController::class, 'show'])->name('items.show');
    Route::post('items', [PropFirmController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [PropFirmController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [PropFirmController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [PropFirmCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{id}', [PropFirmCategoryController::class, 'show'])->name('categories.show');
    Route::post('categories', [PropFirmCategoryController::class, 'store'])->name('categories.store');
    Route::delete('categories/{id}', [PropFirmCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [PropFirmCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/admin')->name('panel.content-imports.')->middleware(PanelRequest::class)->group(function (): void {
    Route::post('content-imports', [ContentImportController::class, 'store'])->name('store');
    Route::get('content-imports/reports/{id}', [ContentImportController::class, 'report'])->name('report');
});

Route::prefix('v1/admin/bonuses')->name('panel.bonuses.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [BonusController::class, 'index'])->name('items.index');
    Route::post('items', [BonusController::class, 'store'])->name('items.store');
    Route::get('items/{id}', [BonusController::class, 'show'])->name('items.show');
    Route::patch('items/{id}', [BonusController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [BonusController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [BonusCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [BonusCategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{id}', [BonusCategoryController::class, 'show'])->name('categories.show');
    Route::delete('categories/{id}', [BonusCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [BonusCategoryController::class, 'merge'])->name('categories.merge');
});

Route::prefix('v1/bonuses')->name('bonuses.rating.')->middleware(BonusRatingRequest::class)->group(function (): void {
    Route::get('{id}/rating', [BonusRatingController::class, 'show'])->name('show');
    Route::put('{id}/rating', [BonusRatingController::class, 'update'])->name('update');
    Route::options('{id}/rating', fn () => response()->noContent())->name('options');
});

Route::prefix('v1/admin/tutorials')->name('panel.tutorials.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('items', [TutorialController::class, 'index'])->name('items.index');
    Route::post('items', [TutorialController::class, 'store'])->name('items.store');
    Route::get('items/{id}', [TutorialController::class, 'show'])->name('items.show');
    Route::patch('items/{id}', [TutorialController::class, 'update'])->name('items.update');
    Route::delete('items/{id}', [TutorialController::class, 'destroy'])->name('items.destroy');
    Route::get('categories', [TutorialCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [TutorialCategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{id}', [TutorialCategoryController::class, 'show'])->name('categories.show');
    Route::delete('categories/{id}', [TutorialCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/{id}/merge', [TutorialCategoryController::class, 'merge'])->name('categories.merge');
});
Route::prefix('v1/admin/comments')->name('panel.comments.')->middleware([PanelRequest::class, StrictCommentQuery::class])->group(function (): void {
    Route::get('/', [CommentController::class, 'index'])->name('index');
    Route::get('{id}', [CommentController::class, 'show'])->name('show');
    Route::put('{id}/reply', [CommentController::class, 'reply'])->name('reply');
    Route::delete('{id}', [CommentController::class, 'destroy'])->name('destroy');
});
Route::prefix('v1/comments')->name('comments.')->middleware([StrictCommentQuery::class, PublicCommentsRequest::class])->group(function (): void {
    Route::get('{section}/{parentId}', [PublicCommentController::class, 'index'])->middleware(PublicReadRequest::class)->name('index');
    Route::post('{section}/{parentId}', [PublicCommentController::class, 'store'])->name('store');
    Route::options('{section}/{parentId}', fn () => response()->noContent())->name('options');
});

Route::prefix('v1')->middleware(PublicReadRequest::class)->group(function (): void {
    Route::get('page-settings', [PageSettingsController::class, 'show'])->name('page-settings.public');
    Route::get('blog/articles', [PublicBlogController::class, 'index'])->name('public.blog.index');
    Route::get('blog/featured', [PublicBlogController::class, 'featured'])->name('public.blog.featured');
    Route::get('blog/articles/{slug}/related', [PublicBlogController::class, 'related'])->name('public.blog.related');
    Route::get('blog/articles/{slug}', [PublicBlogController::class, 'show'])->name('public.blog.show');
    Route::get('search', [PublicSearchController::class, 'index'])->name('public.search');
    Route::get('sitemap/entries', [SitemapEntriesController::class, 'index'])->name('public.sitemap');
    foreach (['page-settings', 'blog/articles', 'blog/featured', 'blog/articles/{slug}', 'blog/articles/{slug}/related', 'search', 'sitemap/entries'] as $path) {
        Route::options($path, fn () => response()->noContent());
    }
});
