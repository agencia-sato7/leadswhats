<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source_method')->default('auto')->after('source');
            $table->timestamp('source_updated_at')->nullable()->after('source_method');
        });

        Schema::create('lead_source_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('previous_source')->nullable();
            $table->string('new_source');
            $table->string('change_type')->default('manual');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['company_id', 'changed_at']);
            $table->index(['company_id', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_source_histories');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['source_method', 'source_updated_at']);
        });
    }
};
