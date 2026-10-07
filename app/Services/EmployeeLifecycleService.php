<?php

namespace App\Services;

use App\Models\Employee;

class EmployeeLifecycleService
{
    public function __construct(private readonly AdminActionLogService $auditLogService)
    {
    }

    public function deactivateEmployee(Employee $employee, ?int $performedByUserId, ?string $reason = null): void
    {
        if (!$employee->is_active) {
            return;
        }

        $employee->update([
            'is_active' => false,
        ]);

        $this->auditLogService->log(
            'employee.deactivated',
            'employee',
            $employee->id,
            $performedByUserId,
            $reason ?: 'Deactivated by admin',
            [
                'employee_no' => $employee->employee_no,
                'name' => trim($employee->first_name . ' ' . $employee->last_name),
            ]
        );
    }

    public function reactivateEmployee(Employee $employee, ?int $performedByUserId, ?string $reason = null): void
    {
        if ($employee->is_active) {
            return;
        }

        $employee->update([
            'is_active' => true,
        ]);

        $this->auditLogService->log(
            'employee.reactivated',
            'employee',
            $employee->id,
            $performedByUserId,
            $reason ?: 'Reactivated by admin',
            [
                'employee_no' => $employee->employee_no,
                'name' => trim($employee->first_name . ' ' . $employee->last_name),
            ]
        );
    }
}
