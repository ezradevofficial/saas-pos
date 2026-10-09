<?php

// BR-02..BR-07 : images de marque, domaines personnalisés, expéditeur des e-mails et des SMS.
return [
    'attributes' => [
        'kind' => 'type d’image',
        'file' => 'image',
        'host' => 'domaine',
        'slug' => 'sous-domaine',
        'email_from_name' => 'nom de l’expéditeur',
        'email_from_address' => 'adresse de l’expéditeur',
        'sms_sender_id' => 'identifiant d’expéditeur SMS',
    ],

    'errors' => [
        'asset_not_stored' => 'L’image n’a pas pu être enregistrée. Réessayez dans un instant.',
        'file_too_large' => 'Cette image dépasse :max. Choisissez-en une plus petite.',
        'file_type' => 'Utilisez une image JPEG, PNG ou WebP.',
        'file_unreadable' => 'Ce fichier ne peut pas être lu comme une image. Choisissez-en un autre.',
        'favicon_size' => 'Une icône de site doit être une image carrée de 16 à 512 pixels de côté.',
        'host_format' => 'Saisissez un domaine complet, comme erp.entreprise.cd, sans http:// ni chemin.',
        'platform_domain' => 'C’est le domaine de la plateforme. Choisissez plutôt votre sous-domaine sur la page Marque.',
        'domain_taken' => 'Ce domaine est déjà utilisé. Retirez-le d’abord de l’autre compte, ou utilisez un autre domaine.',
        'slug_taken' => 'Ce sous-domaine est déjà pris. Choisissez-en un autre.',
        'slug_format' => 'Utilisez des lettres minuscules, des chiffres et des tirets, en commençant et en finissant par une lettre ou un chiffre.',
        'slug_reserved' => 'Ce sous-domaine est réservé. Choisissez-en un autre.',
        'sender_domain' => 'Envoyez depuis une adresse d’un de vos domaines vérifiés. Ajoutez et vérifiez d’abord le domaine.',
        'from_name_format' => 'Le nom de l’expéditeur doit tenir sur une ligne, sans < > ni guillemets.',
        'sms_sender_format' => 'Utilisez 3 à 11 lettres, chiffres ou espaces, dont au moins une lettre.',
    ],
];
