---
id: pos-receipt
title: Remettre le ticket
module: pos
order: 70
permissions: [pos.sale.print, pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:ReceiptScreen, pos:SalesScreen]
---
Quand une vente est terminée, la caisse affiche « Vente terminée » avec le ticket.

1. Si le client a payé plus que le total, rendez-lui le montant affiché sous « À rendre au client ».
2. Touchez « Imprimer » pour imprimer le ticket.
3. Touchez « Nouvelle vente » pour commencer la suivante.

## Ligne de l'administration fiscale

Le ticket porte une ligne pour l'administration fiscale (KRA eTIMS au Kenya, DGI en RD Congo). Son état indique où en est la vente :

- « En attente d'envoi » : la vente est sur cette caisse et sera envoyée quand la caisse sera en ligne.
- « En attente » : la vente a atteint le serveur et l'administration n'a pas encore répondu.
- « Accepté » : le code et le QR code de l'administration sont ajoutés.
- « Refusé par l'administration » : le back-office assure le suivi. La vente reste valable.
- « Non transmis à l'administration » : cette entreprise ne transmet pas encore ses ventes depuis la plateforme.

## Retrouver un ancien ticket

Touchez « Menu », puis « Ventes et retours ». Saisissez le numéro dans « Numéro de ticket » et touchez « Trouver la vente », ou choisissez la vente dans la liste.
