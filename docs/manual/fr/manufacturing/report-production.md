---
id: mfg-report-production
title: Déclarer la production et clôturer un ordre
module: manufacturing
order: 5
permissions: [manufacturing.order.view, manufacturing.production.report, manufacturing.order.close]
roles: [branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/:orderId]
---
Déclarez ce qui est sorti de l’ordre aussi souvent que nécessaire. Clôturez-le quand le travail est terminé.

1. Ouvrez « Ordres de fabrication » sous « Fabrication » et ouvrez un ordre en cours.
2. Touchez « Déclarer la production ».
3. Saisissez la quantité « Bon » et la quantité « Rebut », dans l’unité du produit. S’il y a du rebut, choisissez « Motif du rebut » : « Endommagé », « Refusé au contrôle qualité », « Perte de fabrication » ou « Autre ».
4. Sous « Sous-produits », saisissez la quantité de chaque sous-produit obtenu.
5. Saisissez les « Minutes de main-d’œuvre », ou laissez vide pour utiliser les minutes de la version pour la quantité bonne. Ajoutez une « Remarque » si utile.
6. Touchez « Déclarer la production ». La production bonne entre en stock, et la déclaration est listée sous « Déclarations de production » avec son coût.
7. Quand plus rien ne sera sorti ni déclaré, touchez « Clôturer l’ordre » et confirmez. Ce qui reste en cours devient l’écart.

Vous pouvez déclarer à nouveau jusqu’à la clôture. Si une production n’est pas entrée en stock, la page liste les articles.
