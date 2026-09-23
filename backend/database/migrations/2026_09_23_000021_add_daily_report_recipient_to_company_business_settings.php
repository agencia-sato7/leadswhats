<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('company_business_settings', 'daily_report_recipient')) {
            Schema::table('company_business_settings', function (Blueprint $table) {
                // Destinatário único do relatório diário de IA da clínica. É nullable
                // no schema apenas porque clínicas já existentes não têm o dado; a
                // obrigatoriedade é regra do cadastro (Central da Agência).
                $table->string('daily_report_recipient')->nullable()->after('timezone');
            });
        }

        $this->backfillFromExistingManagers();
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_business_settings', 'daily_report_recipient')) {
            Schema::table('company_business_settings', function (Blueprint $table) {
                $table->dropColumn('daily_report_recipient');
            });
        }
    }

    /**
     * Clínicas criadas antes deste campo continuam recebendo o relatório: usamos o
     * e-mail do primeiro administrador ativo (ou, na falta dele, do primeiro
     * gestor) como destinatário inicial. Sem nenhum deles o campo fica nulo e o
     * envio fica pendente até alguém cadastrar o e-mail na Central da Agência.
     */
    private function backfillFromExistingManagers(): void
    {
        $settings = DB::table('company_business_settings')
            ->whereNull('daily_report_recipient')
            ->orderBy('id')
            ->get(['id', 'company_id']);

        foreach ($settings as $setting) {
            $email = DB::table('users')
                ->where('company_id', $setting->company_id)
                ->where('active', true)
                ->whereIn('role', ['admin', 'gestor'])
                ->orderByRaw("case when role = 'admin' then 0 else 1 end")
                ->orderBy('id')
                ->value('email');

            if (!is_string($email) || $email === '') {
                continue;
            }

            DB::table('company_business_settings')
                ->where('id', $setting->id)
                ->update(['daily_report_recipient' => $email]);
        }
    }
};
