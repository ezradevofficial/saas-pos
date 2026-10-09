<?php

// Transmission fiscale (note de concept 7.2) : messages de l’API, libellés des listes et alertes.
return [
    'errors' => [
        'confirm_required' => 'Confirmez que les documents depuis cette date doivent être envoyés à l’administration fiscale.',
        'not_enabled' => 'Activez la transmission avant d’envoyer les ventes antérieures.',
        'currency_unconfirmed' => 'Le traitement des devises dans eTIMS pour les ventes en USD doit être confirmé. Ce document en :currency est retenu jusqu’à la décision, puis renvoyé.',
        'tax_code_missing' => 'L’article « :item » n’a pas de code de taxe, il ne peut pas être envoyé à l’administration fiscale. Donnez-lui une catégorie de taxe, puis réessayez.',
        'fiscal_code_missing' => 'L’article « :item » utilise le code de taxe :code, qui n’a pas de code fiscal. Renseignez le code fiscal du code de taxe, puis réessayez.',
        'fiscal_code_unknown' => 'Le code de taxe :code a le code fiscal :fiscal_code, inconnu de l’administration fiscale. Utilisez l’un de :allowed, puis réessayez.',
        'driver_unavailable' => 'La transmission vers cette administration fiscale n’est pas encore disponible. Laissez la transmission désactivée pour l’instant.',
        'band_rate_conflict' => 'Des lignes de la tranche :band ont été vendues à des taux différents. Vérifiez les codes et taux de taxe, puis réessayez.',
        'item_code_missing' => 'L’article « :item » n’a pas de code. Donnez-lui un code, puis réessayez.',
        'item_class_missing' => 'L’article « :item » n’a pas de classification de l’administration fiscale, et la société n’a pas de valeur par défaut. Renseignez-en une, puis réessayez.',
        'unit_code_missing' => 'L’article « :item » n’a pas de code d’unité de l’administration fiscale, et la société n’a pas de valeur par défaut. Renseignez-en un, puis réessayez.',
        'settings_missing' => 'Saisissez d’abord :fields.',
        'authority_unavailable' => 'L’administration fiscale est injoignable. Un nouvel essai aura lieu.',
        'initialize_refused' => 'L’administration fiscale a refusé d’initialiser l’appareil (:code : :message). Vérifiez le numéro fiscal, l’identifiant de succursale et le numéro de série.',
        'not_initialized' => 'L’appareil n’est pas encore initialisé auprès de l’administration fiscale. Initialisez-le dans les paramètres fiscaux, puis réessayez.',
        'authority_refused' => 'L’administration fiscale a refusé le document (:code : :message).',
        'unexpected' => 'L’envoi a échoué de façon inattendue. Un nouvel essai aura lieu.',
        'settings_unknown' => 'Utilisez uniquement ces paramètres : :keys.',
        'credentials_unknown' => 'Utilisez uniquement ces identifiants : :keys.',
        'driver_invalid' => 'Choisissez une administration fiscale disponible pour le pays de la société.',
        'country_unsupported' => 'La transmission fiscale n’est pas disponible pour le pays de cette société.',
        'not_configured' => 'Enregistrez d’abord les paramètres fiscaux de la société.',
        'not_ready' => 'La transmission ne peut pas encore être activée. Saisissez d’abord :fields.',
        'already_accepted' => 'L’administration fiscale a déjà accepté ce document.',
    ],

    'document_types' => [
        'sale' => 'Vente',
        'refund' => 'Remboursement',
        'void' => 'Annulation',
    ],

    'statuses' => [
        'queued' => 'En file',
        'sending' => 'Envoi en cours',
        'accepted' => 'Accepté',
        'rejected' => 'Rejeté',
        'retrying' => 'Nouvel essai',
        'needs_attention' => 'À décider',
    ],

    'filters' => [
        'all' => 'Tous',
        'pending' => 'Pas encore accepté',
        'queued' => 'En file',
        'sending' => 'Envoi en cours',
        'accepted' => 'Accepté',
        'rejected' => 'Rejeté',
        'retrying' => 'Nouvel essai',
        'needs_attention' => 'À décider',
    ],

    'submissions' => [
        'list_title' => 'Envois fiscaux de :company',
        'columns' => [
            'created_at' => 'Mis en file',
            'document_type' => 'Document',
            'document_number' => 'Numéro',
            'invoice_number' => 'Facture fiscale',
            'status' => 'Statut',
            'attempts' => 'Essais',
            'error' => 'Dernière erreur',
        ],
    ],

    'notifications' => [
        'needs_attention' => [
            'label' => 'Document fiscal en attente de décision',
            'subject' => '{document_type} {document_number} est retenu avant l’envoi à l’administration fiscale',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} de {company_name} est retenu : {error}\n\nUne fois la décision prise, relancez-le depuis la file fiscale.",
            'sms' => '{app_name} : {document_type} {document_number} est retenu avant l’envoi à l’administration fiscale. Ouvrez la file fiscale.',
        ],
        'rejected' => [
            'label' => 'Document fiscal rejeté',
            'subject' => '{document_type} {document_number} a été rejeté par l’administration fiscale',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} de {company_name} n’a pas été accepté : {error}\n\nCorrigez ce qui manque, puis relancez-le depuis la file fiscale.",
            'sms' => '{app_name} : {document_type} {document_number} a été rejeté par l’administration fiscale. Ouvrez la file fiscale.',
        ],
        'delayed' => [
            'label' => 'Document fiscal pas encore envoyé',
            'subject' => '{document_type} {document_number} n’est pas accepté après {hours} heures',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} de {company_name} n’a pas été accepté par l’administration fiscale après {hours} heures. Dernière erreur : {error}\n\nDe nouveaux essais ont lieu. Vérifiez la connexion et les paramètres fiscaux.",
            'sms' => '{app_name} : {document_type} {document_number} n’est pas accepté par l’administration fiscale après {hours} heures.',
        ],
    ],
];
