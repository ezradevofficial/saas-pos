---
id: mfg-plan-order
title: Planifier et lancer un ordre de fabrication
module: manufacturing
order: 3
permissions: [manufacturing.order.view, manufacturing.order.create, manufacturing.order.release]
roles: [branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/new, /manufacturing/orders/:orderId, /manufacturing/orders/:orderId/edit]
---
Un ordre de fabrication dit quoi fabriquer, combien et où. La version de la nomenclature en vigueur au début prévu donne les matières.

1. Ouvrez « Ordres de fabrication » sous « Fabrication » et touchez « Planifier un ordre ». Si la page indique qu’aucun produit n’a encore de nomenclature de production, créez-en une d’abord. Voir « Créer une nomenclature ou une recette ».
2. Choisissez l’« Emplacement », le « Produit », saisissez la « Quantité » et choisissez l’« Unité ».
3. Réglez le « Début prévu » et la « Fin prévue », et ajoutez une « Remarque ».
4. Touchez « Planifier l’ordre ». L’ordre reçoit un numéro et est « Planifié ». La page liste ses « Matières ».
5. Tant qu’il est planifié, touchez « Modifier » pour le changer, puis « Enregistrer les modifications ».
6. Touchez « Lancer ». Si la société demande une approbation pour lancer les ordres, la page indique « Lancement envoyé pour approbation » et un responsable d’agence le décide dans sa boîte d’approbations. Voir « Décider des approbations ». Les matières peuvent être sorties une fois l’ordre lancé.

Pour arrêter un ordre, touchez « Annuler l’ordre », saisissez « Motif d’annulation » et confirmez. Un ordre annulé ne peut pas être rouvert. Touchez « Imprimer » pour obtenir l’ordre de fabrication ou la liste de prélèvement en noir sur blanc, ou « Télécharger le PDF ».

Quand le module Stocks est désactivé, les ordres n’enregistrent que des quantités : aucun mouvement de stock ni coût.
