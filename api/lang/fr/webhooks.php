<?php

// HOOK-01 : abonnements webhook et livraisons (docs/adr/021-webhooks.md).
return [
    'attributes' => [
        'url' => 'URL de destination',
        'description' => 'description',
        'events' => 'événements',
        'event' => 'événement',
        'company_id' => 'société',
        'branch_id' => 'agence',
    ],

    'url' => [
        'invalid' => 'L’URL de destination n’est pas une adresse web valide. Saisissez une adresse https:// complète avec un nom d’hôte.',
        'not_https' => 'Les destinations de webhook doivent utiliser https://. Choisissez une URL sécurisée.',
        'credentials' => 'L’URL de destination ne peut pas contenir d’identifiant ni de mot de passe. Vérifiez les livraisons avec le secret de signature.',
        'private_address' => 'L’URL de destination mène à un réseau privé ou interne, ce qui n’est pas autorisé. Utilisez une adresse publique.',
        'unresolved' => 'Le nom d’hôte de la destination est introuvable. Vérifiez l’URL.',
    ],

    'errors' => [
        'app_owned' => 'Cet abonnement appartient à une application installée. Vous pouvez le désactiver ; désinstallez l’application pour le supprimer.',
        'too_many' => 'Vous pouvez avoir jusqu’à :count webhooks. Archivez-en un que vous n’utilisez plus, puis réessayez.',
        'event_not_visible' => 'Vous ne voyez pas ces enregistrements, vous ne pouvez donc pas vous abonner à cet événement. Demandez à un propriétaire de s’abonner.',
        'principal_inactive' => 'L’utilisateur au nom duquel ce webhook envoie n’est plus actif. Recréez le webhook avec un utilisateur actif.',
        'webhook_inactive' => 'Ce webhook est désactivé. Activez-le, puis relancez la livraison.',
        'replay_not_visible' => 'Cette livraison ne peut pas être relancée : son type d’événement n’est plus disponible ou l’utilisateur du webhook ne peut plus le voir.',
        'not_owner' => 'Seul l’utilisateur au nom duquel ce webhook envoie peut modifier son URL, ses événements ou son filtre. Vous pouvez modifier sa description ou l’activer et le désactiver ; le propriétaire peut le reprendre.',
        'take_over_not_visible' => 'Vous ne voyez pas les enregistrements de certains événements de ce webhook à sa portée, vous ne pouvez donc pas le reprendre. Retirez d’abord ces événements.',
    ],

    'test_message' => 'Ceci est un événement de test. Votre destination l’a reçu et peut vérifier sa signature.',

    'notifications' => [
        'disabled' => [
            'label' => 'Webhook désactivé',
            'subject' => 'Un webhook a été désactivé après des échecs répétés',
            'body' => "Bonjour {recipient_name},\n\nLe webhook vers {url} a été désactivé après {failures} livraisons échouées d’affilée. Plus aucun événement ne lui est envoyé.\n\nVérifiez que le système destinataire fonctionne, puis réactivez le webhook dans Paramètres, Webhooks. Les livraisons échouées peuvent être relancées depuis son journal.",
        ],
    ],
];
