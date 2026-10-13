<?php

// Inscription autonome (ONB-01..ONB-08).
return [
    'business_types' => [
        'retail' => ['name' => 'Boutique', 'description' => 'Boutiques, quincailleries, électronique'],
        'supermarket' => ['name' => 'Supermarché ou supérette', 'description' => 'Codes-barres, balances, plusieurs caisses'],
        'restaurant' => ['name' => 'Restaurant ou café', 'description' => 'Tables, commandes en cuisine, additions partagées'],
        'bar' => ['name' => 'Bar ou lounge', 'description' => 'Ardoises ouvertes, tournées rapides'],
        'hotel' => ['name' => 'Hôtel ou lodge', 'description' => 'Frais de chambre, folios clients'],
        'pharmacy' => ['name' => 'Pharmacie ou agro-vétérinaire', 'description' => 'Lots, dates de péremption'],
        'wholesale' => ['name' => 'Grossiste ou distributeur', 'description' => 'Listes de prix, crédit, livraisons'],
        'van_sales' => ['name' => 'Vente itinérante', 'description' => 'Vendre et encaisser sur le terrain'],
        'salon' => ['name' => 'Salon, spa ou services', 'description' => 'Rendez-vous, commissions du personnel'],
        'fuel_station' => ['name' => 'Station-service', 'description' => 'Pompes, équipes, boutique de piste'],
        'non_pos' => ['name' => 'Pas besoin de caisse', 'description' => 'RH, paie ou comptabilité seulement'],
    ],

    'attributes' => [
        'business_type' => 'type d’activité',
        'business_name' => 'nom de l’entreprise',
        'outlets' => 'nombre de points de vente',
        'modules' => 'modules',
        'cycle' => 'cycle de facturation',
        'bundle' => 'offre groupée',
        'step' => 'étape',
        'status' => 'statut',
        'contact_name' => 'nom du contact',
    ],

    'errors' => [
        'sample_loaded' => 'Les données d’exemple sont déjà chargées. Retirez-les avant de les charger à nouveau.',
        'sample_not_loaded' => 'Il n’y a pas de données d’exemple à retirer.',
        'sample_in_use' => 'Impossible de retirer les données d’exemple : :count de vos propres enregistrements les utilisent. Modifiez d’abord ces enregistrements, puis réessayez.',
    ],

    'sample' => [
        'price_list' => 'Prix d’exemple',
        'categories' => [
            'drinks' => 'Boissons (exemple)',
            'groceries' => 'Épicerie (exemple)',
            'household' => 'Entretien (exemple)',
        ],
        'items' => [
            'water' => 'Eau en bouteille 500 ml',
            'soda' => 'Soda 300 ml',
            'juice' => 'Jus de fruits 1 L',
            'tea' => 'Thé en feuilles 250 g',
            'rice' => 'Riz 1 kg',
            'sugar' => 'Sucre 1 kg',
            'flour' => 'Farine de maïs 2 kg',
            'oil' => 'Huile de cuisine 1 L',
            'soap' => 'Savon en barre',
            'detergent' => 'Lessive en poudre 500 g',
            'tissue' => 'Papier toilette',
            'matches' => 'Allumettes',
        ],
        'customers' => [
            'customer_1' => 'Client d’exemple : Grâce',
            'customer_2' => 'Client d’exemple : Joseph',
            'customer_3' => 'Client d’exemple : Neema',
        ],
    ],

    'assisted' => [
        'setup_user' => 'Configuration par :name',
    ],
];
