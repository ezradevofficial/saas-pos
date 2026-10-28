---
id: inventory-adjust-stock
title: Ajuster le stock et passer en perte
module: inventory
order: 50
permissions: [inventory.adjustment.view, inventory.adjustment.create]
roles: [storekeeper, branch_manager]
screens: [/inventory/adjustments, /inventory/adjustments/new, /inventory/adjustments/:adjustmentId]
---
Utilisez un ajustement pour diminuer le stock (casse, péremption, vol ou perte) ou l’augmenter (stock retrouvé, rectification). Il est comptabilisé une fois approuvé.

1. Ouvrez « Ajustements » sous « Stocks » et touchez « Nouvel ajustement ».
2. Choisissez l’« Emplacement » et le « Motif » : « Endommagé », « Périmé », « Vol ou perte », « Retrouvé », « Rectification », « Usage interne » ou « Autre ». Ajoutez une « Remarque » pour expliquer.
3. Sous « Lignes », choisissez l’« Article ». Dans « Changement », choisissez « Diminuer le stock » ou « Augmenter le stock ». Choisissez l’« Unité » et saisissez la « Quantité ».
4. Pour un stock que vous augmentez, saisissez le « Coût unitaire », ou laissez vide pour utiliser le coût actuel de l’article.
5. Touchez « Ajouter une ligne » pour d’autres articles, puis touchez « Demander l’ajustement ».

L’ajustement reste « En attente d’approbation » jusqu’à la décision d’un responsable dans « Approbations ». S’il ne peut pas être comptabilisé parce que le stock a changé, il affiche « Non comptabilisé » : vérifiez le stock et créez-en un nouveau. Pour retirer une demande, ouvrez-la et touchez « Annuler l’ajustement ».
