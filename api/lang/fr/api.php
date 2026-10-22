<?php

// API-01..API-05 : l’API publique (docs/adr/020-public-api.md).
return [
    'errors' => [
        'sandbox_limit_reached' => 'Ce bac à sable développeur a déjà ses :max :what. Archivez ou réinitialisez ses données pour faire de la place.',
        'sandbox_daily_limit' => 'Ce bac à sable développeur a utilisé ses :max requêtes d’API pour aujourd’hui. Réessayez demain.',
        'route_refused' => 'Les clés d’API ne peuvent pas être utilisées ici. Utilisez ce point d’accès depuis l’application, ou consultez le portail développeurs pour savoir ce que les clés peuvent atteindre.',
        'scope_missing' => 'Cette clé d’API n’a pas la portée :scope. Ajoutez la portée à la clé ou utilisez une autre clé.',
        'scope_missing_app' => 'Cette application n’a pas reçu la portée :scope. Demandez-la dans une nouvelle version de l’application ; l’organisation y consent ensuite.',
        'idempotency_key_invalid' => 'L’en-tête Idempotency-Key doit contenir de 1 à 255 lettres, chiffres, tirets, traits de soulignement, points ou deux-points.',
        'idempotency_mismatch' => 'Cette Idempotency-Key a déjà servi pour une autre requête. Utilisez une nouvelle clé pour une nouvelle requête.',
        'idempotency_in_progress' => 'Une requête avec cette Idempotency-Key est encore en cours. Attendez qu’elle se termine, puis renvoyez-la.',
        'owner_role' => 'Une clé d’API ne peut pas avoir le rôle Propriétaire. Choisissez un autre rôle.',
        'key_limit' => 'Vous avez déjà :max clés d’API actives. Révoquez celle que vous n’utilisez plus, puis réessayez.',
        'key_revoked' => 'Cette clé d’API est révoquée. Créez une nouvelle clé.',
        'key_expired' => 'Cette clé d’API a expiré. Créez une nouvelle clé.',
        'not_sandbox' => 'Seul un bac à sable développeur peut être réinitialisé.',
        'sandbox_not_billed' => 'Un bac à sable développeur n’a pas d’abonnement. Tous les modules sont déjà activés.',
    ],
    'sandbox' => [
        'caps' => [
            'user' => 'utilisateurs',
            'device' => 'appareils',
            'outlet' => 'points de vente',
            'records' => 'nouvelles fiches',
        ],
        'closing' => [
            'subject' => 'Votre bac à sable développeur :name va bientôt fermer',
            'body' => 'Votre bac à sable développeur :name n’a pas été utilisé depuis un moment. Il ferme le :date.',
            'keep' => 'Pour le garder, connectez-vous ou envoyez une requête d’API avec l’une de ses clés avant cette date.',
        ],
    ],
];
