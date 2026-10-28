---
id: pos-void-sale
title: Annuler une vente
module: pos
order: 100
permissions: [pos.sale.view, pos.sale.void]
roles: [cashier, branch_manager]
screens: [pos:SalesScreen, pos:OverrideDialog]
---
Annuler une vente la supprime en entier. Utilisez-le pour une erreur faite pendant le même service. Pour une vente d'un service précédent, remboursez plutôt les articles.

1. Touchez « Menu », puis « Ventes et retours ».
2. Trouvez la vente avec « Numéro de ticket » et touchez « Trouver la vente ».
3. Saisissez le motif dans « Motif ».
4. Touchez « Annuler toute la vente ».
5. Un responsable approuve avec son code PIN dans « Approbation d'un responsable », sauf si votre rôle peut annuler. Voir « Demander l'approbation d'un responsable ».

La caisse confirme alors l'annulation, et la vente affiche « Annulée ».

Vous ne pouvez pas annuler :

- une vente d'un autre service, car seules les ventes du service ouvert peuvent être annulées ;
- une vente dont des articles ont été retournés. Retournez plutôt le reste des articles ;
- une vente déjà annulée.
