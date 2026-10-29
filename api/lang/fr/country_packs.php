<?php

// Libellés des packs pays (CP-01), par code de pack et code de taxe.
return [
    'KE' => [
        'name' => 'Kenya',
        'VAT_STD' => 'TVA, taux normal',
        'VAT_ZERO' => 'TVA, taux zéro',
        'VAT_EXEMPT' => 'Exonéré de TVA',
        'categories' => [
            'standard' => 'Taux normal',
            'zero_rated' => 'Taux zéro',
            'exempt' => 'Exonéré',
        ],
        'VAT_WHT' => 'Retenue de TVA à la source',
    ],
    'CD' => [
        'name' => 'République démocratique du Congo',
        'VAT_STD' => 'TVA, taux normal',
        'VAT_ZERO' => 'TVA, taux zéro',
        'VAT_EXEMPT' => 'Exonéré de TVA',
        'categories' => [
            'standard' => 'Taux normal',
            'zero_rated' => 'Taux zéro',
            'exempt' => 'Exonéré',
        ],
    ],
    // ADM-04 (ADR 022) : versions de packs dans la console de la plateforme.
    'releases' => [
        'wrong_country' => 'Le document concerne :code et non :country. Chargez-le pour son propre pays.',
        'unknown_kind' => 'Ce type de pack (:kind) est inconnu.',
        'schema' => 'Le document n’a pas la forme de ce pack : :reason',
        'overlap' => 'Deux périodes de :key dans :path se chevauchent. Clôturez l’une avant le début de la suivante.',
        'missing_value' => ':path est marqué comme confirmé mais n’a pas de valeur pour :fields. Saisissez les chiffres ou marquez-le comme à confirmer.',
        'needs_confirmation' => ':path doit encore être confirmé ; ce qui l’utilise reste bloqué jusqu’à sa confirmation.',
        'pay_needs_confirmation' => ':path : le taux de rémunération n’est pas confirmé ; les paies comprenant ce congé restent bloquées jusqu’à sa confirmation.',
        'low_confidence' => ':path : recherché, confiance faible : non confirmé par un comptable. La valeur est utilisée ; approuver cette publication la met en vigueur.',
        'status_change' => ':path : :from → :to. Approuver cette publication certifie ce changement.',
        'missing_key' => ':key dans :path omet :fields. Chaque ligne de ce pack en a besoin ; utilisez null pour un chiffre encore à confirmer.',
        'supersede_notes' => 'Rétablit le contenu de la version :version.',
        'not_published' => 'Seule une version publiée peut être remplacée.',
        'nothing_before' => 'Il n’y a rien avant cette version à rétablir.',
        'content_shape' => 'Le document doit être un objet JSON de 512 Ko au plus.',
        'not_draft' => 'Seule une version en brouillon peut être approuvée.',
        'invalid' => 'Cette version comporte des erreurs de validation. Corrigez-les et chargez-la de nouveau.',
        'own_load' => 'Vous avez chargé cette version : une autre personne doit l’approuver.',
        'decided' => 'Cette version a déjà été publiée ou refusée.',
        'not_approved' => 'Seule une version approuvée peut être publiée.',
        'own_approval' => 'Vous avez approuvé cette version : une autre personne doit la publier.',
    ],
    'statuses' => [
        'confirmed' => 'confirmé',
        'researched' => 'recherché',
        'needs_confirmation' => 'à confirmer',
    ],
];
