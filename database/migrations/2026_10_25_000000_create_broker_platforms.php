<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['broker_categories' => true, 'stock_brokerage_categories' => false] as $tableName => $hasKey) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, function (Blueprint $table) use ($hasKey): void {
                $table->id();
                $table->string('name', 80);
                $table->string('name_normalized', 240)->unique();
                $table->string('public_name_normalized', 240);
                if ($hasKey) {
                    $table->string('key', 100)->unique();
                }
                $table->unsignedBigInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->createProfiles('brokers', false);
        $this->createProfiles('stock_brokerages', true);
        $this->createSlugOwnership('broker_slug_ownership', 'brokers', 'broker_id');
        $this->createSlugOwnership('stock_brokerage_slug_ownership', 'stock_brokerages', 'stock_brokerage_id');
        $this->createOperationalTables('broker', 'broker_id', 'brokers');
        $this->createOperationalTables('stock_brokerage', 'stock_brokerage_id', 'stock_brokerages');

        if (! Schema::hasTable('broker_legacy_stock_paths')) {
            Schema::create('broker_legacy_stock_paths', function (Blueprint $table): void {
                $table->id();
                $table->string('source_slug', 200)->unique();
                $table->foreignId('stock_brokerage_id')->constrained('stock_brokerages')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->index('stock_brokerage_id');
            });
        }
    }

    private function createProfiles(string $tableName, bool $stock): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($stock): void {
            $table->id();
            if ($stock) {
                $table->string('kind', 30)->default('stock-brokers');
            }
            $nameLimit = $stock ? 120 : 200;
            $table->string('title', $nameLimit);
            $table->string('title_normalized', $nameLimit * 2);
            $table->string('title_public_normalized', $nameLimit * 2);
            $table->string('english_name', $nameLimit);
            $table->string('english_name_normalized', $nameLimit * 2);
            $table->string('english_public_normalized', $nameLimit * 2);
            $table->string('slug', $nameLimit)->unique();
            $table->text('description')->default('');
            $table->text('description_normalized');
            $table->text('description_public_normalized');
            $table->text('markets_public_normalized')->nullable();
            $table->string('logo', 2048)->default('');
            $table->string('website_url', 2048)->default('');
            $table->char('founded_year', 4)->default('');
            if (! $stock) {
                $table->string('platforms', 1500)->default('');
                $table->string('markets', 1500)->default('');
                $table->string('access', 1500)->default('');
                $table->string('account_currency', 1500)->default('');
            }
            $table->json('specifications');
            $table->longText('content');
            $table->string('meta_description', 500)->default('');
            $table->foreignId('category_id')->nullable()->constrained($stock ? 'stock_brokerage_categories' : 'broker_categories')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->binary('title_sort_key')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'id']);
            $table->index(['category_id', 'status', 'deleted_at']);
            $table->index(['status', 'published_at', 'id']);
            $table->index(['title_normalized', 'created_at']);
            $table->index(['english_name_normalized', 'created_at']);
        });
    }

    private function createSlugOwnership(string $tableName, string $profileTable, string $foreignKey): void
    {
        $indexName = $tableName === 'stock_brokerage_slug_ownership' ? 'stock_broker_slug_public_idx' : 'broker_slug_public_idx';
        if (Schema::hasTable($tableName)) {
            if (! Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($foreignKey, $indexName): void {
                    $table->index([$foreignKey, 'was_public'], $indexName);
                });
            }

            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($profileTable, $foreignKey, $indexName): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->foreignId($foreignKey)->constrained($profileTable)->restrictOnDelete();
            $table->boolean('was_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index([$foreignKey, 'was_public'], $indexName);
        });
    }

    private function createOperationalTables(string $prefix, string $foreignKey, string $profileTable): void
    {
        $outboxTable = $prefix.'_outbox';
        $deliveryIndexName = $prefix === 'stock_brokerage' ? 'stock_broker_outbox_delivery_idx' : 'broker_outbox_delivery_idx';
        if (! Schema::hasTable($outboxTable)) {
            Schema::create($outboxTable, function (Blueprint $table) use ($foreignKey, $profileTable, $deliveryIndexName): void {
                $table->id();
                $table->foreignId($foreignKey)->nullable()->constrained($profileTable)->restrictOnDelete();
                $table->string('event', 80);
                $table->json('payload');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['delivered_at', 'available_at', 'created_at'], $deliveryIndexName);
            });
        } elseif (! Schema::hasIndex($outboxTable, $deliveryIndexName)) {
            Schema::table($outboxTable, function (Blueprint $table) use ($deliveryIndexName): void {
                $table->index(['delivered_at', 'available_at', 'created_at'], $deliveryIndexName);
            });
        }

        $auditTable = $prefix.'_audit_events';
        if (! Schema::hasTable($auditTable)) {
            Schema::create($auditTable, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('target_type', 30);
                $table->unsignedBigInteger('target_id');
                $table->string('event', 80);
                $table->string('request_id', 80);
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['target_type', 'target_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_legacy_stock_paths');
        foreach (['stock_brokerage_audit_events', 'stock_brokerage_outbox', 'broker_audit_events', 'broker_outbox', 'stock_brokerage_slug_ownership', 'broker_slug_ownership', 'stock_brokerages', 'brokers', 'stock_brokerage_categories', 'broker_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
