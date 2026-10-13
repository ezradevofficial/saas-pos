<?php

// Module Fidélité (docs/modules/loyalty.md) : messages de l’API, libellés des listes et exports, notifications.
return [
    'numbering' => [
        'statement' => 'Relevé client',
    ],

    'errors' => [
        'credit_account_exists' => 'Ce client a déjà un compte crédit ouvert dans cette société.',
        'credit_account_closed' => 'Ce compte crédit est fermé. Rouvrez-le avant d’y enregistrer quoi que ce soit.',
        'credit_balance_not_zero' => 'Le compte a encore un solde. Enregistrez un paiement ou un ajustement pour le ramener à zéro, puis fermez-le.',
        'credit_limit_exceeded' => 'Ce débit fait dépasser au client sa limite de crédit de :limit. Demandez une limite plus élevée ou enregistrez un montant plus petit.',
        'gift_card_not_inactive' => 'Cette carte a déjà été vendue ou est bloquée. Choisissez une carte qui n’a pas été vendue.',
        'gift_card_blocked' => 'Cette carte est déjà bloquée.',
        'gift_card_not_blocked' => 'Cette carte n’est pas bloquée.',
        'coupon_archived' => 'Ce coupon est déjà archivé.',
    ],

    'validation' => [
        'positive' => 'Saisissez un montant supérieur à zéro.',
        'item_other_company' => 'Choisissez un article que cette société vend.',
        'required_for_type' => 'Renseignez ce champ pour une promotion « :type ».',
        'percent' => 'Saisissez un pourcentage supérieur à 0 et au plus égal à 100, avec au plus 4 décimales.',
        'items' => 'Choisissez des articles que cette société vend.',
        'categories' => 'Choisissez des catégories que cette société utilise.',
        'branches' => 'Choisissez des agences de cette société.',
        'promotions' => 'Choisissez des promotions de cette société.',
        'codes_required' => 'Saisissez au moins un code ou indiquez combien en générer.',
        'codes_distinct' => 'Chaque code ne peut figurer qu’une fois.',
        'codes_taken' => 'Ces codes sont déjà utilisés dans cette société : :codes. Choisissez d’autres codes.',
        'customer_company' => 'Choisissez un client de cette société (ou partagé dans le groupe).',
        'statement_period' => 'Un relevé couvre au plus un an. Choisissez une période plus courte.',
    ],

    'promotion' => [
        'list_title' => 'Promotions',
        'columns' => [
            'name' => 'Nom',
            'type' => 'Type',
            'status' => 'Statut',
            'priority' => 'Priorité',
            'company' => 'Société',
            'version' => 'Version',
            'updated_at' => 'Dernière modification',
        ],
        'types' => [
            'percent' => 'Pourcentage de remise',
            'amount_off' => 'Montant de remise',
            'buy_x_get_y' => 'X achetés, Y offerts',
            'bundle' => 'Prix de lot',
        ],
        'statuses' => [
            'draft' => 'Brouillon',
            'active' => 'Active',
            'archived' => 'Archivée',
        ],
    ],

    'gift_card' => [
        'list_title' => 'Cartes cadeaux et bons',
        'columns' => [
            'card' => 'Carte',
            'kind' => 'Type',
            'status' => 'Statut',
            'initial' => 'Valeur',
            'balance' => 'Solde',
            'expires_on' => 'Expire le',
            'issued_at' => 'Émise le',
        ],
        'kinds' => [
            'gift_card' => 'Carte cadeau',
            'voucher' => 'Bon',
        ],
        'statuses' => [
            'inactive' => 'Non vendue',
            'active' => 'Active',
            'blocked' => 'Bloquée',
        ],
    ],

    'credit' => [
        'list_title' => 'Comptes crédit',
        'columns' => [
            'customer' => 'Client',
            'company' => 'Société',
            'balance' => 'Solde',
            'limit' => 'Limite de crédit',
            'status' => 'Statut',
        ],
        'statuses' => [
            'open' => 'Ouvert',
            'closed' => 'Fermé',
        ],
        'kinds' => [
            'charge' => 'Vente à crédit',
            'payment' => 'Paiement reçu',
            'reverse_charge' => 'Vente annulée',
            'refund_credit' => 'Remboursement',
            'adjust' => 'Ajustement',
        ],
    ],

    'ageing' => [
        'title' => 'Balance âgée',
        'buckets' => [
            '0_30' => '0 à 30 jours',
            '31_60' => '31 à 60 jours',
            '61_90' => '61 à 90 jours',
            '90_plus' => 'Plus de 90 jours',
        ],
    ],

    'notifications' => [
        'points_expiring' => [
            'label' => 'Points de fidélité qui expirent',
            'subject' => '{points} points de {company_name} expirent d’ici le {date}',
            'body' => "Bonjour {recipient_name},\n\n{customers} clients de {company_name} ont {points} points qui expirent d’ici le {date}. Rappelez-leur d’utiliser leurs points.",
            'sms' => '{app_name} : {points} points de fidélité expirent d’ici le {date}.',
        ],
        'credit_limit_near' => [
            'label' => 'Client proche de sa limite de crédit',
            'subject' => '{party_name} a utilisé {percent} % de sa limite de crédit',
            'body' => "Bonjour {recipient_name},\n\n{party_name} doit {balance}, soit {percent} % de sa limite de crédit de {limit}. Demandez un paiement avant la prochaine vente à crédit.",
            'sms' => '{app_name} : {party_name} a utilisé {percent} % de sa limite de crédit.',
        ],
        'credit_limit_exceeded' => [
            'label' => 'Client au-delà de sa limite de crédit',
            'subject' => '{party_name} a dépassé sa limite de crédit',
            'body' => "Bonjour {recipient_name},\n\n{party_name} doit {balance}, plus que sa limite de crédit de {limit}. Demandez un paiement ou revoyez la limite.",
            'sms' => '{app_name} : {party_name} a dépassé sa limite de crédit.',
        ],
        'gift_card_low' => [
            'label' => 'Solde de carte cadeau faible',
            'subject' => 'Il reste {balance} sur la carte cadeau {card}',
            'body' => "Bonjour {recipient_name},\n\nIl reste {balance} sur la carte cadeau {card} après sa dernière utilisation.",
            'sms' => '{app_name} : il reste {balance} sur la carte cadeau {card}.',
        ],
    ],
];
