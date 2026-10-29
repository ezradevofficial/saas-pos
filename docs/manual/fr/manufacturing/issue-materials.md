---
id: mfg-issue-materials
title: Sortir des matières pour un ordre de fabrication et reprendre ce qui reste
module: manufacturing
order: 4
permissions: [manufacturing.order.view, manufacturing.material.issue, manufacturing.material.return]
roles: [storekeeper, branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/:orderId]
---
Les matières quittent le stock pour l’ordre quand vous les sortez, et y reviennent au même coût quand vous les rendez.

1. Ouvrez « Ordres de fabrication » sous « Fabrication » et ouvrez un ordre lancé. « Matières » montre pour chacune ce qui est « Requis », « Sorti », « Retourné », « Consommé » et « Reste à sortir ».
2. Touchez « Sortir des matières ».
3. Saisissez la quantité de chaque matière, dans son unité de base. La fenêtre indique ce qui reste à sortir. Plus que requis est permis et apparaît comme un écart. Laissez une ligne vide pour ne rien sortir.
4. Ajoutez une « Remarque » si utile et touchez « Sortir les matières ». La sortie est listée sous « Sorties ».
5. Pour rendre ce qui n’a pas servi, touchez « Retourner des matières ». Choisissez la « Sortie », saisissez la quantité de chaque matière (jusqu’à ce qui a été sorti moins retourné) et choisissez le « Motif » : « Non utilisé », « Plus que nécessaire », « Mauvais article » ou « Autre ». Touchez « Retourner les matières ».

Les retours sont listés sous « Retours » et reviennent au coût de leur sortie.
