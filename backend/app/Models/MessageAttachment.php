<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = [
        'company_id', 'message_id', 'type', 'mime_type', 'original_name',
        'size_bytes', 'disk', 'path', 'external_media_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
