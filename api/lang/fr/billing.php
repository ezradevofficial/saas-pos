<?php

// Catalogue des offres et prix (SUB-01, ONB-03).
return [
    'catalogue' => [
        'unknown_country' => 'Choisissez un pays où nous vendons : le Kenya ou la RD Congo.',
        'makeup_fixed' => 'Ce point est figé tant que l’élément a un prix. Ajoutez plutôt un nouveau module ou une nouvelle offre avec son propre prix.',
        'unknown_module' => 'Choisissez un module du catalogue.',
    ],

    'quote' => [
        'nothing_selected' => 'Choisissez au moins un module ou une offre groupée.',
        'unknown_module' => 'Choisissez des modules du catalogue. « :name » n’en fait pas partie.',
        'unknown_bundle' => 'Choisissez une offre groupée du catalogue. « :name » n’en fait pas partie.',
        'unknown_cycle' => 'Choisissez une facturation mensuelle ou annuelle.',
        'not_sold_here' => ':name n’est pas encore vendu dans ce pays. Retirez-le ou contactez l’équipe commerciale.',
        'bundle_outlet_limit' => ':name ne couvre qu’un seul point de vente. Choisissez une offre qui en permet plus, ou choisissez les modules un par un.',
        'addon_requires_bundle' => ':name n’est vendu qu’avec une offre groupée. Choisissez une offre qui le propose.',
        'addon_not_available' => ':name n’est pas proposé avec cette offre. Retirez-le ou choisissez une autre offre.',
        'amount_too_large' => 'Ces quantités sont trop élevées pour être chiffrées. Réduisez-les ou contactez l’équipe commerciale.',
        'requires_module' => ':name a besoin du module qu’il complète. Ajoutez aussi ce module.',
    ],

    'coupon' => [
        'invalid' => 'Ce code promo n’est pas valide ou a expiré. Vérifiez le code ou retirez-le.',
    ],

    'price' => [
        'exists' => 'Un prix à partir de cette date existe déjà. Choisissez une autre date.',
        'in_effect' => 'Ce prix est déjà en vigueur et ne peut pas être retiré. Ajoutez plutôt un nouveau prix à partir d’une date ultérieure.',
    ],
];
