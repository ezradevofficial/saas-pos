<?php

// APR-01..APR-09 : approbations.
return [
    'list_title' => 'Approbations',
    'system' => 'Le système',

    'approver_types' => [
        'branch_manager' => 'Responsable d’agence',
        'department_head' => 'Chef de département',
        'cost_centre_owner' => 'Responsable du centre de coûts',
        'manager_levels_up' => 'Responsable N niveaux au-dessus',
        'manager_levels_up_n' => 'Responsable :levels niveau(x) au-dessus',
        'role' => 'Rôle',
        'user' => 'Utilisateur désigné',
        'stage_roles' => 'Personnes pouvant terminer l’étape',
    ],

    'escalation_targets' => [
        'next_level' => 'le responsable du niveau supérieur',
    ],

    'outcomes' => [
        'approved' => 'approuvé',
        'rejected' => 'rejeté',
    ],

    'statuses' => [
        'pending' => 'En attente',
        'approved' => 'Approuvé',
        'rejected' => 'Rejeté',
        'returned' => 'Renvoyé pour modification',
        'cancelled' => 'Annulé',
        'expired' => 'Clôturé',
    ],

    'filters' => [
        'waiting' => 'En attente de vous',
        'decided' => 'Décidés par vous',
        'all' => 'Tous',
    ],

    'columns' => [
        'received_at' => 'Reçu',
        'type' => 'Type',
        'number' => 'Numéro',
        'title' => 'Résumé',
        'amount' => 'Montant',
        'step' => 'Étape',
        'requester' => 'Demandé par',
        'status' => 'Statut',
        'due_at' => 'Échéance',
    ],

    'blocked' => [
        'no_approver' => 'Personne ne peut approuver cette étape : les approbateurs manquent ou sont le demandeur. Un administrateur peut la réattribuer.',
    ],

    'history' => [
        'requested' => 'Envoyé pour approbation',
        'step' => 'Passé à l’approbateur suivant',
        'approved' => 'Approuvé',
        'rejected' => 'Rejeté',
        'returned' => 'Renvoyé pour modification',
        'commented' => 'Commentaire ajouté',
        'info_requested' => 'Informations demandées',
        'attached' => 'Fichier joint',
        'reminded' => 'Rappel envoyé',
        'escalated' => 'Escaladé',
        'escalation_exhausted' => 'Plus personne vers qui escalader',
        'reassigned' => 'Réattribué',
        'blocked' => 'En attente d’un approbateur',
        'auto_approved' => 'Approuvé automatiquement au délai final',
        'auto_rejected' => 'Rejeté automatiquement au délai final',
        'auto_failed' => 'Rappels et escalade arrêtés : ils n’ont pas pu être traités',
        'auto_approve_refused' => 'Non approuvé automatiquement : personne d’indépendant ne pouvait l’approuver',
        'closed' => 'Clôturé',
    ],

    'attributes' => [
        'comment' => 'commentaire',
        'node' => 'étape',
        'reason' => 'motif',
        'file' => 'fichier',
        'from_user' => 'approbateur actuel',
        'to_user' => 'nouvel approbateur',
        'ids' => 'approbations',
        'delegate' => 'délégataire',
        'starts_on' => 'date de début',
        'ends_on' => 'date de fin',
        'document_types' => 'types de documents',
        'note' => 'note',
    ],

    'errors' => [
        'self_reassign' => 'Vous ne pouvez pas réattribuer votre propre demande.',
        'target_decided' => 'Cette personne a déjà décidé de cette demande. Choisissez quelqu’un d’autre.',
        'target_out_of_scope' => 'Cette personne n’a pas d’accès là où se trouve ce document. Choisissez quelqu’un qui y a un rôle.',
        'already_decided' => 'Vous avez déjà décidé de cette demande. Une autre personne doit décider de cette étape.',
        'not_pending' => 'Cette approbation n’attend plus de décision. Actualisez pour voir ce qui s’est passé.',
        'not_assignee' => 'Cette approbation ne vous attend pas. Demandez à un administrateur de vous la réattribuer si vous devez en décider.',
        'reason_required' => 'Indiquez un motif pour cette décision.',
        'self_approval' => 'Vous ne pouvez pas décider de votre propre demande. Elle reste chez les autres approbateurs.',
        'no_requester' => 'Cette demande n’a pas de demandeur à interroger. Ajoutez plutôt un commentaire.',
        'not_pending_approver' => 'Cette personne n’est pas un approbateur actuel de cette demande. Choisissez un des approbateurs en attente.',
        'ineligible_approver' => 'Cette personne ne peut pas approuver cette demande : choisissez un utilisateur actif autre que le demandeur.',
        'already_approver' => 'Cette personne est déjà approbateur de cette étape.',
        'file_type' => 'Ce type de fichier ne peut pas être joint. Joignez un PDF, une image, un fichier texte, CSV, Word ou Excel.',
        'file_not_stored' => 'Le fichier n’a pas pu être enregistré. Réessayez.',
        'attach_forbidden' => 'Seuls les approbateurs et le demandeur peuvent joindre des fichiers à cette approbation.',
        'attachment_limit' => 'Une approbation peut avoir au plus :max fichiers.',
        'bulk_not_allowed' => 'Cette approbation demande un motif : elle ne peut pas être approuvée en lot. Ouvrez-la pour décider.',
    ],

    'attention' => [
        'auto_failed' => 'ses rappels ou son escalade n’ont pas pu être traités ; ils ont été arrêtés.',
        'auto_approve_refused' => 'personne d’autre que le demandeur ne peut l’approuver ; elle n’a donc pas été approuvée automatiquement au délai final.',
    ],

    'delegations' => [
        'all_types' => 'tous les types de documents',
        'too_long' => 'Une délégation dure au plus un an.',
    ],

    'email' => [
        'approve' => 'Approuver',
        'reject' => 'Rejeter',
        'sign_in' => [
            'used' => 'Ce lien a déjà été utilisé. Connectez-vous pour voir l’approbation.',
            'expired' => 'Ce lien a expiré. Connectez-vous pour décider.',
            'not_waiting' => 'Cette approbation ne vous attend plus. Connectez-vous pour voir ce qui s’est passé.',
            'two_factor' => 'Votre rôle exige une connexion en deux étapes. Connectez-vous pour décider.',
        ],
    ],

    'validation' => [
        'config' => 'Les paramètres d’approbation ne sont pas valides.',
        'chain' => 'Une chaîne séquentielle compte entre un et :max approbateurs.',
        'step' => 'approbateur :step : :problem',
        'approver' => 'choisissez qui approuve',
        'approver_type' => '« :type » n’est pas un type d’approbateur',
        'mode' => 'choisissez un seul, tous ou la majorité',
        'flag' => 'le paramètre :setting doit être activé ou désactivé',
        'escalation' => 'les paramètres d’escalade ne sont pas valides',
        'escalation_after' => 'escaladez après une durée de 1 à 10 000 heures ou jours',
        'escalation_to' => 'escaladez au niveau supérieur, à un rôle ou à un utilisateur',
        'escalation_role' => 'choisissez le rôle vers qui escalader',
        'escalation_user' => 'choisissez l’utilisateur vers qui escalader',
        'escalation_needs_after' => 'indiquez après combien de temps escalader',
        'escalation_final' => 'au délai final, approuvez, rejetez ou ne faites rien',
        'final_needs_time' => 'une décision finale automatique demande un délai ou une durée d’escalade',
        'reminders' => 'au plus :max rappels, chacun après une durée de 1 à 10 000 heures ou jours',
        'dimension_field' => 'ce type de document n’a pas de champ :reference pour trouver l’approbateur',
        'levels' => 'choisissez entre 1 et :max niveaux au-dessus',
        'role' => 'choisissez un rôle',
        'user' => 'choisissez un utilisateur',
    ],

    // Textes des notifications (les {variables} sont remplies pour chaque destinataire).
    'notifications' => [
        'requested' => [
            'label' => 'Approbation demandée',
            'subject' => 'Approuver {document_type} {document_number}',
            'body' => "Bonjour {recipient_name},\n\n{requester_name} vous demande d’approuver {document_type} {document_number} {document_title} {amount} à l’étape « {step} ».",
            'sms' => '{app_name} : approuvez {document_type} {document_number} {amount}.',
        ],
        'decided' => [
            'label' => 'Approbation décidée',
            'subject' => '{document_type} {document_number} : {outcome}',
            'body' => "Bonjour {recipient_name},\n\n{decided_by} a décidé de votre {document_type} {document_number} {document_title} à l’étape « {step} » : {outcome}.\n\n{comment}",
            'sms' => '{app_name} : {document_type} {document_number} : {outcome}.',
        ],
        'returned' => [
            'label' => 'Renvoyé pour modification',
            'subject' => '{document_type} {document_number} doit être modifié',
            'body' => "Bonjour {recipient_name},\n\n{decided_by} a renvoyé votre {document_type} {document_number} pour modification à l’étape « {step} ».\n\nMotif : {comment}",
            'sms' => '{app_name} : {document_type} {document_number} a été renvoyé pour modification.',
        ],
        'info_requested' => [
            'label' => 'Informations demandées',
            'subject' => 'Question sur {document_type} {document_number}',
            'body' => "Bonjour {recipient_name},\n\n{decided_by} a besoin de plus d’informations avant de décider de votre {document_type} {document_number} :\n\n{comment}",
            'sms' => '{app_name} : {decided_by} pose une question sur {document_type} {document_number}.',
        ],
        'reminder' => [
            'label' => 'Rappel d’approbation',
            'subject' => 'Rappel : approuver {document_type} {document_number}',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} {document_title} {amount} attend toujours votre décision à l’étape « {step} ». Échéance : {due}.",
            'sms' => '{app_name} : {document_type} {document_number} attend toujours votre approbation.',
        ],
        'escalated' => [
            'label' => 'Approbation escaladée',
            'subject' => 'Escaladé : approuver {document_type} {document_number}',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} {document_title} {amount} vous a été escaladé : {waiting_for} n’a pas décidé à temps à l’étape « {step} ».",
            'sms' => '{app_name} : {document_type} {document_number} vous a été escaladé.',
        ],
        'attention' => [
            'label' => 'Approbation à traiter par un administrateur',
            'subject' => '{document_type} {document_number} demande un administrateur',
            'body' => "Bonjour {recipient_name},\n\n{document_type} {document_number} {document_title} à l’étape « {step} » demande votre attention : {problem}\n\nOuvrez-la pour la réattribuer ou décider de la suite.",
            'sms' => '{app_name} : {document_type} {document_number} demande un administrateur.',
        ],
        'delegated' => [
            'label' => 'Approbations qui vous sont déléguées',
            'subject' => '{delegator_name} vous a délégué ses approbations',
            'body' => "Bonjour {recipient_name},\n\n{delegator_name} vous a délégué ses approbations de {document_types} du {starts_on} au {ends_on}. Ses éléments portent la mention « Délégué par » dans vos approbations, et vos décisions sont enregistrées en son nom.",
            'sms' => '{app_name} : {delegator_name} vous a délégué ses approbations du {starts_on} au {ends_on}.',
        ],
    ],
];
