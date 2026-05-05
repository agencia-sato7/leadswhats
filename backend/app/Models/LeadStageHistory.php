<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadStageHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'from_column_id',
        'to_column_id',
        'moved_by_user_id',
        'move_source',
        'reason',
        'moved_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
        ];
    }
}
