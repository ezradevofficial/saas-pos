<?php

// EXP-02 : exports complets des données de l'organisation.
return [
    'errors' => [
        'in_progress' => 'Un export est déjà en préparation. Attendez qu’il se termine, puis réessayez.',
        'limit' => 'Vous pouvez demander :count exports par 24 heures. Réessayez plus tard ou téléchargez un export récent.',
        'not_downloadable' => 'Cet export ne peut pas être téléchargé. Seul le propriétaire qui l’a demandé le peut, jusqu’à son expiration.',
        'closing_unavailable' => 'Ce compte d’entreprise n’a pas d’export de fermeture prêt à envoyer. Il est envoyé une fois le compte fermé et son export préparé.',
    ],

    'notifications' => [
        'ready' => [
            'label' => 'Export des données prêt',
            'subject' => 'Votre export des données est prêt',
            'body' => "Bonjour {recipient_name},\n\nL’export complet des données de votre organisation est prêt ({size}). Téléchargez-le depuis Paramètres, Export des données, avant le {expires_at}. Il sera ensuite supprimé.\n\nVous seul pouvez le télécharger.",
        ],
    ],

    // NFR-08 : l’export d’un compte d’entreprise fermé, par un lien signé envoyé à ses propriétaires.
    'closing' => [
        'mail' => [
            'subject' => 'Téléchargez les données de :name',
            'body' => 'Le compte d’entreprise :name est fermé : plus personne ne peut s’y connecter. Nous avons préparé pour vous un export complet de ses données (:size).',
            'action' => 'Télécharger l’export',
            'expiry' => 'Le lien fonctionne jusqu’au :date. Ensuite, il ne fonctionne plus et l’export est supprimé.',
            'private' => 'Ce lien est personnel et fonctionne sans connexion. Ne transférez pas cet e-mail.',
        ],
        'page' => [
            'invalid' => [
                'title' => 'Ce lien n’est pas valide',
                'body' => 'Le lien de téléchargement est incomplet ou a été modifié. Ouvrez-le à nouveau depuis l’e-mail, ou demandez à l’assistance de vous le renvoyer.',
            ],
            'expired' => [
                'title' => 'Ce lien a expiré',
                'body' => 'L’export de ce compte d’entreprise fermé n’est plus disponible. Contactez l’assistance si vous avez encore besoin des données.',
            ],
            'unavailable' => [
                'title' => 'Cet export n’est pas disponible',
                'body' => 'Aucun export ne peut être téléchargé avec ce lien. Contactez l’assistance si vous avez besoin des données d’un compte d’entreprise fermé.',
            ],
        ],
    ],
];
