<?php

// Module Vente ambulante (docs/modules/van-sales.md) : messages de l’API. Les noms
// des camionnettes, tournées, clients et articles sont affichés tels que saisis.
return [
    'errors' => [
        'van_unknown' => 'Choisissez une camionnette qui existe et qui n’est pas archivée.',
        'route_unknown' => 'Choisissez une tournée qui existe et qui n’est pas archivée.',
        'run_unknown' => 'Cette journée n’a pas été démarrée sur cette camionnette. Démarrez d’abord la journée.',
        'run_closed' => 'Cette journée est déjà clôturée. Démarrez une nouvelle journée pour continuer.',
        'run_open' => 'Cette camionnette a déjà une journée ouverte. Comptez-la et clôturez-la avant d’en démarrer une autre.',
        'customer_unknown' => 'Choisissez un client qui existe.',
        'stop_unknown' => 'Cet arrêt n’est sur aucune tournée de cette société. Synchronisez la camionnette et réessayez.',
        'rep_unknown' => 'Choisissez une personne active.',
        'not_permitted' => 'Cette personne n’a pas le droit de faire cela à l’emplacement de la camionnette.',
        'location_unknown' => 'Choisissez un emplacement qui existe.',
        'location_archived' => 'Cet emplacement est archivé. Choisissez un emplacement actif.',
        'device_unknown' => 'Choisissez un appareil associé à l’emplacement de la camionnette.',
        'name_taken' => 'Ce nom est déjà utilisé. Choisissez un autre nom.',
        'id_conflict' => 'Cet identifiant est déjà utilisé pour autre chose. Recréez l’enregistrement.',
        'payload_mismatch' => 'Cet enregistrement a déjà été envoyé avec un contenu différent. Il a été conservé tel qu’envoyé la première fois.',
        'visit_invalid' => 'Choisissez un motif lorsqu’aucune vente n’a été faite.',
        'collection_invalid' => 'Vérifiez le mode de paiement, la devise et le montant de cet encaissement.',
        'receipt_range_unknown' => 'Le numéro de reçu ne vient pas de la plage de cet appareil. Synchronisez l’appareil et réessayez.',
        'receipt_number_mismatch' => 'Le numéro de reçu ne correspond pas à la numérotation de cet appareil. Synchronisez l’appareil et réessayez.',
        'count_open' => 'Un comptage de cette journée attend une décision. Attendez-la avant de recompter.',
        'load_not_open' => 'Cette feuille de chargement ne peut plus être modifiée.',
        'load_not_approved' => 'Ce chargement n’est pas encore approuvé. Demandez à un approbateur d’approuver le transfert.',
        'load_not_released' => 'Ce chargement n’a pas encore été libéré par l’entrepôt.',
        'load_same_location' => 'Choisissez un entrepôt autre que la camionnette.',
        'load_cross_company' => 'Choisissez un entrepôt de la société de la camionnette.',
        'load_line_unknown' => 'Une ligne n’appartient pas à cette feuille de chargement. Rechargez la feuille et recomptez.',
        'load_received_invalid' => 'La quantité reçue ne peut être ni négative ni supérieure à la quantité envoyée.',
        'load_unknown' => 'Cette feuille de chargement n’est pas pour cette camionnette.',
        'item_unknown' => 'Choisissez un article en stock qui existe et qui n’est pas archivé.',
        'van_has_open_run' => 'Cette camionnette a une journée ouverte ou en attente de décision. Clôturez-la d’abord.',
        'route_has_open_run' => 'Une journée est ouverte sur cette tournée. Clôturez-la d’abord.',
        'company_mismatch' => 'La société ne correspond pas à la succursale.',
        'collection_voided' => 'Cet encaissement est déjà annulé.',
        'review_not_pending' => 'Cette vente a déjà été examinée.',
        'unload_not_ready' => 'Le déchargement n’est pas prêt à être reçu. Il faut un transfert approuvé vers l’entrepôt.',
        'upload_rejected' => 'Rien n’a été enregistré. Vérifiez les messages de chaque enregistrement et renvoyez.',
        'batch_size' => 'Envoyez entre 1 et 100 enregistrements à la fois.',
    ],

    'loads' => [
        'short_reason' => 'Manquant sur la camionnette lors du comptage',
        'unload_short_reason' => 'Manquant à l’entrepôt lors du comptage',
    ],

    'workflow' => [
        'reconciliation' => [
            'type' => 'Rapprochement de camionnette',
            'start' => 'Compté',
            'approve' => 'Approbation du superviseur de tournée',
            'approved' => 'Approuvé',
            'rejected' => 'Rejeté',
        ],
        'fields' => [
            'location' => 'Emplacement de la camionnette',
            'branch' => 'Succursale',
            'company' => 'Société',
            'rep' => 'Vendeur',
            'cash_variance' => 'Écart d’espèces et de mobile money',
            'stock_variance_lines' => 'Articles avec un écart de stock',
        ],
    ],
];
