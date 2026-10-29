---
id: pharmacy-move-stock
title: Déplacer du stock entre emplacements
module: pharmacy
order: 25
permissions: [pharmacy.lot.transfer, pharmacy.lot.view]
roles: [pharmacist, pharmacy_technician]
screens: [/pharmacy/transfers/new]
---
Déplacez des lots entre deux emplacements de la même société. Les lots quittent l'origine selon la péremption la plus proche.

1. Ouvrez « Stock par lot » et touchez « Déplacer du stock ».
2. Choisissez l'emplacement dans « De » et celui dans « Vers ».
3. Sous « Articles à déplacer », choisissez chaque article et saisissez sa « Quantité ». Ajoutez une ligne par article supplémentaire.
4. Lisez « Prélevé sur » pour voir quels lots vont bouger. Si seule une partie est en stock, la page indique ce qui manque.
5. Touchez « Déplacer le stock ». Le message « Stock déplacé. » le confirme.

Un lot périmé, en quarantaine ou rappelé ne peut pas être déplacé.
