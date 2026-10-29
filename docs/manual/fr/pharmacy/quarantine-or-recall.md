---
id: pharmacy-quarantine-or-recall
title: Mettre un lot en quarantaine ou émettre un rappel
module: pharmacy
order: 50
permissions: [pharmacy.lot.quarantine, pharmacy.recall.issue, pharmacy.recall.lift]
roles: [pharmacist]
screens: [/pharmacy/lots, /pharmacy/recalls, /pharmacy/lots/:lotId, /pharmacy/recalls/:recallId]
---
Empêchez la vente d'un lot douteux.

1. Pour retenir un seul lot, ouvrez-le depuis « Stock par lot », touchez « Mettre en quarantaine », donnez le « Motif » et confirmez. Touchez ensuite « Libérer » pour le revendre.
2. Pour bloquer tout un lot à tous les emplacements et dans toutes les caisses, ouvrez « Rappels » et touchez « Émettre le rappel ».
3. Choisissez l'« Article », saisissez le « Numéro de lot » et le « Motif ». Ajoutez la « Référence du régulateur » si vous l'avez.
4. Touchez « Émettre le rappel ».
5. Ouvrez le rappel et lisez « Qui l’a acheté » pour joindre les clients nommés sur les ventes.
6. Quand le lot est dégagé, ouvrez le rappel et touchez « Lever le rappel ».
