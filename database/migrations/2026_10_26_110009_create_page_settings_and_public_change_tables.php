<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('snapshot');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $headings = ['exchanges' => ['exchange', 'معرفی صرافی‌ها', '/exchanges'], 'aparat' => ['video', 'تحلیل در آپارات', '/tutorials'], 'news' => ['news', 'اخبار بازار', '/news'], 'bonuses' => ['bonus', 'بونوس‌ها', '/bonuses'], 'videos' => ['video', 'ویدیوهای آموزشی', '/tutorials'], 'articles' => ['article', 'مقالات تحلیلی', '/blog']];
        $carousels = [];
        foreach ($headings as $key => [$kind, $title, $url]) {
            $carousels[] = ['key' => $key, 'kind' => $kind, 'title' => $title, 'description' => '', 'action_label' => 'مشاهده همه', 'action_url' => $url, 'selected_ids' => []];
        }
        $snapshot = ['hero' => ['title' => 'آگاهانه تحلیل کنید، آگاهانه انتخاب کنید', 'accent' => 'آگاهانه انتخاب کنید', 'description' => 'بررسی پلتفرم‌ها، اخبار بازار و آموزش‌های کاربردی.', 'primary_label' => 'بررسی صرافی‌ها', 'primary_url' => '/exchanges', 'secondary_label' => 'آموزش‌های کاربردی', 'secondary_mobile_label' => 'آموزش‌ها', 'secondary_url' => '/tutorials'],
            'carousels' => $carousels, 'menu' => array_map(static fn (string $key): array => ['key' => $key, 'selected_ids' => []], ['exchanges', 'forex', 'stock-brokers', 'gold-platforms', 'prop-firms']),
            'links' => array_fill_keys(['telegram', 'instagram', 'youtube', 'aparat'], ''), 'advertisements' => ['home_header' => [], 'news_and_articles' => []], 'infinite_slider' => []];
        DB::table('page_settings')->insert(['id' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('public_change_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->string('source_id', 128);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestamp('created_at');
            $table->unique(['source', 'source_id']);
            $table->index(['delivered_at', 'available_at', 'id']);
        });
        Schema::create('page_settings_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 80);
            $table->string('request_id', 128);
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_settings_audit_events');
        Schema::dropIfExists('public_change_deliveries');
        Schema::dropIfExists('page_settings');
    }
};
