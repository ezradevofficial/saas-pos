<?php

// EXP-02 : exports complets des données de l'organisation.
return [
    'errors' => [
        'in_progress' => 'Un export est déjà en préparation. Attendez qu’il se termine, puis réessayez.',
        'limit' => 'Vous pouvez demander :count exports par 24 heures. Réessayez plus tard ou téléchargez un export récent.',
    ],

    'notifications' => [
        'ready' => [
            'label' => 'Export des données prêt',
            'subject' => 'Votre export des données est prêt',
            'body' => "Bonjour {recipient_name},\n\nL’export complet des données de votre organisation est prêt ({size}). Téléchargez-le depuis Paramètres, Export des données, avant le {expires_at}. Il sera ensuite supprimé.\n\nVous seul pouvez le télécharger.",
        ],
    ],
];
