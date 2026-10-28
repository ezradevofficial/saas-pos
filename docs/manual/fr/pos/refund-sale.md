---
id: pos-refund-sale
title: Rembourser des articles d'une vente
module: pos
order: 90
permissions: [pos.sale.view, pos.sale.refund]
roles: [cashier, branch_manager]
screens: [pos:SalesScreen, pos:OverrideDialog]
---
Utilisez un remboursement quand un client rapporte une partie ou la totalité des articles. Vous avez besoin d'un service ouvert.

1. Touchez « Menu », puis « Ventes et retours ».
2. Saisissez le numéro du ticket dans « Numéro de ticket » et touchez « Trouver la vente ». Vous pouvez aussi toucher la vente dans la liste.
3. Pour chaque article rapporté par le client, touchez le bouton plus (« Retourner un de plus ») jusqu'à la bonne quantité. Chaque ligne indique combien d'articles peuvent encore être retournés.
4. Saisissez le motif dans « Motif ».
5. Vérifiez le total du remboursement. Choisissez comment rembourser : la caisse propose ses moyens en espèces, dans les devises avec lesquelles la vente a été payée.
6. Touchez « Rembourser » avec le montant.
7. Si la caisse demande « Approbation d'un responsable », demandez à un responsable d'approuver. Voir « Demander l'approbation d'un responsable ».
8. La caisse confirme le remboursement et le montant à rendre. Rendez ce montant au client.

Une vente dont des articles ont été retournés affiche « Retour partiel ». Vous ne pouvez pas retourner plus que ce qui a été vendu.

Votre rôle a besoin du droit de rembourser et d'une limite de remboursement. Sans eux, ou au-delà de la limite, un responsable doit approuver chaque remboursement.
