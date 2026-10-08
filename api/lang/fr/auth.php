<?php

return [
    'failed' => 'Ces identifiants ne correspondent à aucun compte.',
    'throttle' => 'Trop de tentatives de connexion. Réessayez dans :seconds seconde.|Trop de tentatives de connexion. Réessayez dans :seconds secondes.',

    'password' => [
        'incorrect' => 'Le mot de passe fourni est incorrect.',
        'common' => 'Ce mot de passe est trop courant. Choisissez-en un moins prévisible.',
        'too_long' => 'Ce mot de passe est trop long. Utilisez au plus :max caractères, moins s’il contient des lettres accentuées ou des symboles.',
    ],

    'unverified' => 'Vérifiez d’abord votre e-mail ou votre téléphone. Nous vous avons envoyé un nouveau code.',
    'unverified_wait' => 'Vérifiez d’abord votre e-mail ou votre téléphone. Utilisez le code déjà envoyé, ou patientez un peu et reconnectez-vous pour en recevoir un nouveau.',
    'deactivated' => 'Ce compte est désactivé. Demandez à votre administrateur de le réactiver.',
    'locked' => 'Trop d’échecs de connexion. Réessayez dans :minutes minute.|Trop d’échecs de connexion. Réessayez dans :minutes minutes.',

    // AUTH-06..AUTH-08 : codes PIN de caisse, cartes du personnel et validations du responsable.
    'pin' => [
        'format' => 'Saisissez un code PIN de 4 à 6 chiffres.',
        'weak' => 'Ce code PIN est trop facile à deviner. Évitez les chiffres répétés, les suites comme 1234 et les codes courants.',
        'card_format' => 'Scannez de nouveau la carte : son code doit compter de 6 à 64 lettres ou chiffres.',
        'saved' => 'Votre code PIN de caisse est enregistré.',
        'reset' => 'Le code PIN de caisse est réinitialisé. Communiquez le nouveau code à la personne en privé.',
        'removed' => 'Le code PIN de caisse est supprimé. Un nouveau code est nécessaire pour se connecter à une caisse.',
        'locked' => 'Ce code PIN est bloqué sur cette caisse après trop d’essais erronés. Demandez à un responsable de réinitialiser votre code.',
        'not_set' => 'Vous n’avez pas encore de code PIN de caisse. Définissez-en un dans les paramètres de votre compte ou demandez à un responsable.',
        'incorrect' => 'Code PIN erroné. Il reste :count essai avant le blocage du code sur cette caisse.|Code PIN erroné. Il reste :count essais avant le blocage du code sur cette caisse.',
        'not_staff_here' => 'Cette personne n’a pas de rôle sur le site de cette caisse. Demandez à un responsable de lui en attribuer un.',
    ],
    'override' => [
        'invalid' => 'Cette validation du responsable n’est pas valide. Demandez au responsable de saisir de nouveau son code PIN.',
        'expired' => 'Cette validation du responsable a expiré. Demandez au responsable de saisir de nouveau son code PIN.',
        'mismatch' => 'Cette validation du responsable concerne une autre action. Demandez au responsable de valider celle-ci.',
        'replayed' => 'Cette validation du responsable a déjà servi. Demandez au responsable de valider cette action.',
        'not_permitted' => 'Ce responsable n’est pas autorisé à valider cette action ici. Demandez à un responsable qui l’est.',
        'unknown_permission' => 'Choisissez une action qui existe.',
    ],

    'code' => [
        'invalid' => 'Ce code n’est pas valide. Vérifiez-le et réessayez.',
        'expired' => 'Ce code a expiré. Demandez-en un nouveau.',
        'attempts' => 'Trop de codes erronés. Reconnectez-vous pour en recevoir un nouveau.',
        'exhausted' => 'Trop de codes erronés pour celui-ci. Reconnectez-vous pour recevoir un nouveau code.',
    ],

    'two_factor' => [
        'enrollment_required' => 'Votre rôle exige la vérification en deux étapes. Configurez-la pour continuer.',
        'already_enabled' => 'La vérification en deux étapes est déjà activée. Désactivez-la avant de la configurer à nouveau.',
        'not_started' => 'Commencez d’abord la configuration de la vérification en deux étapes, puis saisissez le code.',
        'phone_required' => 'Ajoutez et vérifiez un numéro de téléphone avant d’utiliser les codes par SMS.',
        'required_by_role' => 'Votre rôle exige la vérification en deux étapes : elle ne peut pas être désactivée. Contactez votre administrateur si c’est une erreur.',
        'enabled' => 'La vérification en deux étapes est activée.',
        'disabled' => 'La vérification en deux étapes est désactivée.',
    ],

    'password_reset' => [
        'sent' => 'Si un compte utilise cet e-mail ou ce numéro de téléphone, nous lui avons envoyé un code pour réinitialiser le mot de passe.',
        'done' => 'Votre mot de passe a été modifié. Connectez-vous avec le nouveau mot de passe.',
    ],

    // AUTH-05 : invitations.
    'invitation' => [
        'expired' => 'Cette invitation a expiré. Demandez à votre administrateur d’en envoyer une nouvelle.',
        'revoked' => 'Cette invitation a été retirée. Contactez votre administrateur si vous avez encore besoin d’un accès.',
        'accepted' => 'Cette invitation a déjà été utilisée. Connectez-vous plutôt.',
        'stale' => 'Cette invitation ne correspond plus à l’organisation. Demandez à votre administrateur d’en envoyer une nouvelle.',
    ],

    // AUTH-13 : administration des utilisateurs.
    'users' => [
        'contact_unverified' => 'Cet utilisateur n’a jamais vérifié d’e-mail ni de numéro de téléphone : il ne peut pas être réactivé. Invitez-le de nouveau.',
    ],

    'notifications' => [
        'greeting' => 'Bonjour :name,',
        'verification_code' => [
            'expiry' => 'Il expire dans :minutes minute et ne fonctionne qu’une fois.|Il expire dans :minutes minutes et ne fonctionne qu’une fois.',
            'ignore' => 'Si vous ne l’avez pas demandé, ignorez ce message.',
            'verify_contact' => [
                'subject' => 'Votre code de vérification :app',
                'line' => 'Votre code de vérification est :code.',
                'sms' => 'Code de vérification :app : :code. Il expire dans :minutes minute.|Code de vérification :app : :code. Il expire dans :minutes minutes.',
            ],
            'two_factor' => [
                'subject' => 'Votre code de connexion :app',
                'line' => 'Votre code de vérification en deux étapes est :code.',
                'sms' => 'Code de connexion :app : :code. Il expire dans :minutes minute. Ne le partagez jamais.|Code de connexion :app : :code. Il expire dans :minutes minutes. Ne le partagez jamais.',
            ],
            'password_reset' => [
                'subject' => 'Réinitialisez votre mot de passe :app',
                'line' => 'Votre code de réinitialisation du mot de passe est :code.',
                'sms' => 'Code de réinitialisation du mot de passe :app : :code. Il expire dans :minutes minute.|Code de réinitialisation du mot de passe :app : :code. Il expire dans :minutes minutes.',
            ],
        ],
        // ADR 009 : types d’événements système, envoyés par le Notifier
        // (modèles, journal des envois). Les variables s’écrivent {nom}.
        'events' => [
            'invited' => [
                'label' => 'Invitation à rejoindre',
                'subject' => 'Rejoignez {tenant_name} sur {app_name}',
                'body' => "Bonjour {recipient_name},\n\n{inviter_name} vous invite à rejoindre {tenant_name}. Ouvrez le lien pour accepter.\n\nL’invitation est valable jusqu’au {expires_at}.\n\nSi vous ne l’attendiez pas, ignorez ce message.",
                'sms' => '{app_name} : vous êtes invité à rejoindre {tenant_name}. Acceptez ici : {invitation_url}',
            ],
            'new_device' => [
                'label' => 'Connexion depuis un nouvel appareil',
                'subject' => 'Nouvelle connexion à votre compte {app_name}',
                'body' => "Bonjour {recipient_name},\n\nUne connexion à votre compte a eu lieu depuis un nouvel appareil le {time}.\n\nAppareil : {device}. Adresse IP : {ip}.\n\nSi ce n’était pas vous, changez votre mot de passe et fermez la session depuis les paramètres de votre compte.",
                'sms' => '{app_name} : nouvelle connexion à votre compte le {time}. Si ce n’était pas vous, changez votre mot de passe.',
            ],
        ],
    ],
];
