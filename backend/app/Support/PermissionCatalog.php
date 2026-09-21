<?php

namespace App\Support;

final class PermissionCatalog
{
    /** @return array<string, array{name:string,group:string}> */
    public static function all(): array
    {
        return [
            'dashboard.view' => ['name' => 'Visualizar Visão Geral', 'group' => 'Visão Geral'],
            'attendance.view' => ['name' => 'Visualizar Atendimento', 'group' => 'Atendimento'],
            'attendance.respond' => ['name' => 'Responder atendimentos', 'group' => 'Atendimento'],
            'conversations.view' => ['name' => 'Visualizar Conversas', 'group' => 'Conversas'],
            'conversations.respond' => ['name' => 'Responder conversas', 'group' => 'Conversas'],
            'followups.view' => ['name' => 'Visualizar Acompanhamento', 'group' => 'Acompanhamento'],
            'crm.view' => ['name' => 'Visualizar Auto-CRM', 'group' => 'Auto-CRM'],
            'crm.manage' => ['name' => 'Administrar Auto-CRM', 'group' => 'Auto-CRM'],
            'contacts.view' => ['name' => 'Visualizar Contatos', 'group' => 'Contatos'],
            'contacts.export' => ['name' => 'Exportar contatos', 'group' => 'Contatos'],
            'contacts.classify' => ['name' => 'Classificar origem', 'group' => 'Contatos'],
            'conversation_intelligence.view' => ['name' => 'Visualizar Inteligência de Conversas', 'group' => 'Inteligência'],
            'conversation_intelligence.analyze' => ['name' => 'Executar análise de conversas', 'group' => 'Inteligência'],
            'campaign_intelligence.view' => ['name' => 'Visualizar Inteligência de Campanhas', 'group' => 'Inteligência'],
            'campaign_intelligence.analyze' => ['name' => 'Executar análise de campanhas', 'group' => 'Inteligência'],
            'whatsapp_settings.view' => ['name' => 'Visualizar Configurações do WhatsApp', 'group' => 'WhatsApp'],
            'whatsapp_settings.manage' => ['name' => 'Administrar conexão do WhatsApp', 'group' => 'WhatsApp'],
            'leads.assignable' => ['name' => 'Pode receber leads e conversas', 'group' => 'Operação'],
        ];
    }

    /** @return list<string> */
    public static function gestor(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function sdr(): array
    {
        return [
            'dashboard.view', 'attendance.view', 'attendance.respond',
            'conversations.view', 'conversations.respond', 'followups.view',
            'crm.view', 'contacts.view', 'leads.assignable',
        ];
    }

    /** @return list<string> */
    public static function whatsappConnector(): array
    {
        return ['whatsapp_settings.view', 'whatsapp_settings.manage'];
    }
}
