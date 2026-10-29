---
id: mfg-new-bom-version
title: Modifier une nomenclature avec une nouvelle version
module: manufacturing
order: 2
permissions: [manufacturing.bom.view, manufacturing.bom.manage]
roles: [admin, owner]
screens: [/manufacturing/boms, /manufacturing/boms/:bomId, /manufacturing/bom-versions/:versionId]
---
Une version publiée ne change jamais. Pour modifier une nomenclature, créez une nouvelle version et publiez-la. Les nouveaux ordres et les ventes l’utilisent à partir de sa date ; les ordres déjà planifiés gardent leur version.

1. Ouvrez « Nomenclatures et recettes » sous « Fabrication » et ouvrez la nomenclature.
2. Sous « Versions », lisez la version « En vigueur » et la date depuis laquelle elle s’applique.
3. Touchez « Nouvelle version ». Choisissez « Copier depuis » une version, ou « Partir de zéro », et touchez « Créer la version ».
4. Touchez « Ouvrir le brouillon ». Changez les lignes, la quantité « Un lot produit » ou les « Minutes de main-d’œuvre par lot ». Utilisez les flèches pour monter ou descendre une ligne et « Retirer » pour en supprimer une.
5. Touchez « Enregistrer le brouillon ». Enregistrez avant de publier : la page vous le demande.
6. Touchez « Publier ». Choisissez « En vigueur à partir du ». La date doit suivre celle de la dernière version publiée ; vide signifie maintenant. Confirmez.

Pour cesser d’utiliser une nomenclature, touchez « Archiver » sur sa page et confirmez. Les ordres déjà planifiés gardent leur version, et une nouvelle nomenclature de l’article pourra être créée ensuite. Une nomenclature archivée ne prend plus de nouvelle version.
