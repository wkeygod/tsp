<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionLog extends Model
{
    protected $fillable = [
        'action_type',
        'target_type',
        'target_id',
        'performed_by_user_id',
        'reason',
        'context',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
