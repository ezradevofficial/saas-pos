<?php

// NOT-01..NOT-06 : le service de notifications. Les textes des événements
// utilisent la syntaxe {placeholder} (NOT-03) ; ce sont des textes par
// défaut qu’un client peut remplacer par canal et par langue.
return [
    'channels' => [
        'all' => 'Tous les canaux',
        'in_app' => 'Dans l’application',
        'email' => 'E-mail',
        'push' => 'Push',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
    ],

    'digests' => [
        'immediate' => 'Immédiatement',
        'daily' => 'Résumé quotidien',
        'weekly' => 'Résumé hebdomadaire',
    ],

    'statuses' => [
        'all' => 'Tous',
        'queued' => 'En file d’attente',
        'sending' => 'Envoi en cours',
        'sent' => 'Envoyé',
        'delivered' => 'Remis',
        'failed' => 'Échec',
        'skipped' => 'Non envoyé',
        'pending_digest' => 'En attente du résumé',
        'digested' => 'Envoyé dans un résumé',
    ],

    'inbox_statuses' => [
        'active' => 'Boîte de réception',
        'unread' => 'Non lues',
        'archived' => 'Archivées',
        'all' => 'Toutes',
    ],

    'reasons' => [
        'no_email' => 'L’utilisateur n’a pas d’adresse e-mail vérifiée.',
        'no_phone' => 'L’utilisateur n’a pas de numéro de téléphone vérifié.',
        'channel_unavailable' => 'Ce canal n’est pas encore configuré.',
        'user_deactivated' => 'L’utilisateur n’est plus actif.',
    ],

    'delivery_errors' => [
        'send_failed' => 'Le serveur de messagerie ou le fournisseur a refusé ou n’a pas répondu.',
        'job_failed' => 'L’envoi s’est arrêté de façon inattendue.',
    ],

    'template_sources' => [
        'default' => 'Par défaut',
        'all' => 'Votre texte pour tous les canaux',
        'channel' => 'Votre texte pour ce canal',
    ],

    'samples' => [
        'recipient_name' => 'Amina Otieno',
    ],

    'placeholders' => [
        'recipient_name' => 'Nom du destinataire',
        'app_name' => 'Nom de l’application',
    ],

    'events' => [
        'core' => [
            'notification' => [
                'test' => [
                    'label' => 'Message de test',
                    'subject' => 'Message de test de {sender_name}',
                    'body' => "Bonjour {recipient_name},\n\n{sender_name} vous a envoyé un message de test :\n\n{message}",
                    'sms' => '{app_name} : message de test de {sender_name} : {message}',
                ],
            ],
        ],
    ],

    'digest' => [
        'subject_daily' => 'Votre résumé du jour : :count notification|Votre résumé du jour : :count notifications',
        'subject_weekly' => 'Votre résumé de la semaine : :count notification|Votre résumé de la semaine : :count notifications',
        'intro' => 'Voici ce qui s’est passé depuis votre dernier résumé.',
    ],

    'mail' => [
        'open' => 'Ouvrir',
        'footer' => 'Vous recevez cet e-mail selon vos paramètres de notification dans :app. Pour les modifier, ouvrez Paramètres, puis Notifications.',
    ],

    'errors' => [
        'subject_line_break' => 'Un objet tient sur une ligne. Supprimez les retours à la ligne.',
        'unknown_placeholders' => 'Ce texte utilise des champs que cette notification n’a pas : :placeholders. Utilisez uniquement : :allowed.',
        'unknown_event_type' => 'Il n’existe pas de type de notification « :event ». Choisissez-en un dans la liste.',
        'channel_not_offered' => '« :event » n’est pas envoyé par :channel. Choisissez un autre canal.',
        'mandatory_channel' => 'Votre organisation exige :channel pour « :event ». Ce canal ne peut pas être désactivé.',
        'mandatory_not_allowed' => '« :event » ne peut pas être rendu obligatoire.',
        'digest_not_allowed' => 'L’e-mail pour « :event » est exigé immédiatement ; il ne peut pas attendre un résumé.',
    ],

    'attributes' => [
        'event_type' => 'type de notification',
        'channel' => 'canal',
        'locale' => 'langue',
        'subject' => 'objet',
        'body' => 'texte',
        'channels' => 'canaux',
        'digest' => 'moment de l’e-mail',
        'mandatory_channels' => 'canaux obligatoires',
    ],

    'inbox' => [
        'list_title' => 'Notifications',
        'columns' => [
            'subject' => 'Objet',
            'body' => 'Message',
            'type' => 'Type',
            'read' => 'Lue',
            'created_at' => 'Reçue',
        ],
    ],

    'delivery' => [
        'list_title' => 'Envois de notifications',
        'columns' => [
            'created_at' => 'Créé',
            'user' => 'Destinataire',
            'recipient' => 'Envoyé à',
            'type' => 'Type',
            'channel' => 'Canal',
            'status' => 'Statut',
            'reason' => 'Motif',
            'attempts' => 'Tentatives',
            'error' => 'Dernière erreur',
            'sent_at' => 'Envoyé',
        ],
        'filters' => [
            'channel' => 'Canal',
        ],
    ],
];
