<?php

// Module Restaurant (docs/modules/restaurant.md) : messages de l’API. Les noms
// de tables, de zones, de postes et d’articles sont affichés tels que saisis.
return [
    'cancelled_by_till' => 'Annulée à la caisse.',
    'errors' => [
        'location_unknown' => 'Choisissez un emplacement existant.',
        'location_archived' => 'Cet emplacement est archivé. Choisissez un emplacement actif.',
        'area_unknown' => 'Choisissez une zone de cet emplacement qui n’est pas archivée.',
        'area_not_empty' => 'Cette zone contient encore des tables. Archivez ou déplacez-les d’abord.',
        'table_in_use' => 'Une commande est ouverte sur cette table. Clôturez-la ou déplacez-la d’abord.',
        'table_unknown' => 'Cette table n’est pas sur le plan de salle de cet emplacement. Synchronisez la caisse et choisissez une autre table.',
        'order_closed' => 'Cette commande est déjà clôturée, fusionnée ou annulée. Saisissez les articles dans une nouvelle commande.',
        'order_unknown' => 'Choisissez une commande existante de cet emplacement.',
        'order_not_empty' => 'Des articles ont déjà été envoyés ou payés sur cette commande, elle ne peut donc pas être annulée. Annulez plutôt les articles.',
        'merge_invalid' => 'Choisissez une autre commande ouverte du même emplacement pour la fusion.',
        'waiter_unknown' => 'Choisissez un serveur existant.',
        'customer_unknown' => 'Choisissez un client existant.',
        'item_unknown' => 'Choisissez un article existant.',
        'item_must_not_be_stock' => 'Choisissez un article hors stock pour les frais de service ou le pourboire.',
        'modifier_unknown' => 'Cette option n’appartient pas à ce groupe. Rechargez le groupe et réessayez.',
        'ticket_status_invalid' => 'Choisissez un statut : nouveau, en préparation, prêt ou servi.',
        'ticket_unknown' => 'Ce bon n’appartient pas à l’emplacement de cette caisse.',
        'ticket_not_permitted' => 'Cette personne ne peut pas modifier les bons de cuisine ici. Demandez à un responsable.',
        'split_invalid' => 'Ce partage ne correspond pas à la commande. Vérifiez les groupes, les places ou le nombre de parts.',
        'void_not_permitted' => 'Vous ne pouvez pas annuler un article déjà envoyé. Demandez à un responsable.',
        'void_limit_exceeded' => 'Cet article dépasse votre limite d’annulation. Demandez à un responsable de l’annuler.',
        'reason_required' => 'Indiquez un motif pour annuler un article déjà envoyé.',
        'line_changed' => 'Cet article est déjà envoyé : son article, sa quantité et son prix ne peuvent plus changer. Annulez-le et ajoutez une nouvelle ligne.',
        'line_conflict' => 'Cette ligne appartient à une autre commande qui n’est plus ouverte. Saisissez-la comme nouvelle ligne.',
        'id_conflict' => 'Cet identifiant est déjà utilisé par une autre caisse ou un autre emplacement.',
        'upload_rejected' => 'Le serveur n’a conservé aucune de ces commandes. Vérifiez les erreurs et renvoyez.',
    ],
];
