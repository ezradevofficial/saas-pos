---
id: pos-review-shifts
title: Contrôler les sessions et les espèces
module: pos
order: 130
permissions: [pos.shift.view]
roles: [branch_manager]
screens: [/pos/shifts, /pos/shifts/:shiftId]
---
Vérifiez les espèces de chaque caisse après la clôture de la session par le caissier.

1. Ouvrez « Sessions de caisse » sous « Point de vente ».
2. Utilisez « Rechercher une caisse ou un caissier », ou les filtres « Statut », « Ouverte du » et « Ouverte au ».
3. Lisez « Écart » pour chaque session. Il indique « juste », « Excédent de » ou « Manque de », par devise. Une session non comptée affiche « Non compté ».
4. Ouvrez une session pour voir « Espèces par devise » : « Fonds de caisse », « Attendu », « Compté » et « Écart ».
5. Sous « Mouvements d’espèces », vérifiez chaque entrée et chaque sortie : le montant, le « Motif » et la personne qui l’a accordée.

Les espèces attendues sont le fonds de caisse plus les espèces encaissées, moins la monnaie rendue et les remboursements en espèces, plus les entrées et moins les sorties. Les enregistrements arrivés au serveur après la clôture sont inclus, et une note sur la session le signale.
