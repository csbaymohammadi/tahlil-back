<?php

use App\Support\CategoryAssignments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (CategoryAssignments::MODELS as $recordClass => $categoryClass) {
            $recordTable = (new $recordClass)->getTable();
            $categoryTable = (new $categoryClass)->getTable();
            $tableName = $recordTable.'_category_assignments';
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($recordTable, $categoryTable): void {
                    $table->foreignId('record_id')->constrained($recordTable)->cascadeOnDelete();
                    $table->foreignId('category_id')->constrained($categoryTable)->restrictOnDelete();
                    $table->unsignedInteger('position');
                    $table->primary(['record_id', 'category_id']);
                    $table->index(['category_id', 'record_id'], $recordTable.'_cat_reverse_idx');
                });
            }
            DB::table($recordTable)->whereNotNull('category_id')->orderBy('id')->chunkById(500, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    if (! DB::table($tableName)->where('record_id', $row->id)->exists()) {
                        DB::table($tableName)->insertOrIgnore(['record_id' => $row->id, 'category_id' => $row->category_id, 'position' => 0]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Membership data is intentionally preserved on application rollback.
    }
};
