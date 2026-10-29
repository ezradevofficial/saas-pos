---
id: procurement-add-landed-costs
title: Ajouter des frais d’approche aux marchandises reçues
module: procurement
order: 14
permissions: [procurement.landed_cost.view, procurement.landed_cost.create]
roles: [accountant, procurement_officer, owner]
screens: [/procurement/landed-costs, /procurement/landed-costs/new, /procurement/landed-costs/:landedCostId, /procurement/landed-costs/:landedCostId/edit]
---
Le fret, les droits, l’assurance et le dédouanement sont répartis sur les marchandises reçues : le coût de chaque article les inclut.

1. Ouvrez « Frais d’approche » sous « Achats » et touchez « Nouveaux frais d’approche ».
2. Choisissez la « Succursale », réglez la « Date » et choisissez la « Méthode de répartition » : « Selon la valeur », « Selon la quantité » ou « Selon le poids ». Les frais sont convertis en devise de base au taux de la date. Pour « Selon le poids », chaque ligne reçue doit avoir son poids.
3. Saisissez un « Libellé ».
4. Sous « Réceptions concernées », choisissez les réceptions.
5. Sous « Frais », saisissez le « Frais » (par exemple le fret), choisissez « Facturé par », saisissez le « Numéro de facture du fournisseur », vérifiez la « Devise » et saisissez le « Montant ». Touchez « Ajouter des frais » pour en ajouter. Le numéro de facture est nécessaire pour valider : la facture est comptabilisée en dettes fournisseurs.
6. Touchez « Enregistrer les frais d’approche ». Ils sont enregistrés comme brouillon.
7. Ouvrez-les. « Répartition » montre ce que la comptabilisation répartira sur chaque ligne reçue, en devise de base. Si la page indique que les frais ne peuvent pas encore être répartis, corrigez ce qu’elle signale.
8. Touchez « Comptabiliser les frais ».

Touchez « Modifier » pour changer un brouillon, ou « Annuler le brouillon » pour l’abandonner.
