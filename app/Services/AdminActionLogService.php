<?php

namespace App\Services;

use App\Models\AdminActionLog;

class AdminActionLogService
{
    public function log(
        string $actionType,
        string $targetType,
        ?int $targetId,
        ?int $performedByUserId,
        ?string $reason = null,
        array $context = []
    ): AdminActionLog {
        return AdminActionLog::query()->create([
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'performed_by_user_id' => $performedByUserId,
            'reason' => $reason,
            'context' => $context,
            'performed_at' => now(),
        ]);
    }
}
