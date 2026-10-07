<?php

return [
    'forbidden' => 'You don’t have permission to do this.',

    'errors' => [
        'system_role' => 'System roles can’t be changed. Copy the role to customise it.',
        'module_inactive' => 'This module isn’t active for your organisation.',
        'last_owner' => 'Your organisation needs at least one active Owner.',
    ],

    // RBAC-03: system role names, stored in the tenant's default language.
    'templates' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'branch_manager' => 'Branch Manager',
        'cashier' => 'Cashier',
        'waiter' => 'Waiter',
        'storekeeper' => 'Storekeeper',
        'accountant' => 'Accountant',
        'hr_officer' => 'HR Officer',
        'payroll_officer' => 'Payroll Officer',
        'procurement_officer' => 'Procurement Officer',
        'approver' => 'Approver',
        'employee_self_service' => 'Employee self-service',
        'read_only_auditor' => 'Read-only Auditor',
    ],
];
