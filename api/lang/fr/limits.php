<?php

// LIM-01..LIM-04 : limites d'utilisation.
return [
    'items' => [
        'outlet' => 'points de vente',
        'device' => 'appareils de caisse',
        'user' => 'utilisateurs',
        'employee' => 'employés',
    ],

    'cost' => [
        'monthly' => ':amount par mois',
        'unpriced' => 'au tarif des options',
    ],

    'errors' => [
        'blocked_ask_owner' => 'La limite de votre abonnement pour les :item est atteinte. Demandez à un propriétaire de mettre à niveau l’abonnement pour en ajouter.',
        'blocked' => 'Votre abonnement comprend :allowed :item et vous en avez :count. Passez à un abonnement supérieur pour en ajouter. Tout ce qui est déjà en place continue de fonctionner.',
    ],

    'notifications' => [
        'exceeded' => [
            'label' => 'Utilisation au-delà de l’abonnement',
            'subject' => 'Vous avez plus de {item} que votre abonnement n’en comprend',
            'body' => "Bonjour {recipient_name},\n\nVotre organisation compte {count} {item}. Votre abonnement en comprend {allowed}. Les {over} en plus coûtent {extra_cost}, facturés sur votre prochaine facture.\n\nPassez à un abonnement supérieur avant le {blocks_on} pour continuer à ajouter des {item}. Ensuite, l’ajout est suspendu jusqu’à la mise à niveau. Tout ce qui est déjà en place continue de fonctionner, et les ventes, la paie et les approbations ne sont jamais bloquées.",
        ],
        'reminder' => [
            'label' => 'Utilisation au-delà de l’abonnement : rappel quotidien',
            'subject' => 'Plus que {days_left} jours pour mettre à niveau vos {item}',
            'body' => "Bonjour {recipient_name},\n\nVotre organisation compte toujours {count} {item}, et votre abonnement en comprend {allowed}. Les {over} en plus coûtent {extra_cost}, facturés sur votre prochaine facture.\n\nPassez à un abonnement supérieur avant le {blocks_on} pour continuer à ajouter des {item}. Tout ce qui est déjà en place continue de fonctionner.",
        ],
        'blocked' => [
            'label' => 'Utilisation au-delà de l’abonnement : ajout suspendu',
            'subject' => 'L’ajout de {item} est suspendu jusqu’à la mise à niveau',
            'body' => "Bonjour {recipient_name},\n\nVotre organisation compte {count} {item}, et votre abonnement en comprend {allowed}. Vous ne pouvez plus ajouter de {item} tant que vous n’avez pas mis à niveau votre abonnement. Tout ce qui est déjà en place continue de fonctionner, et les ventes, la paie et les approbations ne sont jamais bloquées.\n\nLes {over} en plus coûtent {extra_cost}, facturés sur votre prochaine facture.",
        ],
    ],
];
