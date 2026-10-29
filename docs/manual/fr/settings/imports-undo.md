---
id: imports-undo
title: Annuler un import
module: core
order: 540
permissions: [core.import.run]
roles: [owner, admin, accountant]
screens: [/settings/imports]
---
Avertissement : annuler un import archive les enregistrements qu'il a ajoutés et rétablit les anciennes valeurs de ceux qu'il a mis à jour. Cela touche des enregistrements en service ; vérifiez les chiffres avant de confirmer.

1. Ouvrez Paramètres, puis « Importations ».
2. Ouvrez l'import terminé dans la liste.
3. Touchez « Annuler l’importation ». La fenêtre indique combien d'enregistrements ont été ajoutés et mis à jour.
4. Touchez « Annuler l’importation », ou « Garder l’importation » pour laisser les choses en l'état.

Un enregistrement désormais utilisé, ou modifié depuis l'import, n'est pas annulé. La page liste chacun avec la raison, et l'import affiche « Partiellement annulée ». Vous pouvez retenter l'annulation plus tard.

Les caisses qui ont déjà reçu des articles importés les gardent jusqu'à leur prochaine synchronisation.
