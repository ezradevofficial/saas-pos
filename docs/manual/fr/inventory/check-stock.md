---
id: inventory-check-stock
title: Consulter les niveaux de stock
module: inventory
order: 20
permissions: [inventory.stock.view]
roles: [storekeeper, branch_manager]
screens: [/inventory/stock, /inventory/items/:itemId, /inventory/movements]
---
Voyez ce que chaque emplacement détient maintenant et comment cela a évolué.

1. Ouvrez « Stock disponible » sous « Stocks ».
2. Saisissez un code ou un nom dans « Rechercher par code ou nom d’article ». Affinez la liste avec « Société », « Agence », « Emplacement » et « Catégorie ». Choisissez « Bas uniquement » sous « Niveau de stock » pour voir les articles au niveau de réapprovisionnement ou en dessous.
3. Touchez un article pour l’ouvrir. Les onglets « Par emplacement », « Lots » et « Numéros de série » montrent où il se trouve et quels lots ou numéros de série sont en stock.
4. Ouvrez l’onglet « Mouvements », ou « Mouvements » dans la barre latérale, pour lire chaque variation de stock, la plus récente d’abord, et filtrer par « Mouvement », « Du » et « Au ».
5. Ouvrez « Seuils de réapprovisionnement » pour voir quand {{app_name}} vous prévient qu’un article est bas.

Pour enregistrer un stock que vous détenez déjà au démarrage, touchez « Ajouter un stock initial », renseignez l’article, l’emplacement, la quantité et le coût unitaire, puis touchez « Ajouter le stock initial ».
