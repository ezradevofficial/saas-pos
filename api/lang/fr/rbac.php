<?php

return [
    'forbidden' => 'Vous n’avez pas la permission d’effectuer cette action.',

    'errors' => [
        'system_role' => 'Les rôles système ne peuvent pas être modifiés. Copiez le rôle pour le personnaliser.',
        'module_inactive' => 'Ce module n’est pas activé pour votre organisation.',
        'last_owner' => 'Votre organisation doit garder au moins un propriétaire actif.',
    ],

    // RBAC-03 : noms des rôles système, enregistrés dans la langue par défaut du locataire.
    'templates' => [
        'owner' => 'Propriétaire',
        'admin' => 'Administrateur',
        'branch_manager' => 'Responsable d’agence',
        'cashier' => 'Caissier',
        'waiter' => 'Serveur',
        'storekeeper' => 'Magasinier',
        'accountant' => 'Comptable',
        'hr_officer' => 'Chargé RH',
        'payroll_officer' => 'Gestionnaire de paie',
        'procurement_officer' => 'Acheteur',
        'approver' => 'Approbateur',
        'employee_self_service' => 'Libre-service employé',
        'read_only_auditor' => 'Auditeur (lecture seule)',
    ],
];
