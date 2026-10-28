---
id: inventory-count-stock
title: Faire un inventaire
module: inventory
order: 40
permissions: [inventory.count.view, inventory.count.create, inventory.count.count]
roles: [storekeeper, branch_manager]
screens: [/inventory/counts, /inventory/counts/new, /inventory/counts/:countId]
---
Un inventaire compare ce qui est en rayon avec le stock enregistré. Lancer un inventaire gèle les articles comptés : leurs ajustements et transferts attendent la fin du comptage. Les ventes continuent.

1. Ouvrez « Inventaires » sous « Stocks » et touchez « Nouvel inventaire ».
2. Choisissez l’« Emplacement » et le « Type d’inventaire » : « Inventaire complet » ou « Inventaire tournant ».
3. Pour un inventaire tournant, choisissez « Articles choisis » ou « Une catégorie ». Ajoutez chaque article avec « Ajouter un article », ou choisissez la « Catégorie ».
4. Touchez « Lancer l’inventaire ».
5. Comptez le rayon. Sur l’inventaire, saisissez chaque « Quantité comptée de » l’article. Touchez « Enregistrer les comptages » au fur et à mesure.
6. Quand chaque ligne est comptée, touchez « Soumettre l’inventaire ».

S’il n’y a aucun écart, l’inventaire est comptabilisé. Sinon, il est soumis à approbation et le stock change une fois l’écart approuvé. Pour abandonner un inventaire, touchez « Annuler l’inventaire » et donnez un motif ; les articles sont dégelés.
