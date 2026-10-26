<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCategories();
        $this->createProfiles();
        $this->createSlugOwnership();
        $this->createOutbox();
        $this->createAuditEvents();
    }

    private function createCategories(): void
    {
        if (! Schema::hasTable('prop_firm_categories')) {
            Schema::create('prop_firm_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80);
                $table->string('name_normalized', 240);
                $table->string('public_name_normalized', 240);
                $table->unsignedBigInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
                $table->unique('name_normalized', 'prop_cat_name_norm_uq');
            });
        }

        $this->ensureIndex('prop_firm_categories', 'prop_cat_name_norm_uq', ['name_normalized'], true);
    }

    private function createProfiles(): void
    {
        if (! Schema::hasTable('prop_firms')) {
            Schema::create('prop_firms', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 30)->default('prop-firms');
                $table->string('title', 120);
                $table->string('title_normalized', 240);
                $table->string('title_public_normalized', 240);
                $table->string('english_name', 120);
                $table->string('english_name_normalized', 240);
                $table->string('english_public_normalized', 240);
                $table->string('slug', 120);
                $table->text('description')->default('');
                $table->text('description_normalized');
                $table->text('description_public_normalized');
                $table->text('markets_public_normalized')->nullable();
                $table->string('logo', 2048)->default('');
                $table->string('website_url', 2048)->default('');
                $table->char('founded_year', 4)->default('');
                $table->json('specifications');
                $table->longText('content');
                $table->string('meta_description', 500)->default('');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('status', 20)->default('draft');
                $table->unsignedBigInteger('author_id');
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->unsignedBigInteger('version')->default(1);
                $table->binary('title_sort_key')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreign('category_id', 'prop_firm_category_fk')->references('id')->on('prop_firm_categories')->restrictOnDelete();
                $table->foreign('author_id', 'prop_firm_author_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('created_by', 'prop_firm_created_by_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by', 'prop_firm_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('deleted_by', 'prop_firm_deleted_by_fk')->references('id')->on('users')->restrictOnDelete();
                $table->unique('slug', 'prop_firm_slug_uq');
                $table->index(['created_at', 'id'], 'prop_firm_created_idx');
                $table->index(['category_id', 'status', 'deleted_at'], 'prop_firm_category_state_idx');
                $table->index(['status', 'published_at', 'id'], 'prop_firm_status_pub_idx');
                $table->index(['title_normalized', 'created_at'], 'prop_firm_title_created_idx');
                $table->index(['english_name_normalized', 'created_at'], 'prop_firm_english_created_idx');
            });
        }

        $this->ensureIndex('prop_firms', 'prop_firm_slug_uq', ['slug'], true);
        $this->ensureIndex('prop_firms', 'prop_firm_created_idx', ['created_at', 'id']);
        $this->ensureIndex('prop_firms', 'prop_firm_category_state_idx', ['category_id', 'status', 'deleted_at']);
        $this->ensureIndex('prop_firms', 'prop_firm_status_pub_idx', ['status', 'published_at', 'id']);
        $this->ensureIndex('prop_firms', 'prop_firm_title_created_idx', ['title_normalized', 'created_at']);
        $this->ensureIndex('prop_firms', 'prop_firm_english_created_idx', ['english_name_normalized', 'created_at']);
    }

    private function createSlugOwnership(): void
    {
        if (! Schema::hasTable('prop_firm_slug_ownership')) {
            Schema::create('prop_firm_slug_ownership', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 120);
                $table->unsignedBigInteger('prop_firm_id');
                $table->boolean('was_public')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('prop_firm_id', 'prop_slug_platform_fk')->references('id')->on('prop_firms')->restrictOnDelete();
                $table->unique('slug', 'prop_slug_value_uq');
                $table->index(['prop_firm_id', 'was_public'], 'prop_firm_slug_public_idx');
            });
        }

        $this->ensureIndex('prop_firm_slug_ownership', 'prop_slug_value_uq', ['slug'], true);
        $this->ensureIndex('prop_firm_slug_ownership', 'prop_firm_slug_public_idx', ['prop_firm_id', 'was_public']);
    }

    private function createOutbox(): void
    {
        if (! Schema::hasTable('prop_firm_outbox')) {
            Schema::create('prop_firm_outbox', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prop_firm_id')->nullable();
                $table->string('event', 80);
                $table->json('payload');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('prop_firm_id', 'prop_outbox_platform_fk')->references('id')->on('prop_firms')->restrictOnDelete();
                $table->index(['delivered_at', 'available_at', 'created_at'], 'prop_outbox_delivery_idx');
            });
        }

        $this->ensureIndex('prop_firm_outbox', 'prop_outbox_delivery_idx', ['delivered_at', 'available_at', 'created_at']);
    }

    private function createAuditEvents(): void
    {
        if (! Schema::hasTable('prop_firm_audit_events')) {
            Schema::create('prop_firm_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('target_type', 30);
                $table->unsignedBigInteger('target_id');
                $table->string('event', 80);
                $table->string('request_id', 80);
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('actor_id', 'prop_audit_actor_fk')->references('id')->on('users')->restrictOnDelete();
                $table->index(['target_type', 'target_id'], 'prop_audit_target_idx');
                $table->index('created_at', 'prop_audit_created_idx');
            });
        }

        $this->ensureIndex('prop_firm_audit_events', 'prop_audit_target_idx', ['target_type', 'target_id']);
        $this->ensureIndex('prop_firm_audit_events', 'prop_audit_created_idx', ['created_at']);
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, string $indexName, array $columns, bool $unique = false): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName, $columns, $unique): void {
            if ($unique) {
                $table->unique($columns, $indexName);
            } else {
                $table->index($columns, $indexName);
            }
        });
    }

    public function down(): void
    {
        foreach (['prop_firm_audit_events', 'prop_firm_outbox', 'prop_firm_slug_ownership', 'prop_firms', 'prop_firm_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
