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
    // RBAC-13 : accès du support avec consentement.
    'support' => [
        'request_open' => 'Vous avez déjà une demande ou une session active pour ce compte. Utilisez-la ou terminez-la d’abord.',
        'not_pending' => 'Cette demande a déjà été tranchée.',
        'not_granted' => 'Le compte n’a pas accordé cet accès, ou il a pris fin. Demandez-le de nouveau si vous en avez encore besoin.',
        'not_active' => 'Cet accès n’est plus actif.',
        'entry_code_invalid' => 'Ce lien de session de support a expiré ou a déjà été utilisé. Ouvrez de nouveau la session depuis la console.',
        'ended' => 'Cette session de support est terminée. Demandez de nouveau l’accès au compte si vous en avez encore besoin.',
        'audit_unavailable' => 'Cette modification ne peut pas être enregistrée dans le journal d’audit pour l’instant : elle n’a pas été faite. Réessayez dans un moment.',
        'route_refused' => 'Une session de support ne peut pas utiliser cette page. Les personnes du compte la gèrent elles-mêmes.',
        'read_only' => 'Cette session de support est en lecture seule : rien ne peut être modifié. Demandez au compte un accès en écriture si nécessaire.',
        'principal_name' => ':name (assistance)',
        'modes' => [
            'read' => 'en lecture seule',
            'write' => 'en lecture et écriture',
        ],
        'end_reasons' => [
            'revoked' => 'révoqué par votre équipe',
            'expired' => 'sa durée est écoulée',
            'ended_by_staff' => 'terminé par notre équipe de support',
            'ended_in_session' => 'terminé par notre équipe de support',
            'cancelled' => 'annulé par notre équipe de support',
            'ended' => 'terminé',
            'staff_changed' => 'le compte du membre de l’équipe a changé',
        ],
        'notifications' => [
            'requested' => [
                'label' => 'Accès du support demandé',
                'subject' => '{staff_name} demande un accès {mode} à votre compte',
                'body' => "Bonjour {recipient_name},\n\n{staff_name}, de l’équipe de support de {app_name}, demande un accès {mode} à votre compte pendant {hours} heures. Motif : {reason}\n\nApprouvez ou refusez dans Paramètres, Sécurité, Accès du support. Rien n’est ouvert avant votre approbation.",
            ],
            'started' => [
                'label' => 'Session de support ouverte',
                'subject' => '{staff_name} a ouvert une session de support',
                'body' => "Bonjour {recipient_name},\n\n{staff_name}, de l’équipe de support de {app_name}, a ouvert une session de support {mode} dans votre compte. Elle se termine au plus tard le {until}. Vous pouvez la révoquer à tout moment dans Paramètres, Sécurité, Accès du support.",
            ],
            'ended' => [
                'label' => 'Accès du support terminé',
                'subject' => 'L’accès du support de {staff_name} est terminé',
                'body' => "Bonjour {recipient_name},\n\nL’accès du support de {staff_name} est terminé : {how}. Tout ce que la session a fait figure dans Paramètres, Sécurité, Accès du support.",
            ],
        ],
    ],
];
