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
        // EXP-01 : la liste des devises de l’organisation et son export.
        'list_title' => 'Devises utilisées',
        'columns' => [
            'code' => 'Code',
            'name' => 'Devise',
            'decimals' => 'Décimales',
            'cash_rounding' => 'Arrondi espèces',
            'status' => 'Statut',
            'updated_at' => 'Modifiée le',
        ],
        'statuses' => [
            'active' => 'Activée',
            'inactive' => 'Désactivée',
        ],
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
        // EXP-01 : l’historique des taux et son export.
        'list_title' => 'Taux de change de :company',
        'columns' => [
            'pair' => 'Paire de devises',
            'effective_at' => 'En vigueur',
            'kind' => 'Type',
            'mid' => 'Taux',
            'buy' => 'Achat',
            'sell' => 'Vente',
            'direction' => 'Sens',
            'source' => 'Source',
            'created_at' => 'Saisi le',
            'from' => 'Du',
            'to' => 'Au',
        ],
        'kinds' => [
            'reference' => 'Référence',
            'shop' => 'Boutique',
        ],
        'directions' => [
            'direct' => 'Dans ce sens',
            'inverse' => 'Sens inverse',
        ],
        'sources' => [
            'manual' => 'Saisi à la main',
            'bcc' => 'Banque Centrale du Congo',
            'cbk' => 'Banque centrale du Kenya',
            'fake' => 'Flux de test',
            'feed' => 'Flux de taux',
        ],
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
            'name' => 'nom',
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
        // EXP-01 : la liste des contacts et son export.
        'list_titles' => [
            'all' => 'Contacts',
            'customer' => 'Clients',
            'supplier' => 'Fournisseurs',
            'contact' => 'Contacts',
            'employee_link' => 'Employés',
        ],
        'columns' => [
            'name' => 'Nom',
            'legal_name' => 'Raison sociale',
            'kind' => 'Type',
            'roles' => 'Rôles',
            'phones' => 'Téléphones',
            'emails' => 'Adresses e-mail',
            'tax_id' => 'Numéro fiscal',
            'tags' => 'Étiquettes',
            'currency' => 'Devise',
            'credit_limit' => 'Plafond de crédit',
            'payment_terms' => 'Conditions de paiement',
            'status' => 'Statut',
            'created_at' => 'Créé le',
            'updated_at' => 'Modifié le',
            'role' => 'Rôle',
            'tag' => 'Étiquette',
        ],
        'kinds' => [
            'person' => 'Personne',
            'organisation' => 'Organisation',
        ],
        'roles' => [
            'customer' => 'Client',
            'supplier' => 'Fournisseur',
            'contact' => 'Contact',
            'employee_link' => 'Employé',
        ],
        'payment_terms_days' => ':days jour|:days jours',
        'company_required' => 'Ces données sont gérées par société. Choisissez la société à laquelle appartient cet enregistrement.',
        'company_not_allowed' => 'Ces données sont partagées dans le groupe : l’enregistrement ne peut pas appartenir à une seule société. Retirez la société.',
        'company_not_reached' => 'Vous ne pouvez pas déplacer d’enregistrements vers cette société. Choisissez une société dans laquelle vous travaillez.',
        'company_change_needs_confirmation' => 'Ce changement de rôle déplacerait l’enregistrement entre le partage et une seule société. Indiquez la société, ou aucune pour le partager, pour confirmer.',
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

    // MD-02 : articles, catégories d’articles, unités de mesure.
    'item' => [
        // EXP-01 : la liste des articles et son export.
        'list_title' => 'Articles',
        'columns' => [
            'code' => 'Code',
            'name' => 'Nom',
            'category' => 'Catégorie',
            'type' => 'Type',
            'base_unit' => 'Unité de base',
            'barcodes' => 'Codes-barres',
            'tax_category' => 'Catégorie de taxe',
            'status' => 'Statut',
            'created_at' => 'Créé le',
            'updated_at' => 'Modifié le',
            'barcode' => 'Code-barres',
        ],
        'types' => [
            'stock' => 'Article stocké',
            'service' => 'Service',
            'non_stock' => 'Article non stocké',
            'kit' => 'Kit',
        ],
        'company_required' => 'Les articles sont tenus par société. Choisissez la société de cet enregistrement.',
        'company_not_allowed' => 'Les articles sont partagés dans le groupe : cet enregistrement ne peut pas appartenir à une seule société. Retirez la société.',
        'company_not_reached' => 'Vous ne pouvez pas déplacer d’articles vers cette société. Choisissez une société où vous travaillez.',
        'code_invalid' => 'Les codes utilisent des lettres, des chiffres, des points, des tirets, des traits de soulignement et des barres obliques, sans espace, jusqu’à 40 caractères.',
        'code_taken' => 'Un autre article actif utilise déjà le code :code. Choisissez un autre code.',
        'code_taken_race' => 'Un autre article a pris ce code pendant l’enregistrement. Choisissez un autre code.',
        'barcode_invalid' => 'Saisissez le code-barres avec des lettres et des chiffres uniquement, jusqu’à 48 caractères. Les espaces et les tirets sont retirés.',
        'barcode_repeated' => 'Ce code-barres figure deux fois. Retirez-en un.',
        'barcode_taken' => 'Un autre article actif utilise déjà le code-barres :barcode. Retirez-le ici ou de l’autre article.',
        'barcode_taken_race' => 'Un autre article a pris l’un de ces codes-barres pendant l’enregistrement. Vérifiez les codes-barres et réessayez.',
        'barcode_unit' => 'Choisissez l’unité de base ou l’une des unités de cet article pour le code-barres.',
        'barcode_unit_removed' => 'Un code-barres utilise une unité retirée. Modifiez les codes-barres dans la même requête.',
        'base_change_needs_units' => 'Les facteurs et les codes-barres par unité sont exprimés dans l’unité de base. Pour changer l’unité de base, renvoyez les unités et les codes-barres dans la même requête.',
        'uom_archived' => 'Cette unité est archivée. Restaurez-la ou choisissez une unité active.',
        'base_in_units' => 'L’unité de base est toujours incluse avec le facteur 1. Retirez-la des autres unités.',
        'factor_invalid' => 'Saisissez le nombre d’unités de base contenues dans cette unité, avec 6 décimales au plus.',
        'factor_positive' => 'Le facteur doit être supérieur à 0.',
        'sales_default_once' => 'Une seule unité peut être l’unité de vente par défaut.',
        'purchase_default_once' => 'Une seule unité peut être l’unité d’achat par défaut.',
        'category_other_scope' => 'Choisissez une catégorie active de la société de cet article, ou une catégorie partagée pour un article partagé.',
        'tax_category_other_scope' => 'Choisissez une catégorie de taxe active de la société de cet article, ou une catégorie partagée pour un article partagé.',
        'duplicate_codes' => 'Des codes ou des codes-barres sont utilisés par des articles de plusieurs sociétés. Modifiez-les pour que chacun ne soit utilisé qu’une fois, puis partagez les articles.',
        'image_limit' => 'Un article peut avoir jusqu’à :max images. Supprimez-en une avant d’en ajouter une autre.',
        'image_too_large' => 'L’image dépasse :max. Choisissez un fichier plus petit.',
        'image_type' => 'Choisissez une image JPEG, PNG ou WebP.',
        'image_unreadable' => 'Ce fichier ne peut pas être lu comme une image, ou il dépasse 8000 pixels de côté. Choisissez un autre fichier.',
        'image_not_stored' => 'L’image n’a pas pu être enregistrée. Réessayez dans un instant.',
        'image_order_invalid' => 'Indiquez chaque image de cet article une fois, dans le nouvel ordre.',
        'attributes' => [
            'company' => 'société',
            'code' => 'code',
            'name' => 'nom',
            'category' => 'catégorie',
            'type' => 'type',
            'base_uom' => 'unité de base',
            'tax_category' => 'catégorie de taxe',
            'uoms' => 'unités',
            'uom' => 'unité',
            'factor' => 'facteur',
            'barcodes' => 'codes-barres',
            'barcode' => 'code-barres',
            'image' => 'image',
            'image_ids' => 'ordre des images',
        ],
    ],

    'item_category' => [
        'parent_other_scope' => 'Choisissez une catégorie parente de la même société, ou une catégorie partagée pour une catégorie partagée.',
        'parent_cycle' => 'Une catégorie ne peut pas se trouver sous elle-même ou sous l’une de ses sous-catégories. Choisissez un autre parent.',
        'too_deep' => 'Les catégories vont jusqu’à :max niveaux. Choisissez un parent plus haut dans l’arborescence.',
        'colour_invalid' => 'Choisissez une couleur du thème.',
        'in_use' => 'Cette catégorie a des sous-catégories ou des articles actifs. Déplacez-les ou archivez-les d’abord.',
        'parent_archived' => 'La catégorie parente est archivée. Restaurez-la d’abord.',
        'attributes' => [
            'company' => 'société',
            'parent' => 'catégorie parente',
            'name' => 'nom',
            'colour' => 'couleur',
        ],
    ],

    'uom' => [
        'code_invalid' => 'Les codes utilisent des majuscules, des chiffres et des traits de soulignement, jusqu’à 10 caractères.',
        'code_taken' => 'Une autre unité active utilise déjà le code :code. Choisissez un autre code.',
        'code_taken_race' => 'Une autre unité a pris ce code pendant l’enregistrement. Choisissez un autre code.',
        'in_use' => 'Des articles actifs utilisent cette unité. Modifiez d’abord ces articles.',
        'defaults' => [
            'EA' => 'Pièce',
            'KG' => 'Kilogramme',
            'G' => 'Gramme',
            'L' => 'Litre',
            'ML' => 'Millilitre',
            'M' => 'Mètre',
            'BOX' => 'Boîte',
            'PACK' => 'Paquet',
        ],
        'attributes' => [
            'code' => 'code',
            'name' => 'nom',
            'kind' => 'type',
        ],
    ],

    // MD-04 : moyens de paiement.
    'payment_method' => [
        'type_invalid' => 'Choisissez espèces, mobile money, carte, crédit, bon, points ou virement bancaire. Le type d’un moyen de paiement existant ne peut pas changer.',
        'provider_invalid' => 'Choisissez un fournisseur de paiement pris en charge. Le fournisseur d’un moyen de paiement existant ne peut pas changer.',
        'provider_required' => 'Les moyens de paiement mobile money et carte ont besoin d’un fournisseur. Choisissez-en un.',
        'provider_not_allowed' => 'Seuls les moyens de paiement mobile money et carte ont un fournisseur. Retirez le fournisseur.',
        'provider_other_type' => 'Ce fournisseur ne gère pas ce type de paiement. Choisissez un fournisseur du même type.',
        'cash_currency_required' => 'Les moyens de paiement en espèces ont besoin d’une devise. Choisissez l’une de vos devises actives.',
        'settings_none' => 'Ce moyen de paiement n’a pas de paramètres. Retirez-les.',
        'settings_unknown' => 'Utilisez uniquement ces paramètres : :keys.',
        'secrets_none' => 'Ce moyen de paiement n’a pas d’identifiants. Retirez-les.',
        'secrets_unknown' => 'Utilisez uniquement ces identifiants : :keys.',
        'provider_not_configured' => 'Le fournisseur n’est pas encore configuré. Saisissez :keys, puis activez le moyen de paiement.',
        'order_invalid' => 'L’ordre doit lister une fois chaque moyen de paiement actif de la société. Rechargez la liste et réessayez.',
        'defaults' => [
            'cash' => 'Espèces :currency',
            'mpesa_ke' => 'M-Pesa',
            'airtel_ke' => 'Airtel Money',
            'vodacom_mpesa_cd' => 'M-Pesa Vodacom',
            'orange_money_cd' => 'Orange Money',
            'airtel_money_cd' => 'Airtel Money',
            'afrimoney_cd' => 'Afrimoney',
            'card_aggregator' => 'Carte',
        ],
        'attributes' => [
            'type' => 'type',
            'provider' => 'fournisseur',
            'name' => 'nom',
            'currency' => 'devise',
            'settings' => 'paramètres',
            'secrets' => 'identifiants',
            'active' => 'activé',
            'ids' => 'ordre',
        ],
    ],

    // MD-05 : départements, centres de coûts et projets.
    'dimension' => [
        'code_invalid' => 'Les codes commencent par une lettre ou un chiffre et utilisent des lettres, des chiffres, des points, des tirets et des traits de soulignement, jusqu’à 30 caractères.',
        'code_taken' => 'Un autre enregistrement actif de la société utilise déjà ce code. Choisissez un autre code.',
        'parent_other_company' => 'Choisissez un parent actif de la même société.',
        'parent_cycle' => 'Un enregistrement ne peut pas se trouver sous lui-même ou sous l’un de ses enfants. Choisissez un autre parent.',
        'parent_archived' => 'Le parent est archivé. Restaurez-le d’abord.',
        'owner_no_access' => 'Cette personne ne peut pas voir la société. Donnez-lui d’abord accès à la société, ou choisissez quelqu’un d’autre.',
        'in_use' => 'Cet enregistrement a des enfants actifs. Déplacez-les ou archivez-les d’abord.',
        'attributes' => [
            'code' => 'code',
            'name' => 'nom',
            'parent_id' => 'parent',
            'owner_user_id' => 'responsable',
        ],
    ],

    // EXP-01 : les listes des utilisateurs, invitations, sessions, rôles et attributions, et leurs exports.
    'user' => [
        'list_title' => 'Utilisateurs',
        'columns' => [
            'name' => 'Nom',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
            'roles' => 'Rôles',
            'status' => 'Statut',
            'two_factor' => 'Double authentification',
            'last_sign_in_at' => 'Dernière connexion',
            'created_at' => 'Créé le',
        ],
        'statuses' => [
            'active' => 'Actif',
            'pending' => 'En attente',
            'deactivated' => 'Désactivé',
        ],
    ],

    'invitation' => [
        'list_title' => 'Invitations',
        'columns' => [
            'name' => 'Nom',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
            'roles' => 'Rôles',
            'status' => 'Statut',
            'invited_by' => 'Invité par',
            'expires_at' => 'Expire le',
            'created_at' => 'Envoyée le',
        ],
        'statuses' => [
            'pending' => 'En attente',
            'accepted' => 'Acceptée',
            'revoked' => 'Révoquée',
            'expired' => 'Expirée',
        ],
    ],

    'session' => [
        'list_title' => 'Sessions',
        'columns' => [
            'device' => 'Appareil',
            'user_agent' => 'Navigateur ou application',
            'ip' => 'Adresse IP',
            'last_active' => 'Dernière activité',
            'current' => 'Cet appareil',
            'created_at' => 'Connecté le',
        ],
    ],

    'role' => [
        'list_title' => 'Rôles',
        'columns' => [
            'name' => 'Nom',
            'description' => 'Description',
            'type' => 'Type',
            'permissions' => 'Autorisations',
            'two_factor' => 'Double authentification',
            'status' => 'Statut',
        ],
        'types' => [
            'system' => 'Système',
            'custom' => 'Personnalisé',
        ],
        'two_factor_required' => 'Obligatoire',
    ],

    'assignment' => [
        'list_title' => 'Rôles de :name',
        'columns' => [
            'role' => 'Rôle',
            'scope_type' => 'Niveau',
            'scope' => 'Où',
            'granted_by' => 'Attribué par',
            'granted_at' => 'Attribué le',
        ],
        'scope_types' => [
            'tenant' => 'Toute l’organisation',
            'company' => 'Société',
            'branch' => 'Succursale',
            'location' => 'Emplacement',
        ],
        'role_at' => ':role à :scope',
    ],

    // Listes et leurs exports (EXP-01 ; plan listes et sélecteurs).
    'list' => [
        'sort_unknown' => 'Cette liste ne peut pas être triée par « :sort ». Choisissez une autre colonne.',
        'column_unknown' => 'Cette liste n’a pas de colonne « :column » à exporter. Choisissez parmi les colonnes de la liste.',
        'pdf_too_many_rows' => 'Trop de lignes pour un PDF. Affinez les filtres ou exportez vers Excel.',
        'generated_at' => 'Généré le :date',
        'page_of' => 'Page :page sur :pages',
        'sort_hidden' => 'Vous ne pouvez pas trier par un champ que vous ne voyez pas. Choisissez une autre colonne.',
        'filter_hidden' => 'Vous ne pouvez pas filtrer par un champ que vous ne voyez pas.',
        'columns_hidden' => 'Vous ne voyez aucune des colonnes demandées. Choisissez d’autres colonnes à exporter.',
        'too_many_exports' => 'Trop d’exports. Réessayez dans :seconds seconde.|Trop d’exports. Réessayez dans :seconds secondes.',
        'search' => 'Recherche',
        'status' => 'Statut',
        'yes' => 'Oui',
        'no' => 'Non',
        'statuses' => [
            'active' => 'Actif',
            'archived' => 'Archivé',
            'all' => 'Tous',
        ],
    ],
];
