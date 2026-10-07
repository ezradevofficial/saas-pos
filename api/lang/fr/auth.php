<?php

return [
    'failed' => 'Ces identifiants ne correspondent à aucun compte.',
    'throttle' => 'Trop de tentatives de connexion. Réessayez dans :seconds secondes.',

    'password' => [
        'incorrect' => 'Le mot de passe fourni est incorrect.',
        'common' => 'Ce mot de passe est trop courant. Choisissez-en un moins prévisible.',
        'too_long' => 'Ce mot de passe est trop long. Utilisez au plus :max caractères, moins s’il contient des lettres accentuées ou des symboles.',
    ],

    'unverified' => 'Vérifiez d’abord votre e-mail ou votre téléphone. Nous vous avons envoyé un nouveau code.',
    'deactivated' => 'Ce compte est désactivé. Demandez à votre administrateur de le réactiver.',
    'locked' => 'Trop d’échecs de connexion. Réessayez dans :minutes minutes.',

    'code' => [
        'invalid' => 'Ce code n’est pas valide. Vérifiez-le et réessayez.',
        'expired' => 'Ce code a expiré. Demandez-en un nouveau.',
        'attempts' => 'Trop de codes erronés. Reconnectez-vous pour en recevoir un nouveau.',
        'exhausted' => 'Trop de codes erronés pour celui-ci. Reconnectez-vous pour recevoir un nouveau code.',
    ],

    'notifications' => [
        'greeting' => 'Bonjour :name,',
        'verification_code' => [
            'subject' => 'Votre code de vérification :app',
            'line' => 'Votre code de vérification est :code.',
            'expiry' => 'Il expire dans :minutes minutes et ne fonctionne qu’une fois.',
            'ignore' => 'Si vous ne l’avez pas demandé, ignorez ce message.',
            'sms' => 'Code :app : :code. Il expire dans :minutes minutes.',
        ],
        'new_device' => [
            'subject' => 'Nouvelle connexion à votre compte :app',
            'line' => 'Une connexion à votre compte a eu lieu depuis un nouvel appareil le :time.',
            'details' => 'Appareil : :device. Adresse IP : :ip.',
            'advice' => 'Si ce n’était pas vous, changez votre mot de passe et fermez la session depuis les paramètres de votre compte.',
            'sms' => ':app : nouvelle connexion à votre compte le :time. Si ce n’était pas vous, changez votre mot de passe.',
        ],
    ],
];
