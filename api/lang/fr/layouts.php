<?php

// LAY-01 : sources de données des tableaux de bord, telles que l’éditeur les liste.
return [
    'sources' => [
        'approvals_waiting' => 'Approbations qui m’attendent',
        'approvals_mine' => 'Mes approbations',
        'workflows_overdue' => 'Documents en retard dans les circuits',
        'shortcuts' => 'Raccourcis',
        'pos_sales_today' => 'Ventes du jour',
        'pos_sales_by_day' => 'Ventes par jour',
    ],
    // LAY-03 : formulaires dont les clients conçoivent la mise en page.
    'forms' => [
        'sections' => ['custom' => 'Plus de détails'],
        'item' => [
            'label' => 'Article',
            'sections' => ['details' => 'Détails', 'units' => 'Unités', 'barcodes' => 'Codes-barres'],
            'fields' => [
                'company_id' => 'Société', 'code' => 'Code', 'type' => 'Type', 'name' => 'Nom',
                'category_id' => 'Catégorie', 'tax_category_id' => 'Catégorie de taxe', 'units' => 'Unités de mesure', 'barcodes' => 'Codes-barres',
            ],
        ],
        'party' => [
            'label' => 'Client ou fournisseur',
            'sections' => ['details' => 'Détails', 'contact' => 'Contact', 'addresses' => 'Adresses', 'terms' => 'Conditions'],
            'fields' => [
                'kind' => 'Nature', 'name' => 'Nom', 'legal_name' => 'Raison sociale', 'tax_id' => 'Numéro fiscal', 'roles' => 'Rôles',
                'company_id' => 'Société', 'phones' => 'Téléphones', 'emails' => 'E-mails', 'addresses' => 'Adresses',
                'currency' => 'Devise', 'payment_terms_days' => 'Délai de paiement', 'credit_limit' => 'Plafond de crédit',
                'price_list_id' => 'Liste de prix', 'tags' => 'Étiquettes',
            ],
        ],
        'custom_form' => ['lines' => 'Lignes', 'attachments' => 'Pièces jointes', 'details' => 'Détails'],
    ],
];
