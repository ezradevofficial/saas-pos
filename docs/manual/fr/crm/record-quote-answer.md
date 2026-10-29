---
id: crm-record-quote-answer
title: Enregistrer la réponse du client à un devis
module: crm
order: 140
permissions: [crm.quote.view, crm.quote.send]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/quotes, /crm/quotes/:quoteId]
---
Quand un client répond à un devis envoyé, enregistrez sa réponse.

1. Ouvrez « Devis » et touchez le devis envoyé.
2. Si le client est d'accord, touchez « Enregistrer l'acceptation ». Quand le devis appartient à une opportunité, cochez « Marquer aussi <nom> comme gagnée » pour clore l'affaire en même temps. Confirmez avec « Enregistrer l'acceptation ».
3. Si le client a refusé, touchez « Enregistrer le refus », saisissez le « Motif » et confirmez.

Le devis affiche la date d'acceptation ou de refus. Un devis accepté peut être transformé en facture. Voir « Convertir un devis en facture ».
