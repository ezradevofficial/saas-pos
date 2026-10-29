---
id: mfg-create-bom
title: Créer une nomenclature ou une recette
module: manufacturing
order: 1
permissions: [manufacturing.bom.view, manufacturing.bom.manage]
roles: [admin, owner]
screens: [/manufacturing/boms, /manufacturing/boms/new, /manufacturing/boms/:bomId, /manufacturing/bom-versions/:versionId]
---
Une nomenclature liste de quoi un produit est fait. Une recette fait de même pour un plat ou une boisson vendus en caisse.

1. Ouvrez « Nomenclatures et recettes » sous « Fabrication » et touchez « Nouvelle nomenclature ».
2. Choisissez la « Société » et le « Type » : « Nomenclature de production » pour un produit fabriqué pour le stock par des ordres de fabrication (l’article est stocké), ou « Recette » pour ce qui est préparé à la commande et consommé à la vente en caisse (un kit ou un article non stocké).
3. Choisissez l’« Article fabriqué » et saisissez un « Nom ».
4. Sous « Première version », saisissez « Un lot produit » et choisissez l’« Unité produite ». Les quantités des lignes sont pour cette production. Saisissez les « Minutes de main-d’œuvre par lot » et une « Remarque » si vous le souhaitez.
5. Touchez « Ajouter un composant » pour chaque matière. Choisissez l’« Article », l’« Unité » et la « Quantité ». Ajoutez « Rendement % » ou « Perte % » si une part est perdue. Cochez « Utiliser sa recette » pour éclater un composant dans sa propre nomenclature au lieu de le prendre en stock.
6. Touchez « Ajouter un sous-produit » pour tout ce que le lot produit en plus : choisissez l’article et la quantité, et la « Part du coût % ».
7. Touchez « Créer la nomenclature ». Sa première version s’ouvre en brouillon.
8. Vérifiez les lignes. Sous « Éclatement », saisissez une quantité dans « Pour » pour voir ce que la version consomme, jusqu’aux articles pris en stock, et son coût aux coûts actuels du stock.
9. Touchez « Enregistrer le brouillon », puis « Publier ». Choisissez « En vigueur à partir du », ou laissez vide pour une entrée en vigueur immédiate, et confirmez. Une version publiée ne change plus.

Un article n’a qu’une nomenclature à la fois. Pour chercher, utilisez « Rechercher par article ou nom » et filtrez par type et par « Actives » ou « Archivées ».
