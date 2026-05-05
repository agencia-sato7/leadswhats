<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('first_inbound_at')->nullable()->after('is_repeat_lead');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_rescue')->default(false)->after('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_rescue');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('first_inbound_at');
        });
    }
};
