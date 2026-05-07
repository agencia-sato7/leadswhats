<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->dropUnique('kanban_columns_pipeline_id_position_unique');
            $table->index(['pipeline_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->dropIndex('kanban_columns_pipeline_id_position_index');
            $table->unique(['pipeline_id', 'position']);
        });
    }
};
