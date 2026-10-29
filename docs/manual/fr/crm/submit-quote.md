---
id: crm-submit-quote
title: Soumettre un devis
module: crm
order: 120
permissions: [crm.quote.view, crm.quote.create]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/quotes, /crm/quotes/:quoteId]
---
Soumettez un brouillon terminé pour pouvoir l'envoyer.

1. Ouvrez « Devis » et touchez le brouillon.
2. Vérifiez les lignes et les totaux. Si un bandeau indique « Un taux de taxe est nécessaire », faites d'abord confirmer le taux dans Taxes. « Soumettre » reste indisponible jusque-là.
3. Touchez « Soumettre ».

Si une remise dépasse votre limite, ou si le total dépasse le seuil d'approbation de la société, le devis part chez un responsable et affiche « En attente d'approbation ». Le responsable décide dans Approbations. Voir « Décider des approbations ». Une fois approuvé, vous pouvez l'envoyer.
