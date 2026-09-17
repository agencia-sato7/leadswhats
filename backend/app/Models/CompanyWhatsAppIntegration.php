<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyWhatsAppIntegration extends Model
{
    use HasFactory;

    protected $table = 'company_whatsapp_integrations';

    protected $fillable = [
        'company_id',
        'provider',
        'status',
        'connection_mode',
        'phone_number',
        'phone_number_id',
        'business_account_id',
        'waba_id',
        'business_id',
        'page_ids',
        'catalog_ids',
        'dataset_ids',
        'instagram_account_ids',
        'access_token_encrypted',
        'webhook_verify_token',
        'connected_at',
        'coexistence_opted_in_at',
        'history_sync_status',
        'contacts_sync_status',
        'token_expires_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'page_ids' => 'array',
            'catalog_ids' => 'array',
            'dataset_ids' => 'array',
            'instagram_account_ids' => 'array',
            'connected_at' => 'datetime',
            'coexistence_opted_in_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
