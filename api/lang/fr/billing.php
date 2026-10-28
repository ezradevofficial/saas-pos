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

    // SUB-02..SUB-07, LIM-05 : abonnements, factures de la plateforme, paiements et relances.
    'errors' => [
        'unavailable' => 'La facturation n’est pas encore configurée. Réessayez plus tard ou contactez le support.',
        'no_seller' => 'La facturation n’est pas encore disponible pour :country. Contactez le support.',
        'no_subscription' => 'Votre organisation n’a pas encore d’abonnement. Choisissez une formule pour commencer.',
        'plan_quoted' => 'Cette offre est chiffrée par notre équipe commerciale. Demandez un devis.',
        'coupon_already_applied' => 'Votre abonnement a déjà un coupon. Un seul coupon peut être utilisé.',
        'coupon_not_applicable' => 'Ce coupon ne s’applique pas à la formule choisie. Retirez-le ou changez de formule.',
        'no_change' => 'C’est déjà votre formule. Changez d’abord un module, une quantité ou la périodicité.',
        'quote_changed' => 'Le prix a changé depuis votre consultation. Vérifiez le nouveau montant, puis confirmez à nouveau.',
        'invoice_not_open' => 'Cette facture est déjà payée ou annulée. Actualisez pour voir son statut.',
        'card_setup_unknown' => 'Nous ne trouvons pas cet enregistrement de carte. Ajoutez la carte à nouveau.',
        'card_not_saved' => 'Le prestataire n’a pas enregistré la carte. Réessayez ou utilisez un autre moyen.',
        'payment_method_archived' => 'Ce moyen de paiement a été retiré. Choisissez-en un autre.',
        'payment_method_unknown' => 'Choisissez l’un de vos moyens de paiement enregistrés.',
        'provider_unavailable' => 'Ce moyen de paiement n’est pas encore disponible. Choisissez-en un autre ou contactez le support.',
        'payer_email_missing' => 'Ajoutez une adresse e-mail à votre profil, puis payez à nouveau. Le paiement par carte en a besoin pour le reçu.',
        'payment_in_progress' => 'Un paiement de cette facture attend encore le payeur. Validez-le sur le téléphone, ou attendez qu’il se termine, puis réessayez.',
        'payment_in_progress_card' => 'Un paiement par carte ou portefeuille de cette facture est encore en cours de confirmation. Patientez un instant, puis réessayez.',
        'invoice_paid' => 'Cette facture est déjà payée. Actualisez pour voir son statut.',
        'payment_failed' => 'Le paiement n’a pas abouti. Réessayez ou utilisez un autre moyen.',
        'already_subscribed' => 'Vous êtes déjà abonné. Vos factures figurent ci-dessous.',
        'subscribed_change_plan' => 'Vous êtes déjà abonné : la formule se change dans Paramètres, Abonnement.',
        'not_in_trial' => 'Cet abonnement n’est plus en période d’essai.',
    ],

    'methods' => [
        'card_checkout' => 'Carte',
    ],

    'read_only' => [
        'message' => 'Votre abonnement est en retard de paiement : votre compte est en lecture seule. Réglez la facture ouverte dans Paramètres, Abonnement pour continuer.',
        'pos_stopped' => 'Votre abonnement est en retard de paiement et la caisse ne peut plus envoyer de ventes. Demandez au propriétaire de régler la facture ouverte dans Paramètres, Abonnement.',
    ],

    'lines' => [
        'bundle' => 'Offre :key',
        'module' => 'Module :key',
        'overage' => 'Utilisation supplémentaire : :key',
        'annual_discount' => 'Remise pour paiement annuel',
        'coupon' => 'Coupon :key',
        'rounding' => 'Arrondi',
        'proration' => 'Changement de formule pour le reste de la période',
        'app' => 'Application : :key',
        'proration_days' => ':days jours sur :period',
        'months' => ':months mois',
        'included' => ':count inclus',
        'bundle_outlets' => ':outlets points de vente : :included inclus, :extra en plus',
        'minimum' => 'montant minimum',
        'overage_days' => ':quantity :unit en plus, :days jours sur :period',
    ],

    'units' => [
        'outlet' => 'point de vente',
        'device' => 'appareil',
        'warehouse' => 'entrepôt',
        'online_store' => 'boutique en ligne',
        'company' => 'société',
        'employee' => 'employé',
        'tenant' => 'organisation',
    ],

    'invoices' => [
        'list_title' => 'Factures d’abonnement',
        'number_type' => 'Facture d’abonnement',
        'columns' => [
            'number' => 'Numéro',
            'issued_at' => 'Émise le',
            'kind' => 'Type',
            'period' => 'Période',
            'total' => 'Total',
            'due_at' => 'Échéance',
            'status' => 'Statut',
        ],
        'kinds' => [
            'first' => 'Première facture',
            'renewal' => 'Renouvellement',
            'upgrade' => 'Changement de formule',
        ],
        'statuses' => [
            'open' => 'Ouverte',
            'paid' => 'Payée',
            'void' => 'Annulée',
        ],
        'filters' => [
            'all' => 'Toutes',
            'open' => 'Ouvertes',
            'paid' => 'Payées',
            'void' => 'Annulées',
        ],
    ],

    'notifications' => [
        'trial_ending' => [
            'label' => 'Fin de l’essai',
            'subject' => 'Votre essai se termine dans {days} jours',
            'body' => "Bonjour {recipient_name},\n\nVotre essai de {app_name} se termine le {date}. Votre première facture sera de {amount}. Réglez-la dans Paramètres, Abonnement pour que tout continue de fonctionner.",
            'sms' => '{app_name} : votre essai se termine le {date}. Première facture {amount}.',
        ],
        'renewal_due' => [
            'label' => 'Renouvellement de l’abonnement',
            'subject' => 'Votre abonnement se renouvelle le {date}',
            'body' => "Bonjour {recipient_name},\n\nVotre abonnement à {app_name} se renouvelle le {date} pour {amount}. Réglez-le dans Paramètres, Abonnement, ou gardez un moyen de paiement enregistré pour le renouvellement automatique.",
            'sms' => '{app_name} : votre abonnement se renouvelle le {date} pour {amount}.',
        ],
        'past_due' => [
            'label' => 'Facture d’abonnement en retard',
            'subject' => 'La facture {number} est en retard',
            'body' => "Bonjour {recipient_name},\n\nLa facture {number} de {amount} était due le {date} et n’est pas payée. Réglez-la avant le {read_only_date} pour garder un accès complet ; ensuite, votre compte passera en lecture seule.",
            'sms' => '{app_name} : la facture {number} ({amount}) est en retard. Réglez-la avant le {read_only_date}.',
        ],
        'read_only' => [
            'label' => 'Compte en lecture seule',
            'subject' => 'Votre compte est en lecture seule jusqu’au paiement de la facture {number}',
            'body' => "Bonjour {recipient_name},\n\nLa facture {number} de {amount} n’est toujours pas payée : votre compte est en lecture seule. Vos caisses envoient encore les ventes jusqu’au {pos_until}. Réglez la facture dans Paramètres, Abonnement pour retrouver aussitôt un accès complet.",
            'sms' => '{app_name} : compte en lecture seule jusqu’au paiement de la facture {number} ({amount}). Caisses arrêtées le {pos_until}.',
        ],
        'payment_received' => [
            'label' => 'Paiement d’abonnement reçu',
            'subject' => 'Paiement reçu pour la facture {number}',
            'body' => "Bonjour {recipient_name},\n\nNous avons reçu {amount} pour la facture {number}. Merci.",
            'sms' => '{app_name} : paiement de {amount} reçu pour la facture {number}.',
        ],
    ],
    // ADM-02 : contrats Entreprise sur mesure.
    'deals' => [
        'unknown_item' => 'Ce module ou cette offre n’est pas au catalogue. Choisissez-en un que le catalogue vend.',
        'duplicate_line' => 'Cet élément figure deux fois dans le contrat. Gardez une ligne par élément.',
        'overlaps' => 'Un autre contrat approuvé couvre une partie de ces dates. Terminez-le d’abord ou modifiez les dates.',
        'not_pending' => 'Ce contrat a déjà été tranché.',
        'not_approved' => 'Seul un contrat approuvé peut être terminé.',
        'own_draft' => 'Vous avez rédigé ce contrat : une autre personne des finances doit l’approuver ou le refuser.',
    ],
];
