<?php

namespace App\Services;

use App\Models\Card;
use App\Models\CardAssignment;
use App\Models\CardStatusHistory;
use Illuminate\Support\Facades\DB;

class CardLifecycleService
{
    public function __construct(private readonly AdminActionLogService $auditLogService)
    {
    }

    public function blockCard(Card $card, ?string $reason, ?int $performedByUserId): void
    {
        $normalizedReason = $reason ?: 'Blocked by admin';

        DB::transaction(function () use ($card, $normalizedReason, $performedByUserId): void {
            $oldStatus = $card->status;
            if ($oldStatus !== Card::STATUS_BLOCKED) {
                $card->update([
                    'status' => Card::STATUS_BLOCKED,
                    'is_active' => false,
                    'blocked_at' => now(),
                    'block_reason' => $normalizedReason,
                ]);

                $this->recordStatusChange($card, $oldStatus, Card::STATUS_BLOCKED, $normalizedReason, $performedByUserId);
            }

            $this->auditLogService->log(
                'card.blocked',
                'card',
                $card->id,
                $performedByUserId,
                $normalizedReason,
                ['old_status' => $oldStatus, 'new_status' => Card::STATUS_BLOCKED]
            );
        });
    }

    public function unblockCard(Card $card, ?int $performedByUserId): void
    {
        DB::transaction(function () use ($card, $performedByUserId): void {
            $oldStatus = $card->status;
            if ($oldStatus !== Card::STATUS_ACTIVE) {
                $card->update([
                    'status' => Card::STATUS_ACTIVE,
                    'is_active' => true,
                    'blocked_at' => null,
                    'block_reason' => null,
                ]);

                $this->recordStatusChange($card, $oldStatus, Card::STATUS_ACTIVE, 'Unblocked by admin', $performedByUserId);
            }

            $this->auditLogService->log(
                'card.unblocked',
                'card',
                $card->id,
                $performedByUserId,
                'Unblocked by admin',
                ['old_status' => $oldStatus, 'new_status' => Card::STATUS_ACTIVE]
            );
        });
    }

    public function markCardLost(Card $card, ?string $reason, ?int $performedByUserId): void
    {
        $normalizedReason = $reason ?: 'Marked as lost';

        DB::transaction(function () use ($card, $normalizedReason, $performedByUserId): void {
            $oldStatus = $card->status;
            if ($oldStatus !== Card::STATUS_LOST) {
                $card->update([
                    'status' => Card::STATUS_LOST,
                    'is_active' => false,
                    'blocked_at' => now(),
                    'block_reason' => $normalizedReason,
                ]);

                $this->recordStatusChange($card, $oldStatus, Card::STATUS_LOST, $normalizedReason, $performedByUserId);
            }

            $this->auditLogService->log(
                'card.marked_lost',
                'card',
                $card->id,
                $performedByUserId,
                $normalizedReason,
                ['old_status' => $oldStatus, 'new_status' => Card::STATUS_LOST]
            );
        });
    }

    public function replaceCard(
        Card $card,
        string $newUid,
        ?int $employeeId,
        ?string $reason,
        ?int $performedByUserId
    ): Card {
        $effectiveEmployeeId = (int) ($employeeId ?: $card->assigned_employee_id ?: 0);

        if ($effectiveEmployeeId <= 0) {
            throw new \InvalidArgumentException('Selectionnez un employe pour la carte de remplacement.');
        }

        return DB::transaction(function () use ($card, $newUid, $effectiveEmployeeId, $reason, $performedByUserId): Card {
            $oldAssignedEmployeeId = $card->assigned_employee_id;
            $normalizedUid = Card::normalizeUid($newUid);

            $newCard = Card::query()->create([
                'employee_id' => $effectiveEmployeeId,
                'assigned_employee_id' => $effectiveEmployeeId,
                'uid' => $normalizedUid,
                'balance' => $card->balance,
                'is_active' => true,
                'status' => Card::STATUS_ACTIVE,
                'issued_at' => now()->toDateString(),
                'notes' => 'Replacement card for #' . $card->id,
            ]);

            $this->openAssignment($newCard, $effectiveEmployeeId, 'Replacement card assigned', $performedByUserId);

            $oldStatus = $card->status;
            $card->update([
                'status' => Card::STATUS_REPLACED,
                'is_active' => false,
                'replaced_by_card_id' => $newCard->id,
                'blocked_at' => now(),
                'block_reason' => $reason ?: 'Replaced by new card',
                'assigned_employee_id' => null,
            ]);

            if ($oldAssignedEmployeeId) {
                $this->closeOpenAssignment($card, 'Card replaced', $performedByUserId);
            }

            $this->recordStatusChange($card, $oldStatus, Card::STATUS_REPLACED, $reason ?: 'Card replaced', $performedByUserId);

            $this->auditLogService->log(
                'card.replaced',
                'card',
                $card->id,
                $performedByUserId,
                $reason,
                [
                    'old_card_id' => $card->id,
                    'new_card_id' => $newCard->id,
                    'employee_id' => $effectiveEmployeeId,
                ]
            );

            return $newCard;
        });
    }

    private function openAssignment(Card $card, int $employeeId, string $reason, ?int $changedByUserId): void
    {
        CardAssignment::query()->create([
            'card_id' => $card->id,
            'employee_id' => $employeeId,
            'assigned_at' => now(),
            'change_reason' => $reason,
            'changed_by_user_id' => $changedByUserId,
        ]);
    }

    private function closeOpenAssignment(Card $card, string $reason, ?int $changedByUserId): void
    {
        $openAssignment = CardAssignment::query()
            ->where('card_id', $card->id)
            ->whereNull('unassigned_at')
            ->latest('assigned_at')
            ->first();

        if (!$openAssignment) {
            return;
        }

        $openAssignment->update([
            'unassigned_at' => now(),
            'change_reason' => $reason,
            'changed_by_user_id' => $changedByUserId,
        ]);
    }

    private function recordStatusChange(Card $card, ?string $from, string $to, ?string $reason, ?int $changedByUserId): void
    {
        CardStatusHistory::query()->create([
            'card_id' => $card->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by_user_id' => $changedByUserId,
            'changed_at' => now(),
        ]);
    }
}
