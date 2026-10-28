---
id: pos-sell
title: Vendre des articles
module: pos
order: 30
permissions: [pos.sale.create, pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:PhoneCartScreen]
---
Composez la vente sur l'écran de vente, puis encaissez-la. Vous avez besoin d'un service ouvert.

1. Touchez un produit pour l'ajouter à « Vente en cours ». Touchez-le encore pour en ajouter un de plus.
2. Pour trouver un produit, saisissez son nom ou son code dans « Rechercher des produits », ou touchez une catégorie. « Tout » affiche tous les produits.
3. Pour scanner un code-barres, touchez « Scanner ». La première fois, touchez « Autoriser la caméra ».
4. Pour modifier une ligne, touchez-la. Réglez la « Quantité », puis touchez « Enregistrer la ligne ». « Retirer de la vente » supprime la ligne.
5. Pour ajouter un client, touchez « Ajouter un client » et cherchez par « Nom ou numéro de téléphone ». Touchez « Changer » pour choisir quelqu'un d'autre.
6. Vérifiez les totaux : sous-total, remise, taxe et total.
7. Touchez « Encaisser » avec le montant affiché. Prenez ensuite le paiement.

## Mettre une vente en attente

Pour servir quelqu'un d'autre d'abord, touchez « Mettre en attente ». Touchez « Ventes en attente » en haut pour la retrouver, puis « Reprendre ». « Supprimer » retire une vente en attente.

## Recommencer

« Vider » vide la vente.

## Limites

- Changer le « Prix unitaire » demande un responsable. Voir « Demander l'approbation d'un responsable ».
- Un article marqué « Pas de prix », « Taux requis » ou « Non vendable » ne peut pas être vendu tant que le back-office ne l'a pas corrigé. Retirez-le pour encaisser le reste.
- Si la vente est suspendue pour un abonnement impayé, la caisse l'indique. Demandez au propriétaire de régler la facture.
