<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'company_whatsapp_integrations';

    private const LEGACY_COLUMNS = [
        'integration_type',
        'session_status',
        'qr_code_base64',
        'baileys_phone',
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = array_values(array_filter(
            self::LEGACY_COLUMNS,
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $missingColumns = array_values(array_filter(
            self::LEGACY_COLUMNS,
            fn (string $column): bool => !Schema::hasColumn(self::TABLE, $column),
        ));

        Schema::table(self::TABLE, function (Blueprint $table) use ($missingColumns): void {
            if (in_array('integration_type', $missingColumns, true)) {
                $table->string('integration_type', 20)->default('meta_cloud');
            }
            if (in_array('session_status', $missingColumns, true)) {
                $table->string('session_status', 20)->nullable();
            }
            if (in_array('qr_code_base64', $missingColumns, true)) {
                $table->text('qr_code_base64')->nullable();
            }
            if (in_array('baileys_phone', $missingColumns, true)) {
                $table->string('baileys_phone')->nullable();
            }
        });
    }
};
