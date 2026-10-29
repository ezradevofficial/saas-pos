---
id: fuel-review-shifts-and-sales
title: Contrôler les services et les ventes de carburant
module: fuel
order: 36
permissions: [fuel.shift.view, fuel.sale.view]
roles: [station_manager, shift_supervisor]
screens: [/fuel/shifts, /fuel/shifts/:shiftId, /fuel/sales]
---
Lisez ce que chaque service a vendu et quelles ventes ont été signalées. Un signalement marque une vente à vérifier ; aucune vente n'est refusée.

1. Ouvrez « Services ». Filtrez par station, statut et dates « Du » et « Au ».
2. Ouvrez un service pour lire son « Résumé » et le tableau « Pistolets » : index d'ouverture et de fermeture, essais de pompe, litres au compteur, litres enregistrés et litres non enregistrés.
3. Sous « Pompistes », touchez « Ouvrir le règlement » pour lire ce que ce pompiste a remis. Voir « Approuver les écarts et les remises à zéro de compteur » pour le décider.
4. Ouvrez « Ventes de carburant » pour lire chaque vente avec ses litres, son prix, son total, son client, son véhicule et ses « Signalements ».
5. Cochez « Signalées seulement » pour lister les ventes à vérifier.

Les litres au compteur sont l'index de fermeture moins l'index d'ouverture, moins les essais de pompe, plus les remises à zéro approuvées. Les litres non enregistrés sont les litres au compteur qu'aucune vente n'explique.
