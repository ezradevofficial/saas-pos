<?php

// Paiements en caisse (note de concept 7.1) : messages de l’API et libellés des listes.
return [
    'errors' => [
        'push_unsupported' => 'Ce moyen de paiement ne peut pas envoyer de demande de paiement sur un téléphone. Confirmez le paiement avec sa référence.',
        'payout_unsupported' => 'Ce moyen de paiement ne peut pas rembourser seul. Remboursez le client autrement.',
        'check_unsupported' => 'Ce moyen de paiement ne peut pas vérifier les codes de paiement. Rapprochez le paiement dans le back-office.',
        'provider_refused' => 'Le fournisseur de paiement a refusé la demande. Vérifiez les informations et réessayez, ou prenez un autre moyen de paiement.',
        'declined' => 'Le client a refusé le paiement sur son téléphone. Demandez-lui de réessayer, ou prenez un autre moyen de paiement.',
        'not_reached' => 'Le téléphone du client n’a pas répondu à temps. Demandez-lui de vérifier son téléphone et réessayez.',
        'provider_auth' => 'Le fournisseur de paiement a refusé les identifiants. Vérifiez les clés du moyen de paiement dans le back-office.',
        'phone_invalid' => 'Saisissez un numéro de mobile valide, par exemple 0712 345 678.',
        'receipt_used' => 'Ce code de paiement a déjà servi pour une autre vente. Vérifiez le code dans le message du client.',
        'receipt_invalid' => 'Saisissez le code de paiement du message du client : 6 à 20 lettres et chiffres.',
        'unreachable' => 'Le fournisseur de paiement est injoignable. Réessayez, ou prenez un autre moyen de paiement.',
        'amount_mismatch' => 'Le montant indiqué par le fournisseur n’est pas celui du paiement. Vérifiez-le dans le back-office.',
        'no_result' => 'Le fournisseur de paiement n’a envoyé aucun résultat. Vérifiez le remboursement auprès du fournisseur avant de le refaire.',
        'not_matchable' => 'Ce paiement reçu ne peut pas être rapproché de ce paiement. Choisissez un paiement non vérifié ou sans réponse, du même moyen et de la même devise.',
        'currency_not_supported' => 'Ce moyen de paiement n’accepte que :currencies. Prenez le paiement dans cette devise ou avec un autre moyen.',
        'whole_units' => 'Ce moyen de paiement n’accepte que des montants entiers en :currency. Arrondissez le montant, ou prenez les centimes en espèces.',
        'initiator_missing' => 'Les remboursements et vérifications de paiement demandent le nom de l’initiateur et son identifiant de sécurité. Ajoutez-les au moyen de paiement.',
        'phone_unknown' => 'Le numéro du paiement d’origine est inconnu, le remboursement ne peut pas être envoyé. Remboursez le client autrement.',
        'callback_unknown' => 'Rappel inconnu.',
        'callback_invalid' => 'Le contenu du rappel n’est pas valide.',
        'payout_failed' => 'Le fournisseur n’a pas versé le remboursement. Remboursez le client autrement.',
        'code_not_found' => 'Le fournisseur ne connaît pas ce code de paiement. Vérifiez le paiement avec le client.',
        'check_failed' => 'Le fournisseur n’a pas pu vérifier ce code de paiement. Vérifiez-le dans le back-office.',
        'method_unavailable' => 'Choisissez un moyen de paiement actif de la société de cette caisse.',
        'register_unsupported' => 'Seuls les moyens M-Pesa enregistrent leurs URL auprès du fournisseur.',
        'not_configured' => 'Saisissez d’abord les paramètres et identifiants du moyen de paiement.',
        'register_failed' => 'Safaricom a refusé d’enregistrer les URL. Vérifiez le shortcode et les produits de l’application sur le portail Daraja, puis réessayez.',
    ],

    'daraja' => [
        'description' => 'Paiement',
        'refund_remarks' => 'Remboursement',
    ],

    'purposes' => [
        'sale' => 'Vente',
        'refund' => 'Remboursement',
    ],

    'modes' => [
        'direct' => 'Direct',
        'stk' => 'Demande sur téléphone',
        'manual' => 'Code saisi',
        'payout' => 'Versement',
    ],

    'statuses' => [
        'pending' => 'En attente',
        'succeeded' => 'Payé',
        'failed' => 'Échoué',
        'cancelled' => 'Refusé',
        'timeout' => 'Délai dépassé',
    ],

    'verifications' => [
        'unverified' => 'Non vérifié',
        'verified' => 'Vérifié',
        'mismatch' => 'Écart',
    ],

    'filters' => [
        'all' => 'Tous',
        'pending' => 'En attente',
        'succeeded' => 'Payé',
        'failed' => 'Échoué',
        'cancelled' => 'Refusé',
        'timeout' => 'Délai dépassé',
        'unverified' => 'Non vérifié',
        'mismatch' => 'Écart',
    ],

    'receipt_statuses' => [
        'matched' => 'Rapproché',
        'unmatched' => 'Non rapproché',
    ],

    'receipt_filters' => [
        'all' => 'Tous',
        'matched' => 'Rapproché',
        'unmatched' => 'Non rapproché',
    ],

    'intents' => [
        'list_title' => 'Paiements de :company',
        'columns' => [
            'created_at' => 'Créé',
            'purpose' => 'Objet',
            'mode' => 'Mode',
            'amount' => 'Montant',
            'phone' => 'Téléphone',
            'reference' => 'Référence',
            'receipt' => 'Code de paiement',
            'status' => 'Statut',
            'verification' => 'Vérification',
            'message' => 'Message',
        ],
    ],

    'receipts' => [
        'list_title' => 'Sommes reçues par :company',
        'columns' => [
            'transacted_at' => 'Reçu',
            'receipt' => 'Code de paiement',
            'amount' => 'Montant',
            'account_reference' => 'Compte',
            'status' => 'Statut',
        ],
    ],
];
