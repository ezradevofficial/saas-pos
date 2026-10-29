---
id: procurement-receive-goods
title: Réceptionner des marchandises d’un bon de commande
module: procurement
order: 10
permissions: [procurement.receipt.view, procurement.receipt.create, procurement.po.view]
roles: [storekeeper]
screens: [/procurement/receipts, /procurement/receipts/new]
---
Les marchandises se réceptionnent à partir d’un bon de commande approuvé.

1. Ouvrez « Bons de commande » sous « Achats » et ouvrez la commande approuvée.
2. Touchez « Réceptionner ».
3. Vérifiez le « Lieu de réception ». C’est le lieu de livraison de la commande, sauf si vous en choisissez un autre.
4. Saisissez le numéro du « Bon de livraison » du fournisseur.
5. Pour chaque ligne, saisissez ce qui est arrivé dans « Reçu » et, si une partie est refusée, « Refusé » et « Motif du refus ».
6. Pour les articles suivis par lot ou numéro de série, saisissez le « Numéro de lot », la « Date d’expiration » ou les « Numéros de série ».
7. Touchez « Enregistrer la réception ». Elle est enregistrée comme brouillon.
8. Vérifiez le brouillon, puis touchez « Comptabiliser la réception ». Le stock augmente à l’emplacement.

Si des lignes sont signalées comme non entrées en stock, vérifiez l’article, son unité ou ses numéros de série, puis ajustez le stock à la main. Pour abandonner un brouillon, touchez « Annuler le brouillon ».

Une livraison ne peut dépasser la commande que dans la limite de la « Réception excédentaire admise » des « Paramètres des achats ». Le fret et les droits payés sur la livraison s’ajoutent ensuite avec les frais d’approche : voir « Ajouter des frais d’approche aux marchandises reçues ». La facture du fournisseur se saisit depuis le bon de commande : voir « Saisir et approuver une facture fournisseur ».
