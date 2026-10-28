---
id: inventory-transfer-stock
title: Transférer du stock entre emplacements
module: inventory
order: 30
permissions: [inventory.transfer.view, inventory.transfer.create, inventory.transfer.dispatch, inventory.transfer.receive]
roles: [storekeeper, branch_manager]
screens: [/inventory/transfers, /inventory/transfers/new, /inventory/transfers/:transferId]
---
Un transfert déplace du stock entre deux emplacements de la même société. Il est soumis à approbation avant l’expédition.

1. Ouvrez « Transferts » sous « Stocks » et touchez « Nouveau transfert ».
2. Choisissez l’« Emplacement de départ » et l’« Emplacement d’arrivée », et ajoutez une « Remarque » si utile.
3. Sous « Lignes », choisissez l’« Article », l’« Unité » et la « Quantité ». Choisissez le « Lot » ou saisissez les « Numéros de série » si l’article les demande. Touchez « Ajouter une ligne » pour d’autres articles.
4. Touchez « Demander le transfert ».
5. Quand le transfert est approuvé, ouvrez-le et touchez « Expédier ». Le stock est maintenant en transit.
6. À l’emplacement d’arrivée, ouvrez le transfert et touchez « Réceptionner ». Saisissez ce qui est arrivé pour chaque article et touchez « Réceptionner le stock ».
7. Si moins est arrivé que ce qui a été envoyé, saisissez le « Motif de la perte ». La différence est passée en perte en transit.

Pour arrêter un transfert, touchez « Annuler le transfert » et donnez un motif. Le stock reste où il est.
