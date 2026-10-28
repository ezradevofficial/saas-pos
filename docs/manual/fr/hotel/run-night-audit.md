---
id: hotel-run-night-audit
title: Lancer l'audit de nuit
module: hotel
order: 50
permissions: [hotel.audit.view, hotel.audit.run, hotel.charge.resolve]
roles: [night_auditor]
screens: [/hotel/night-audit, /hotel/charge-exceptions]
---
L'audit enregistre les charges de chambre de la nuit, signale les non-présentations et fait passer l'établissement à la date d'exploitation suivante.

1. Ouvrez « Audit de nuit ». La page affiche la date d'exploitation.
2. Ouvrez d'abord « Charges de chambre à résoudre ». Pour chaque charge, touchez « Résoudre », choisissez « Comment la résoudre », ajoutez une « Note » et confirmez.
3. De retour sur « Audit de nuit », touchez « Lancer l'audit de nuit », puis « Lancer l'audit ».
4. Lisez « Instantané de la nuit » : occupation, non-présentations, arrivées, départs et revenu des chambres. Les problèmes éventuels sont comptés en haut.

L'audit clôture la date et ne peut pas être lancé deux fois pour elle. Il peut aussi s'exécuter selon un planning ; « Audits de nuit » liste les audits passés et leur auteur.
