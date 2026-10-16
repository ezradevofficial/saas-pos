<?php

// Équipe de la plateforme (ADR 013).
return [
    'user_exists' => 'Un compte plateforme existe déjà avec cet e-mail.',

    'auth' => [
        'invalid_credentials' => 'Cet e-mail et ce mot de passe ne correspondent à aucun compte plateforme. Vérifiez-les et réessayez.',
        'deactivated' => 'Ce compte plateforme est désactivé. Demandez à un propriétaire de la plateforme de le réactiver.',
        'challenge_expired' => 'Cette connexion a expiré. Reconnectez-vous avec votre e-mail et votre mot de passe.',
        'invalid_code' => 'Ce code n’est pas valide. Saisissez le code à 6 chiffres affiché dans votre application d’authentification.',
        'setup_link_invalid' => 'Ce lien de configuration a expiré ou a déjà été utilisé. Demandez-en un nouveau à un propriétaire de la plateforme.',
    ],
    // ADM-07 : comptes du personnel.
    'staff' => [
        'cannot_deactivate_self' => 'Vous ne pouvez pas désactiver votre propre compte. Demandez-le à un autre propriétaire de la plateforme.',
        'last_owner' => 'La plateforme doit garder au moins un propriétaire actif. Nommez d’abord un autre propriétaire.',
    ],
    // ADM-01 : gestion des locataires.
    'tenants' => [
        'already_suspended' => 'Ce compte est déjà suspendu.',
        'not_suspended' => 'Ce compte n’est pas suspendu : il n’y a rien à réactiver.',
        'suspended' => 'Ce compte est suspendu par la plateforme : les modifications ne peuvent pas être enregistrées. Le propriétaire peut contacter le support pour le réactiver.',
    ],
    // ADM-01c : messages aux propriétaires du compte (messages de compte de la plateforme).
    'notifications' => [
        'tenant_suspended' => [
            'label' => 'Compte suspendu',
            'subject' => 'Votre compte est suspendu',
            'body' => "Bonjour {recipient_name},\n\nNotre équipe a suspendu votre compte {app_name}. Motif : {reason}\n\nVous pouvez toujours vous connecter, consulter vos données, les exporter et payer vos factures. Contactez le support pour réactiver le compte.",
        ],
        'tenant_reactivated' => [
            'label' => 'Compte réactivé',
            'subject' => 'Votre compte est de nouveau actif',
            'body' => "Bonjour {recipient_name},\n\nNotre équipe a réactivé votre compte {app_name}. Motif : {reason}\n\nTout fonctionne comme avant.",
        ],
        'trial_extended' => [
            'label' => 'Essai prolongé',
            'subject' => 'Votre essai se termine désormais le {date}',
            'body' => "Bonjour {recipient_name},\n\nNotre équipe a prolongé votre essai de {app_name} jusqu’au {date}. Motif : {reason}",
        ],
    ],
];
