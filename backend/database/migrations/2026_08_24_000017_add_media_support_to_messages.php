<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('delivery_status')->nullable()->after('external_message_id');
            $table->text('delivery_error')->nullable()->after('delivery_status');
            $table->index(['company_id', 'external_message_id']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('mime_type');
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('external_media_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'external_message_id']);
            $table->dropColumn(['delivery_status', 'delivery_error']);
        });
    }
};
