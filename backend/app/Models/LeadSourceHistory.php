<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadSourceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'previous_source',
        'new_source',
        'change_type',
        'reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }
}
