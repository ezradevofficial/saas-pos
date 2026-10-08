<?php

return [
    'forbidden' => 'You don’t have permission to do this.',

    'errors' => [
        'system_role' => 'System roles can’t be changed. Copy the role to customise it.',
        'module_inactive' => 'This module isn’t active for your organisation.',
        'last_owner' => 'Your organisation needs at least one active Owner.',
        'cannot_grant' => 'You can only give roles whose permissions you hold, where you manage access. Only an Owner can give or remove the Owner role.',
        'already_assigned' => 'This user already has this role here.',
    ],

    // RBAC-01: permission catalogue labels (GET permissions).
    'catalogue' => [
        'modules' => [
            'core' => 'Core',
        ],
        'resources' => [
            'core' => [
                'company' => 'Companies',
                'branch' => 'Branches',
                'location' => 'Locations',
                'device' => 'POS devices',
                'user' => 'Users',
                'role' => 'Roles',
                'audit' => 'Audit log',
                'settings' => 'Settings',
                'access_review' => 'Access review',
                'currency' => 'Currencies',
                'exchange_rate' => 'Exchange rates',
            ],
        ],
        'actions' => [
            'view' => 'View',
            'create' => 'Create',
            'edit' => 'Edit',
            'archive' => 'Archive',
            'pair' => 'Pair',
            'invite' => 'Invite',
            'deactivate' => 'Deactivate',
            'assign' => 'Assign',
            'export' => 'Export',
            'override' => 'Override',
        ],
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
