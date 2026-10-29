---
id: fuel-check-tank-dips
title: Contrôler et enregistrer les jaugeages depuis le bureau
module: fuel
order: 32
permissions: [fuel.dip.view, fuel.dip.record]
roles: [shift_supervisor, station_manager]
screens: [/fuel/dips]
---
Lisez les jaugeages de chaque cuve et enregistrez-en un depuis le back-office. Un jaugeage est converti en litres par le barème en vigueur.

1. Ouvrez « Jaugeages des cuves ». Filtrez par station, cuve, type et dates « Du » et « Au ».
2. Touchez « Enregistrer un jaugeage ».
3. Choisissez la « Cuve ». Saisissez le « Jaugeage » lu sur la jauge, en cm. Saisissez l'« Eau » en cm si vous l'avez mesurée. Laissez « Relevé le » vide pour maintenant.
4. Touchez « Enregistrer le jaugeage ». Le serveur affiche les litres et la suite :
   - le premier jaugeage d'une cuve fixe seulement sa référence ;
   - un écart dans la tolérance est accepté et la référence se déplace sur ce jaugeage ;
   - un écart hors tolérance attend un responsable de station. Vous ne pouvez pas approuver votre propre jaugeage.
5. Si le jaugeage est rapproché, touchez « Ouvrir le rapprochement ».

La cuve doit d'abord avoir un barème. Voir « Configurer la station ». En caisse, utilisez « Enregistrer un jaugeage de cuve ».
