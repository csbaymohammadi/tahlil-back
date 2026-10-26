<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogArticleController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BonusCategoryController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\BrokerCategoryController;
use App\Http\Controllers\Admin\BrokerController;
use App\Http\Controllers\Admin\ContentImportController;
use App\Http\Controllers\Admin\ExchangeCategoryController;
use App\Http\Controllers\Admin\ExchangeItemController;
use App\Http\Controllers\Admin\GoldPlatformCategoryController;
use App\Http\Controllers\Admin\GoldPlatformController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\NewsCategoryController;
use App\Http\Controllers\Admin\NewsItemController;
use App\Http\Controllers\Admin\PropFirmCategoryController;
use App\Http\Controllers\Admin\PropFirmController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StockBrokerageCategoryController;
use App\Http\Controllers\Admin\StockBrokerageController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\BonusRatingController;
use App\Http\Middleware\BonusRatingRequest;
use App\Http\Middleware\PanelRequest;
use Illuminate\Support\Facades\Route;

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
