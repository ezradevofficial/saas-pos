<?php

// LAY-06, LAY-07 : configuration versionnée (thèmes, mises en page, modèles).
return [
    'attributes' => [
        'key' => 'clé',
        'name' => 'nom',
        'scope_type' => 's’applique à',
        'scope_id' => 'lieu, rôle ou utilisateur',
        'payload' => 'configuration',
        'version' => 'version',
        'from' => 'copier depuis',
        'company' => 'société',
        'branch' => 'succursale',
        'location' => 'emplacement',
    ],

    'errors' => [
        'config_invalid' => 'Cette configuration comporte des problèmes. Corrigez les éléments listés, puis publiez à nouveau.',
        'config_changed' => 'Quelqu’un a modifié ce brouillon depuis que vous l’avez ouvert. Rechargez pour voir ses modifications, puis modifiez ou publiez à nouveau.',
        'config_draft_exists' => 'Ce lieu a déjà un brouillon. Remplacez-le pour copier, ou ouvrez d’abord ce brouillon.',
        'config_busy' => 'Quelqu’un d’autre a modifié cette configuration en même temps. Réessayez.',
        'no_draft' => 'Il n’y a aucun brouillon à utiliser. Faites une modification pour en commencer un.',
        'nothing_published' => 'Rien n’est encore publié, il n’y a donc rien à copier. Publiez d’abord, ou copiez le brouillon.',
        'version_not_found' => 'Cette version n’a jamais été publiée pour cette configuration. Choisissez-en une dans l’historique.',
        'version_is_live' => 'Cette version est déjà en vigueur. Choisissez une version antérieure à restaurer.',
        'same_scope' => 'Cette configuration s’applique déjà à cet endroit. Choisissez une autre société, succursale ou un autre emplacement.',
        'unknown_key' => 'Cette configuration n’a pas cette clé. Utilisez une clé proposée par l’éditeur.',
        'payload_too_large' => 'Cette configuration dépasse :kb Ko. Retirez des éléments, puis enregistrez à nouveau.',
        'place_out_of_scope' => 'Vous ne travaillez pas à cet endroit. Choisissez une société, une succursale ou un emplacement auquel vous êtes affecté.',
    ],

    // Problèmes qui empêchent de publier un brouillon (PayloadSchema).
    'problems' => [
        'root' => 'la configuration',
        'not_null' => ':path ne peut pas être vide.',
        'type' => ':path doit être de type :type.',
        'enum' => ':path doit être l’une des valeurs : :values.',
        'required' => ':path est manquant.',
        'unknown' => ':path n’est pas un paramètre connu. Retirez-le.',
        'min_items' => ':path doit contenir au moins :min éléments.',
        'max_items' => ':path peut contenir au plus :max éléments.',
        'duplicate' => ':path répète « :value ». Chaque valeur ne peut apparaître qu’une fois.',
        'min_length' => ':path doit contenir au moins :min caractères.',
        'max_length' => ':path peut contenir au plus :max caractères.',
        'pattern' => ':path n’est pas au format attendu.',
        'min' => ':path doit être au moins :min.',
        'max' => ':path doit être au plus :max.',
        // TPL-01..TPL-03 : modèles de documents.
        'unknown_block' => ':path n’est pas un bloc connu. Supprimez-le.',
        'fiscal_required' => 'Le bloc de l’administration fiscale est obligatoire sur ce document dans votre pays. Rajoutez-le depuis la palette.',
        'totals_required' => 'Le bloc des totaux, avec ses lignes de taxe, est obligatoire sur ce document dans votre pays. Rajoutez-le depuis la palette.',
        'fiscal_twice' => 'Le bloc de l’administration fiscale ne peut figurer qu’une fois. Supprimez le doublon.',
        'fiscal_not_allowed' => 'Ce document ne porte pas de données fiscales. Supprimez le bloc de l’administration fiscale.',
        'tax_lines_locked' => 'Les lignes de taxe sont toujours imprimées avec les totaux. Réactivez-les.',
        'unknown_field' => ':path utilise « :field », que ce document n’a pas. Choisissez un champ dans la liste.',
        'unknown_column' => ':path utilise « :field », qui n’est pas une colonne de ce document. Choisissez une colonne dans la liste.',
        'row_nested' => ':path place une ligne dans une ligne. Sortez-en les blocs.',
        'row_on_thermal' => 'Les lignes à deux colonnes ne conviennent qu’aux formats A4 et A5. Sortez les blocs de la ligne, ou choisissez A4 ou A5.',
        // BR-02, BR-03 : thèmes.
        'not_overridable' => ':path ne peut pas être modifié par un thème. Un thème ne règle que les logos, les couleurs principale et d’accent, la barre latérale, les coins et la police.',
        'asset_missing' => ':path désigne une image qui ne fait pas partie de vos images de ce type. Importez-la de nouveau.',
        // LAY-05 : dispositions de caisse.
        'pos_layout_button' => ':path est un bouton d’article ou de catégorie avec une action, ou une action avec un article ou une catégorie. Donnez-lui l’un ou l’autre.',
        'pos_layout_unknown_item' => ':path désigne un article qui n’existe pas ou qui est archivé. Retirez-le ou choisissez un autre article.',
        'pos_layout_unknown_category' => ':path désigne une catégorie qui n’existe pas ou qui est archivée. Retirez-la ou choisissez une autre catégorie.',
        'pos_layout_unknown_image' => ':path désigne une image qui ne fait partie ni de vos images d’articles ni de vos images de marque. Choisissez une autre image.',
        'contrast' => 'En mode :mode, :pair a un contraste de :ratio:1 et doit atteindre au moins :required:1. Choisissez une couleur plus foncée ou plus claire.',
    ],

    // BR-03 : les mots d’un problème de contraste.
    'theme' => [
        'modes' => ['light' => 'clair', 'dark' => 'sombre'],
        'pairs' => [
            'primary_text_page' => 'le texte principal sur la page',
            'primary_text_card' => 'le texte principal sur les cartes',
            'on_primary' => 'le texte des boutons principaux',
            'primary_tint' => 'le texte sur la teinte principale',
            'on_accent' => 'le texte du bouton décisif',
            'accent_fill' => 'le bouton décisif sur les cartes',
            'sidebar_text' => 'le texte de la barre latérale',
            'sidebar_active' => 'l’élément sélectionné de la barre latérale',
        ],
        // LAY-01, LAY-04 : éditeurs de mise en page.
        'off_grid' => ':path dépasse les :columns colonnes de la grille. Réduisez sa largeur ou déplacez-le vers la gauche.',
        'overlap' => ':path chevauche le widget « :value ». Déplacez l’un des deux.',
        'unknown_source' => ':path utilise la source de données « :value », qui n’est pas disponible. Choisissez une autre source.',
        'source_widget' => ':path : cette source de données ne peut pas alimenter un widget « :value ». Choisissez un autre type de widget ou une autre source.',
        'filter_value' => ':path n’est pas un filtre valide. Retirez-le, puis enregistrez à nouveau la vue.',
        'unknown_view' => ':path désigne la vue « :value », qui n’est pas dans cette liste. Choisissez l’une des vues enregistrées.',
    ],
];
