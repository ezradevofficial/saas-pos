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
        'too_many_requests' => 'Trop de requêtes. Réessayez dans :seconds secondes.',
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
        'invalid_pairing_code' => 'Ce code d’association n’est pas valide ou a expiré. Demandez un nouveau code et réessayez.',
    ],
];
