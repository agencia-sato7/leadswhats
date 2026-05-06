<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'conversation_id',
        'provider',
        'direction',
        'channel',
        'body',
        'audio_transcript',
        'sent_at',
        'external_message_id',
        'raw_payload',
        'is_rescue',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'raw_payload' => 'array',
            'is_rescue' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
