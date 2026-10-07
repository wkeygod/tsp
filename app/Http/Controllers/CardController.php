<?php

namespace App\Http\Controllers;

use App\Models\AdminActionLog;
use App\Models\Card;
use App\Models\CardAssignment;
use App\Models\CardStatusHistory;
use App\Models\Employee;
use App\Services\CardLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CardController extends Controller
{
    public function __construct(private readonly CardLifecycleService $cardLifecycleService)
    {
    }

    public function index(Request $request): View
    {
        $status = trim((string) $request->string('status'));
        $employeeId = $request->integer('employee_id');
        $assignmentState = trim((string) $request->string('assignment_state'));

        $cards = Card::query()
            ->with(['employee', 'replacedByCard'])
            ->when(in_array($status, Card::STATUSES, true), function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($employeeId > 0, function ($query) use ($employeeId): void {
                $query->where('assigned_employee_id', $employeeId);
            })
            ->when($assignmentState === 'assigned', function ($query): void {
                $query->whereNotNull('assigned_employee_id');
            })
            ->when($assignmentState === 'unassigned', function ($query): void {
                $query->whereNull('assigned_employee_id');
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $employees = Employee::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'employee_no', 'first_name', 'last_name']);

        return view('cards.index', [
            'cards' => $cards,
            'employees' => $employees,
            'status' => $status,
            'employeeId' => $employeeId,
            'assignmentState' => $assignmentState,
            'statuses' => Card::STATUSES,
        ]);
    }

    public function create(): View
    {
        $employees = Employee::query()->where('is_active', true)->orderBy('last_name')->get();
        $selectedEmployeeId = request()->integer('employee_id');

        return view('cards.create', [
            'employees' => $employees,
            'selectedEmployeeId' => $selectedEmployeeId,
            'statuses' => Card::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'uid' => Card::normalizeUid((string) $request->input('uid')),
        ]);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'uid' => ['required', 'string', 'max:100', 'unique:cards,uid'],
            'balance' => ['required', 'numeric', 'min:0'],
            'issued_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Card::STATUSES)],
            'blocked_at' => ['nullable', 'date'],
            'block_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payload = $this->prepareCardPayload($validated);
        $payload['assigned_employee_id'] = $validated['employee_id'];
        $payload['employee_id'] = $validated['employee_id']; // Legacy compatibility

        $card = Card::query()->create($payload);

        $this->openAssignment($card, (int) $validated['employee_id'], 'Initial assignment', $request->user()?->id);

        if ($card->status !== Card::STATUS_ACTIVE) {
            $this->recordStatusChange($card, null, $card->status, $card->block_reason, $request->user()?->id);
        }

        return redirect()->route('cards.index')->with('success', 'Carte creee avec succes.');
    }

    public function show(Card $card): View
    {
        $card->load([
            'employee',
            'replacedByCard',
            'assignments.employee',
            'assignments.changedBy',
            'statusHistories.changedBy',
            'mealLogs' => function ($query): void {
                $query->with(['employee'])->latest('logged_at')->limit(25);
            },
        ]);

        $employees = Employee::query()->where('is_active', true)->orderBy('last_name')->get();

        $auditLogs = AdminActionLog::query()
            ->with('performedBy')
            ->where('target_type', 'card')
            ->where('target_id', $card->id)
            ->latest('performed_at')
            ->limit(20)
            ->get();

        return view('cards.show', [
            'card' => $card,
            'employees' => $employees,
            'auditLogs' => $auditLogs,
        ]);
    }

    public function edit(Card $card): View
    {
        $employees = Employee::query()->orderBy('last_name')->get();

        return view('cards.edit', [
            'card' => $card,
            'employees' => $employees,
            'statuses' => Card::STATUSES,
        ]);
    }

    public function update(Request $request, Card $card)
    {
        $request->merge([
            'uid' => Card::normalizeUid((string) $request->input('uid')),
        ]);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'uid' => ['required', 'string', 'max:100', 'unique:cards,uid,' . $card->id],
            'balance' => ['required', 'numeric', 'min:0'],
            'issued_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Card::STATUSES)],
            'blocked_at' => ['nullable', 'date'],
            'block_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldStatus = $card->status;
        $oldAssignedEmployeeId = $card->assigned_employee_id;

        $payload = $this->prepareCardPayload($validated);
        $payload['assigned_employee_id'] = $validated['employee_id'];
        $payload['employee_id'] = $validated['employee_id']; // Legacy compatibility

        $card->update($payload);

        if ((int) $oldAssignedEmployeeId !== (int) $card->assigned_employee_id) {
            $this->closeOpenAssignment($card, 'Reassigned', $request->user()?->id);
            $this->openAssignment($card, (int) $card->assigned_employee_id, 'Reassigned', $request->user()?->id);
        }

        if ($oldStatus !== $card->status) {
            $this->recordStatusChange($card, $oldStatus, $card->status, $card->block_reason, $request->user()?->id);
        }

        return redirect()->route('cards.index')->with('success', 'Carte mise a jour.');
    }

    public function destroy(Card $card)
    {
        $card->delete();

        return redirect()->route('cards.index')->with('success', 'Carte supprimee.');
    }

    public function block(Request $request, Card $card)
    {
        $validated = $request->validate([
            'block_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->cardLifecycleService->blockCard($card, $validated['block_reason'] ?? null, $request->user()?->id);

        return back()->with('success', 'Carte bloquee.');
    }

    public function unblock(Request $request, Card $card)
    {
        $this->cardLifecycleService->unblockCard($card, $request->user()?->id);

        return back()->with('success', 'Carte debloquee.');
    }

    public function markLost(Request $request, Card $card)
    {
        $validated = $request->validate([
            'block_reason' => ['required', 'string', 'max:255'],
        ]);

        $this->cardLifecycleService->markCardLost($card, $validated['block_reason'], $request->user()?->id);

        return back()->with('success', 'Carte marquee comme perdue.');
    }

    public function detach(Request $request, Card $card)
    {
        if (!$card->assigned_employee_id) {
            return back()->with('error', 'La carte est deja non assignee.');
        }

        $this->closeOpenAssignment($card, 'Detached from employee', $request->user()?->id);
        $card->update([
            'assigned_employee_id' => null,
            'notes' => trim((string) $card->notes . "\nDetached on " . now()->format('Y-m-d H:i:s')),
        ]);

        return back()->with('success', 'Carte detachee de l\'employe.');
    }

    public function reassign(Request $request, Card $card)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $newEmployeeId = (int) $validated['employee_id'];

        if ((int) $card->assigned_employee_id === $newEmployeeId) {
            return back()->with('success', 'Carte deja assignee a cet employe.');
        }

        if ($card->assigned_employee_id) {
            $this->closeOpenAssignment($card, 'Reassigned to another employee', $request->user()?->id);
        }

        $card->update([
            'assigned_employee_id' => $newEmployeeId,
            'employee_id' => $newEmployeeId, // Legacy compatibility
        ]);

        $this->openAssignment($card, $newEmployeeId, 'Reassigned to employee', $request->user()?->id);

        return back()->with('success', 'Carte re-assignee avec succes.');
    }

    public function replace(Request $request, Card $card)
    {
        $request->merge([
            'new_uid' => Card::normalizeUid((string) $request->input('new_uid')),
        ]);

        $validated = $request->validate([
            'new_uid' => ['required', 'string', 'max:100', 'unique:cards,uid'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->cardLifecycleService->replaceCard(
                $card,
                $validated['new_uid'],
                $validated['employee_id'] ?? null,
                $validated['reason'] ?? null,
                $request->user()?->id
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Carte remplacee avec succes.');
    }

    private function prepareCardPayload(array $validated): array
    {
        $status = $validated['status'];
        $isActive = $status === Card::STATUS_ACTIVE;

        return [
            'uid' => $validated['uid'],
            'balance' => $validated['balance'],
            'issued_at' => $validated['issued_at'] ?? null,
            'is_active' => $isActive,
            'status' => $status,
            'blocked_at' => in_array($status, [Card::STATUS_BLOCKED, Card::STATUS_LOST], true)
                ? ($validated['blocked_at'] ?? now())
                : null,
            'block_reason' => in_array($status, [Card::STATUS_BLOCKED, Card::STATUS_LOST], true)
                ? ($validated['block_reason'] ?? null)
                : null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function setStatus(Card $card, string $newStatus, ?string $reason, ?int $changedByUserId): void
    {
        if ($card->status === $newStatus) {
            return;
        }

        $oldStatus = $card->status;

        $card->update([
            'status' => $newStatus,
            'is_active' => $newStatus === Card::STATUS_ACTIVE,
            'blocked_at' => in_array($newStatus, [Card::STATUS_BLOCKED, Card::STATUS_LOST, Card::STATUS_REPLACED], true) ? now() : null,
            'block_reason' => $newStatus === Card::STATUS_ACTIVE ? null : $reason,
        ]);

        $this->recordStatusChange($card, $oldStatus, $newStatus, $reason, $changedByUserId);
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
