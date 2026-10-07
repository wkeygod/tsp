<?php

namespace App\Http\Controllers;

use App\Models\AdminActionLog;
use App\Models\Card;
use App\Models\CardAssignment;
use App\Models\Employee;
use App\Models\MealRule;
use App\Services\EmployeeLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeLifecycleService $employeeLifecycleService)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('q'));
        $status = trim((string) $request->string('status'));

        $employees = Employee::query()
            ->with(['card', 'lastMealLog'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('position', 'like', "%{$search}%")
                        ->orWhere('rig_company', 'like', "%{$search}%")
                        ->orWhereRaw("(first_name || ' ' || last_name) like ?", ["%{$search}%"])
                        ->orWhereRaw("(last_name || ' ' || first_name) like ?", ["%{$search}%"])
                        ->orWhereHas('card', function ($cardQuery) use ($search): void {
                            $cardQuery->where('uid', 'like', "%{$search}%");
                        })
                        ->orWhereHas('user', function ($userQuery) use ($search): void {
                            $userQuery->where('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status): void {
                $query->where('is_active', $status === 'active');
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('employees.index', compact('employees', 'search', 'status'));
    }

    public function create()
    {
        $mealRules = MealRule::where('is_active', true)->get();

        return view('employees.create', compact('mealRules'));
    }

    public function store(Request $request)
    {
        $activeMealRuleIds = MealRule::where('is_active', true)->pluck('id')->toArray();
        $validationRules = 'in:' . implode(',', $activeMealRuleIds);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:120'],
            'rig_company' => ['required', 'string', 'max:120'],
            'card_uid' => ['required', 'string', 'max:100'],
            'allowed_meal_types' => ['array', 'min:1'],
            'allowed_meal_types.*' => [$validationRules],
        ]);

        $normalizedCardUid = $this->normalizeCardUid($validated['card_uid'] ?? '');

        DB::transaction(function () use ($validated, $normalizedCardUid, $request): void {
            $employee = Employee::query()->create([
                'employee_no' => $this->generateEmployeeNo(),
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'position' => $validated['position'],
                'rig_company' => $validated['rig_company'],
                'allowed_meal_types' => $validated['allowed_meal_types'] ?? [],
                'is_active' => true,
            ]);

            $card = $this->findOrCreateAssignableCard($normalizedCardUid, $employee);
            $this->assignCardToEmployee(
                $card,
                $employee,
                $request->user()?->id,
                'Assignation initiale depuis la creation employe'
            );
        });

        return redirect()->route('employees.index')->with('success', 'Employe cree avec succes.');
    }

    public function show(Employee $employee): View
    {
        $employee->load([
            'card',
            'card.replacedByCard',
            'departmentModel',
            'user',
            'lastMealLog',
            'mealLogs' => function ($query): void {
                $query
                    ->with(['card', 'mealRule'])
                    ->latest('logged_at')
                    ->limit(10);
            },
        ]);

        $cardHistory = collect();
        if ($this->supportsCardAssignments()) {
            $cardHistory = CardAssignment::query()
                ->with(['card', 'changedBy'])
                ->where('employee_id', $employee->id)
                ->latest('assigned_at')
                ->limit(20)
                ->get();
        }

        $availableCardsQuery = Card::query()
            ->whereNull(Card::assignedEmployeeColumn());

        if (Card::hasStatusColumn()) {
            $availableCardsQuery->where('status', Card::STATUS_ACTIVE);
        } else {
            $availableCardsQuery->where('is_active', true);
        }

        $availableCards = $availableCardsQuery
            ->orderBy('uid')
            ->limit(30)
            ->get(['id', 'uid']);

        $auditLogs = AdminActionLog::query()
            ->with('performedBy')
            ->where('target_type', 'employee')
            ->where('target_id', $employee->id)
            ->latest('performed_at')
            ->limit(15)
            ->get();

        return view('employees.show', [
            'employee' => $employee,
            'cardHistory' => $cardHistory,
            'availableCards' => $availableCards,
            'auditLogs' => $auditLogs,
        ]);
    }

    public function edit(Employee $employee)
    {
        $employee->load('card');
        $mealRules = MealRule::where('is_active', true)->get();

        return view('employees.edit', compact('employee', 'mealRules'));
    }

    public function update(Request $request, Employee $employee)
    {
        $activeMealRuleIds = MealRule::where('is_active', true)->pluck('id')->toArray();
        $validationRules = 'in:' . implode(',', $activeMealRuleIds);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:100'],
            'rig_company' => ['required', 'string', 'max:100'],
            'card_uid' => ['required', 'string', 'max:100'],
            'allowed_meal_types' => ['array', 'min:1'],
            'allowed_meal_types.*' => [$validationRules],
        ]);

        $normalizedCardUid = $this->normalizeCardUid($validated['card_uid'] ?? '');
        $employee->load('card');

        DB::transaction(function () use ($employee, $validated, $normalizedCardUid, $request): void {
            $employee->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'position' => $validated['position'],
                'rig_company' => $validated['rig_company'],
                'allowed_meal_types' => $validated['allowed_meal_types'] ?? [],
            ]);

            $currentCard = $employee->card;
            $targetCard = $this->findOrCreateAssignableCard($normalizedCardUid, $employee, $currentCard?->id);

            if ($currentCard && $targetCard->id !== $currentCard->id) {
                $this->detachCardFromEmployee(
                    $currentCard,
                    $employee,
                    $request->user()?->id,
                    'Carte remplacee depuis la fiche employe'
                );
            }

            $this->assignCardToEmployee(
                $targetCard,
                $employee,
                $request->user()?->id,
                $currentCard && $targetCard->id !== $currentCard->id
                    ? 'Reassignation depuis la modification employe'
                    : 'Verification assignation carte depuis la modification employe'
            );
        });

        return redirect()->route('employees.index')->with('success', 'Employe mis a jour.');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Employe supprime.');
    }

    public function toggleStatus(Employee $employee)
    {
        if ($employee->is_active) {
            $this->employeeLifecycleService->deactivateEmployee($employee, request()->user()?->id, 'Toggled from employee list');
        } else {
            $this->employeeLifecycleService->reactivateEmployee($employee, request()->user()?->id, 'Toggled from employee list');
        }

        return redirect()->route('employees.index')->with(
            'success',
            $employee->is_active ? 'Employe active.' : 'Employe desactive.'
        );
    }

    public function deactivate(Employee $employee, Request $request)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employeeLifecycleService->deactivateEmployee($employee, $request->user()?->id, $validated['reason'] ?? null);

        return back()->with('success', 'Employe desactive.');
    }

    public function reactivate(Employee $employee, Request $request)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employeeLifecycleService->reactivateEmployee($employee, $request->user()?->id, $validated['reason'] ?? null);

        return back()->with('success', 'Employe reactive.');
    }

    private function normalizeCardUid(string $uid): string
    {
        return Card::normalizeUid($uid);
    }

    private function findOrCreateAssignableCard(string $cardUid, Employee $employee, ?int $ignoreCardId = null): Card
    {
        $assignedColumn = Card::assignedEmployeeColumn();

        if ($cardUid === '') {
            throw ValidationException::withMessages([
                'card_uid' => 'Le champ UID carte NFC est obligatoire.',
            ]);
        }

        $card = Card::query()
            ->where('uid', $cardUid)
            ->first();

        if (!$card) {
            $payload = [
                'uid' => $cardUid,
                'balance' => 0,
                'is_active' => true,
                'issued_at' => now()->toDateString(),
                'employee_id' => $employee->id,
                $assignedColumn => $employee->id,
            ];

            if (Card::hasStatusColumn()) {
                $payload['status'] = Card::STATUS_ACTIVE;
            }

            return Card::query()->create($payload);
        }

        if ($ignoreCardId !== null && (int) $card->id === (int) $ignoreCardId) {
            return $card;
        }

        if ($card->{$assignedColumn} !== null && (int) $card->{$assignedColumn} !== (int) $employee->id) {
            throw ValidationException::withMessages([
                'card_uid' => 'Cette carte NFC est deja assignee a un autre employe.',
            ]);
        }

        if (!$card->is_active || (Card::hasStatusColumn() && $card->status !== Card::STATUS_ACTIVE)) {
            throw ValidationException::withMessages([
                'card_uid' => 'Cette carte existe mais son statut ne permet pas l\'assignation (inactive, bloquee, perdue ou remplacee).',
            ]);
        }

        return $card;
    }

    private function assignCardToEmployee(Card $card, Employee $employee, ?int $changedByUserId, string $reason): void
    {
        $assignedColumn = Card::assignedEmployeeColumn();
        $alreadyAssigned = (int) $card->{$assignedColumn} === (int) $employee->id;

        if (!$alreadyAssigned) {
            $card->update([
                $assignedColumn => $employee->id,
                'employee_id' => $employee->id,
            ]);
        }

        if (!$this->supportsCardAssignments()) {
            return;
        }

        $openAssignment = CardAssignment::query()
            ->where('card_id', $card->id)
            ->whereNull('unassigned_at')
            ->latest('assigned_at')
            ->first();

        if ($openAssignment && (int) $openAssignment->employee_id !== (int) $employee->id) {
            $openAssignment->update([
                'unassigned_at' => now(),
                'change_reason' => 'Reaffectation automatique depuis la fiche employe',
                'changed_by_user_id' => $changedByUserId,
            ]);

            $openAssignment = null;
        }

        if (!$openAssignment || (int) $openAssignment->employee_id !== (int) $employee->id) {
            CardAssignment::query()->create([
                'card_id' => $card->id,
                'employee_id' => $employee->id,
                'assigned_at' => now(),
                'change_reason' => $reason,
                'changed_by_user_id' => $changedByUserId,
            ]);
        }
    }

    private function detachCardFromEmployee(Card $card, Employee $employee, ?int $changedByUserId, string $reason): void
    {
        $assignedColumn = Card::assignedEmployeeColumn();

        if ((int) $card->{$assignedColumn} !== (int) $employee->id) {
            return;
        }

        if (!$this->supportsCardAssignments()) {
            $card->update([
                $assignedColumn => null,
            ]);

            return;
        }

        $openAssignment = CardAssignment::query()
            ->where('card_id', $card->id)
            ->where('employee_id', $employee->id)
            ->whereNull('unassigned_at')
            ->latest('assigned_at')
            ->first();

        if ($openAssignment) {
            $openAssignment->update([
                'unassigned_at' => now(),
                'change_reason' => $reason,
                'changed_by_user_id' => $changedByUserId,
            ]);
        }

        $card->update([
            $assignedColumn => null,
        ]);
    }

    private function generateEmployeeNo(): string
    {
        $prefix = 'EMP-' . now()->format('Ymd') . '-';
        $counter = Employee::query()
            ->where('employee_no', 'like', $prefix . '%')
            ->count() + 1;

        do {
            $candidate = $prefix . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
            $exists = Employee::query()->where('employee_no', $candidate)->exists();
            $counter++;
        } while ($exists);

        return $candidate;
    }

    private function supportsCardAssignments(): bool
    {
        return Schema::hasTable('card_assignments');
    }
}
