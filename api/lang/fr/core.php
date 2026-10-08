<?php

return [
    'defaults' => [
        'branch' => 'Succursale principale',
        'location' => 'Point de vente principal',
    ],

    'errors' => [
        'validation_failed' => 'Certains champs sont à corriger. Vérifiez-les et réessayez.',
        'unauthenticated' => 'Connectez-vous pour continuer.',
        'forbidden' => 'Vous n’avez pas la permission d’effectuer cette action.',
        'not_found' => 'Nous n’avons pas trouvé ce que vous cherchez.',
        'method_not_allowed' => 'Cette action n’est pas disponible ici.',
        'too_many_requests' => 'Trop de requêtes. Réessayez dans :seconds seconde.|Trop de requêtes. Réessayez dans :seconds secondes.',
        'http_error' => 'La requête n’a pas pu aboutir. Vérifiez-la et réessayez.',
        'server_error' => 'Une erreur s’est produite de notre côté. Réessayez dans un instant.',
    ],

    // TEN-02..TEN-06 : sociétés, succursales, emplacements.
    'organisation' => [
        'last_active' => 'Votre organisation doit garder au moins un élément actif ici. Ajoutez-en un autre avant d’archiver celui-ci.',
        'has_active_children' => 'Cet élément contient encore des éléments actifs. Archivez-les d’abord.',
        'parent_archived' => 'Cet élément est archivé. Restaurez-le avant d’y ajouter quoi que ce soit.',
        'code_taken' => 'Une succursale active de cette société utilise déjà ce code. Choisissez un autre code.',
    ],

    // TEN-05 : appareils de caisse.
    'devices' => [
        'not_pairable' => 'Cet appareil est déjà associé ou suspendu. Dissociez-le avant de l’associer à nouveau.',
        'not_suspended' => 'Cet appareil n’est pas suspendu : il n’y a rien à réactiver.',
        'invalid_pairing_code' => 'Ce code d’association n’est pas valide ou a expiré. Demandez un nouveau code et réessayez.',
    ],

    // AUTH-02, AUTH-09, L10N-01: tenant settings.
    'settings' => [
        'attributes' => [
            'password_min_length' => 'longueur minimale du mot de passe',
            'session_timeout_minutes' => 'délai d’expiration de session',
            'default_locale' => 'langue par défaut',
        ],
    ],

    // CUR-01, CUR-02 : devises.
    'currency' => [
        'base_currency_locked' => 'La devise de base de cette société est verrouillée, car des montants y ont déjà été comptabilisés.',
        'too_many_reporting_currencies' => 'Une société peut avoir au plus :max devises de présentation. Retirez-en une avant d’en ajouter une autre.',
        'decimals_locked' => 'Des montants dans cette devise sont déjà enregistrés : ses décimales ne peuvent plus changer.',
        'in_use' => 'Une société utilise cette devise comme devise de base ou de présentation. Modifiez d’abord la société.',
        'not_active' => 'Activez d’abord cette devise pour votre organisation.',
        'not_in_catalogue' => 'Choisissez une devise ISO 4217 en vigueur.',
        'attributes' => [
            'code' => 'devise',
            'decimals' => 'décimales',
            'cash_rounding_minor' => 'arrondi des espèces',
            'active' => 'active',
            'base_currency' => 'devise de base',
            'reporting_currencies' => 'devises de présentation',
            'reporting_currency' => 'devise de présentation',
        ],
    ],
];
