<?php

// MKT-04 (ADR 024) : l’API publique du coursier (livraisons e-commerce).
return [
    'errors' => [
        'delivery_not_assignable' => 'Cette livraison ne peut pas être réservée maintenant : un autre livreur ou coursier l’a prise, elle est terminée, ou sa commande n’est pas prête. Actualisez votre liste de commandes.',
        'reference_reused' => 'Cette référence a déjà été utilisée avec d’autres détails. Envoyez une nouvelle référence pour une nouvelle réservation ou mise à jour.',
        'courier_stopped' => 'Cette organisation a arrêté les réservations de ce coursier pour cette société. Demandez-lui de le rétablir.',
        'delivery_unknown' => 'Cette livraison ne vous est pas confiée. Actualisez votre liste de commandes.',
        'delivery_status_invalid' => 'Cette livraison a avancé et ne peut pas recevoir cette mise à jour. Consultez à nouveau la commande pour voir son état.',
        'status_invalid' => 'Cette commande a avancé et ne peut pas recevoir cette mise à jour. Consultez à nouveau la commande pour voir son état.',
        'code_locked' => 'Trop de codes de livraison erronés. Demandez au magasin de confirmer cette livraison.',
        'code_invalid' => 'Le code de livraison n’est pas correct. Demandez au client le code reçu dans son message et renvoyez-le.',
        'proof_required' => 'Envoyez le code de livraison à 6 chiffres du client pour marquer la livraison comme livrée.',
        'payload_mismatch' => 'Cette référence a déjà été utilisée avec d’autres détails. Envoyez une nouvelle référence pour une nouvelle mise à jour.',
        'not_a_delivery' => 'Cette commande est retirée au magasin, pas livrée.',
        'rider_required' => 'Réservez la livraison avant de la déclarer prise en charge.',
    ],
];
