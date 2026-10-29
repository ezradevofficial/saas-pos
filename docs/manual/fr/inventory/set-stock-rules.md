---
id: inventory-set-stock-rules
title: Choisir comment le stock est valorisé
module: inventory
order: 29
permissions: [inventory.stock.view, inventory.settings.edit]
roles: [branch_manager, admin, owner]
screens: [/inventory/settings]
---
Chaque société choisit comment son stock est valorisé et si le stock peut passer sous zéro.

1. Ouvrez « Paramètres des stocks » sous « Stocks ». Si vous voyez « Choisissez une société dans le sélecteur pour voir ses paramètres. », choisissez une société dans le sélecteur.
2. Sous « Méthode de valorisation », choisissez « Coût moyen pondéré » ou « Premier entré, premier sorti (PEPS) ». Les coûts sont tenus dans la devise de base de la société.
3. Activez « Autoriser le stock négatif » si les ajustements, transferts et inventaires peuvent faire passer le stock sous zéro. Les ventes passent toujours, quoi que ce réglage dise.
4. Touchez « Enregistrer les paramètres ».

Dès que la société a des mouvements de stock, vous voyez « La méthode de valorisation ne peut plus changer ». Elle est fixée : choisissez-la avant de recevoir du stock. Si vous voyez « Vous pouvez voir ces paramètres mais pas les modifier. », demandez à un propriétaire ou à un administrateur.
