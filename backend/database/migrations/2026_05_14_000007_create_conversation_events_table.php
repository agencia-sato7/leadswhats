<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'conversation_id', 'event_type', 'created_at'], 'conversation_events_company_conv_type_created_idx');
            $table->index(['company_id', 'user_id', 'event_type', 'created_at'], 'conversation_events_company_user_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
