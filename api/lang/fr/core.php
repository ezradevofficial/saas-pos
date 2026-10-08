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

    // ADR 003 : montants saisis en unités principales (règle MoneyAmount).
    'money' => [
        'invalid' => 'Saisissez le champ :attribute sous forme de nombre, par exemple 1250.50.',
        'too_many_decimals' => 'Le champ :attribute peut avoir au plus :decimals décimales en :currency.',
        'min' => 'Le champ :attribute doit être d’au moins :currency :min.',
        'max' => 'Le champ :attribute doit être d’au plus :currency :max.',
    ],

    // CUR-01, CUR-02 : devises.
    'currency' => [
        'base_currency_locked' => 'La devise de base de cette société est verrouillée, car des montants y ont déjà été comptabilisés.',
        'too_many_reporting_currencies' => 'Une société peut avoir au plus :max devises de présentation. Retirez-en une avant d’en ajouter une autre.',
        'decimals_locked' => 'Des montants dans cette devise sont déjà enregistrés : ses décimales ne peuvent plus changer.',
        'in_use' => 'Une société utilise cette devise comme devise de base ou de présentation. Modifiez d’abord la société.',
        'base_is_reporting' => 'Cette devise fait partie des devises de présentation de la société. Retirez-la d’abord de celles-ci.',
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

    // CUR-03, CUR-06, CUR-07 : taux de change.
    'exchange_rate' => [
        'unavailable' => 'Aucun taux de change de :from vers :to. Saisissez d’abord un taux boutique.',
        'invalid_rate' => 'Saisissez le :attribute sous forme de nombre supérieur à zéro, avec au plus 10 chiffres avant la virgule et 8 après, par exemple 2850.5.',
        'same_currency' => 'Choisissez deux devises différentes.',
        'buy_above_mid' => 'Le taux d’achat ne peut pas dépasser le taux moyen.',
        'sell_below_mid' => 'Le taux de vente ne peut pas être inférieur au taux moyen.',
        'duplicate' => 'Un taux boutique pour cette paire commence déjà à cette heure. Choisissez une autre heure.',
        'tolerance_exceeded' => 'Le taux :pair a varié de :change % par rapport au taux précédent, au-delà de la tolérance de :tolerance %. Il a été enregistré ; vérifiez qu’il est correct.',
        'attributes' => [
            'base' => 'devise de base',
            'quote' => 'devise de cotation',
            'mid' => 'taux moyen',
            'buy' => 'taux d’achat',
            'sell' => 'taux de vente',
            'effective_at' => 'heure d’effet',
        ],
    ],

    // MD-03, CP-01, CP-02 : codes de taxe, taux, catégories.
    'tax' => [
        'code_archived' => 'Le code de taxe :code est archivé. Restaurez-le avant de l’utiliser ou d’y ajouter un taux.',
        'rate_missing' => 'Le code de taxe :code n’a pas de taux confirmé au :date. Saisissez son taux avant de l’utiliser.',
        'rate_overlap' => 'Un taux commence déjà à cette date ou après (dernier début : :date). Choisissez une date postérieure au :date.',
        'exempt_has_no_rate' => 'Un code de taxe exonéré n’a pas de taux.',
        'zero_rated_rate' => 'Un code de taxe à taux zéro a le taux 0.',
        'code_taken' => 'Un autre code de taxe actif de cette société utilise ce code. Choisissez un autre code.',
        'pack_missing' => 'Aucun pack pays n’est encore publié pour :country. Demandez à l’équipe de la plateforme de le publier.',
        'category_other_company' => 'Cette catégorie appartient à une autre société. Définissez les codes de taxe par défaut de sa propre société uniquement.',
        'category_company_not_allowed' => 'Vous ne pouvez pas définir les codes de taxe de cette société.',
        'category_code_invalid' => 'Choisissez un code de taxe actif de cette société.',
        'category_company_required' => 'Les catégories de taxe sont gérées par société, comme les articles. Choisissez la société de cette catégorie.',
        'category_shared_mode' => 'Les catégories de taxe sont partagées dans le groupe, comme les articles : une catégorie ne peut pas appartenir à une seule société. Retirez la société.',
        'attributes' => [
            'code' => 'code',
            'name_en' => 'nom en anglais',
            'name_fr' => 'nom en français',
            'kind' => 'type',
            'rate' => 'taux',
            'effective_from' => 'date de début',
            'fiscal_code' => 'code fiscal',
            'category_name' => 'nom',
            'company' => 'société',
            'codes' => 'codes de taxe par défaut',
            'tax_code' => 'code de taxe',
        ],
    ],

    // MD-03 : listes de prix.
    'price_list' => [
        'archived_default' => 'Une liste de prix archivée ne peut pas être la liste par défaut. Restaurez-la d’abord.',
        'attributes' => [
            'name' => 'nom',
            'currency' => 'devise',
            'tax_inclusive' => 'prix taxes comprises',
            'is_default' => 'liste de prix par défaut',
        ],
    ],

    // TEN-08 : données de référence partagées ou par société.
    'master_data' => [
        'sharing_changed' => 'Le partage de ces données a changé pendant l’enregistrement. Vérifiez la société et réessayez.',
        'records_need_company' => ':count enregistrement n’a pas de société. Choisissez la société qui le reçoit avant de gérer ces données par société.|:count enregistrements n’ont pas de société. Choisissez la société qui les reçoit avant de gérer ces données par société.',
        'confirm_shared' => 'Partager ces données rend les enregistrements de chaque société visibles dans tout le groupe. Confirmez pour continuer.',
        'attributes' => [
            'data_type' => 'type de données',
            'mode' => 'partage',
            'assign_to_company' => 'société qui reçoit les enregistrements',
            'confirm' => 'confirmation',
        ],
    ],

    // MD-01, MD-06 : tiers.
    'party' => [
        'company_required' => 'Ces données sont gérées par société. Choisissez la société à laquelle appartient cet enregistrement.',
        'company_not_allowed' => 'Ces données sont partagées dans le groupe : l’enregistrement ne peut pas appartenir à une seule société. Retirez la société.',
        'company_not_reached' => 'Vous ne pouvez pas déplacer d’enregistrements vers cette société. Choisissez une société dans laquelle vous travaillez.',
        'price_list_other_company' => 'Choisissez une liste de prix active de la société de cet enregistrement.',
        'phone_invalid' => 'Saisissez le numéro avec son indicatif pays, par exemple +243812345678.',
        'tag_invalid' => 'Les étiquettes contiennent des lettres, des chiffres, des espaces, des tirets et des tirets bas, jusqu’à 40 caractères.',
        'attributes' => [
            'company' => 'société',
            'kind' => 'type',
            'name' => 'nom',
            'legal_name' => 'raison sociale',
            'tax_id' => 'numéro fiscal',
            'phones' => 'numéros de téléphone',
            'phone' => 'numéro de téléphone',
            'emails' => 'adresses e-mail',
            'email' => 'adresse e-mail',
            'addresses' => 'adresses',
            'address_line1' => 'ligne d’adresse 1',
            'currency' => 'devise',
            'payment_terms_days' => 'conditions de paiement',
            'credit_limit' => 'limite de crédit',
            'credit_limit_currency' => 'devise de la limite de crédit',
            'price_list' => 'liste de prix',
            'tags' => 'étiquettes',
            'tag' => 'étiquette',
            'roles' => 'rôles',
            'role' => 'rôle',
        ],
    ],
];
