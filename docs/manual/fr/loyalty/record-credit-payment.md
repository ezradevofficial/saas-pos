---
id: loyalty-record-credit-payment
title: Enregistrer un paiement, un débit ou un ajustement de crédit
module: loyalty
order: 90
permissions: [loyalty.credit.view, loyalty.credit.receive, loyalty.credit.charge, loyalty.credit.adjust]
roles: [owner, branch_manager, accountant]
screens: [/loyalty/credit, /loyalty/credit/:accountId]
---
1. Ouvrez « Comptes crédit » et touchez le compte du client. La page montre le solde et l'« Historique du compte » avec un « Solde » courant.
2. Quand le client paie, touchez « Enregistrer un paiement », saisissez le « Montant » et la « Référence », et touchez « Enregistrer le paiement ». Les paiements soldent d'abord la vente la plus ancienne.
3. Pour ajouter une somme due, touchez « Débiter », saisissez le « Montant », et touchez « Débiter ».
4. Pour corriger un solde, touchez « Ajuster ». Choisissez « Réduire ce que le client doit » si le solde baisse, saisissez le « Montant », le « Motif » et une « Remarque », et touchez « Ajuster ».

Chaque bouton n'apparaît que si vous avez la permission correspondante, et « Enregistrer un paiement » et « Débiter » seulement tant que le compte est ouvert.
