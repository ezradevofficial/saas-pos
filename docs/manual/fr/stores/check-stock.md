---
id: stores-check-stock
title: Consulter et saisir le stock du magasin
module: stores
order: 50
permissions: [stores.ledger.view, stores.stock.adjust]
roles: [storekeeper, branch_manager, owner, admin]
screens: [/stores/stock]
---
« Stock en magasin » montre ce que chaque magasin détient, article par article.

1. Ouvrez « Stock en magasin » sous « Magasins ».
2. Cherchez avec « Rechercher par article » et filtrez par « Magasin ». Chaque ligne montre l’article, le magasin, « En stock » et le « Dernier mouvement ».
3. Touchez « Exporter » et choisissez « Excel (.xlsx) », « CSV » ou « PDF ».

Quand le module Stocks est actif, il tient le stock des magasins : saisissez le stock initial et les ajustements dans Stocks. La page l’indique.

Quand le module Stocks est désactivé et que vous pouvez ajuster le stock, « Saisir du stock » apparaît au-dessus de la liste :

1. Choisissez le « Magasin » et la « Saisie » : « Stock initial » ou « Ajustement ».
2. Pour un ajustement, choisissez le « Sens » (« Ajouter au stock » ou « Retirer du stock ») et le « Motif » : « Compté », « Endommagé », « Périmé », « Retrouvé », « Rectification » ou « Autre ».
3. Recherchez et choisissez l’« Article », l’« Unité », et saisissez la « Quantité ».
4. Touchez « Saisir le stock ». Le stock ne passe jamais sous zéro. Retirer du stock peut aussi demander une personne autorisée à approuver les diminutions de stock.
