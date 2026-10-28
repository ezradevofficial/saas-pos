---
id: fuel-request-a-meter-reset
title: Demander une remise à zéro de compteur
module: fuel
order: 42
permissions: [fuel.meter.request_reset]
roles: [shift_supervisor, station_manager]
screens: [/fuel/meter-resets]
---
Demandez une remise à zéro quand un compteur de pompe a été remplacé ou est repassé à zéro. Tant qu'une remise à zéro n'est pas approuvée, les index du compteur ne peuvent pas baisser.

1. Lisez le dernier index avant le changement et le premier index après.
2. Ouvrez « Remises à zéro » et touchez « Demander une remise à zéro ».
3. Choisissez le « Pistolet ».
4. Sous « Service ouvert », choisissez le service sur lequel se trouve le pistolet, s'il y en a un d'ouvert. Les litres d'avant le changement lui sont ajoutés. Sinon, laissez « Aucun service ».
5. Saisissez l'« Index avant » et l'« Index après », tous deux en litres. L'index après ne peut pas dépasser l'index avant.
6. Saisissez le « Motif », par exemple que le compteur a été remplacé.
7. Touchez « Envoyer la demande ». La demande apparaît dans la liste avec le statut « En attente ».

Une autre personne, en général le responsable de station, l'approuve ou la rejette. Vous ne pouvez pas approuver votre propre demande. Voir « Approuver les écarts et les remises à zéro de compteur ». Si la demande est rejetée, rien ne change sur le compteur.
