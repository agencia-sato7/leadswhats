<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyWhatsAppIntegration extends Model
{
    use HasFactory;

    protected $table = "company_whatsapp_integrations";

    protected $fillable = [
        "company_id",
        "provider",
        "status",
        "phone_number",
        "phone_number_id",
        "business_account_id",
        "access_token_encrypted",
        "webhook_verify_token",
        "connected_at",
        "last_error",
    ];

    protected function casts(): array
    {
        return [
            "access_token_encrypted" => "encrypted",
            "connected_at" => "datetime",
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
