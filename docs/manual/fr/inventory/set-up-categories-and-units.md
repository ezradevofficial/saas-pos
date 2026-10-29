---
id: inventory-set-up-categories-and-units
title: Configurer les catégories et les unités d’articles
module: inventory
order: 12
permissions: [core.item_category.view, core.item_category.create, core.item_category.edit, core.item_category.archive, core.uom.view, core.uom.edit]
roles: [storekeeper, admin, owner]
screens: [/catalogue/categories, /catalogue/units]
---
Les catégories regroupent les articles en arborescence. Les unités sont les mesures dans lesquelles on compte et vend les articles. Configurez les deux avant d’ajouter des articles.

Catégories

1. Ouvrez « Catégories » sous « Catalogue ».
2. Touchez « Ajouter une catégorie ». Choisissez la « Société », saisissez le « Nom » et, pour une sous-catégorie, choisissez le « Parent ». « Aucun (premier niveau) » crée une catégorie de premier niveau.
3. Touchez « Ajouter une catégorie » pour enregistrer.
4. Pour ajouter une branche sous une catégorie, touchez « Ajouter une sous-catégorie » sur sa ligne. Touchez « Modifier » pour la renommer ou la déplacer.
5. Pour retirer une catégorie, touchez « Archiver », puis « Archiver la catégorie ». Elle n’est plus proposée pour les nouveaux articles ; ses articles la gardent. Une catégorie ayant des sous-catégories actives ne peut pas être archivée : déplacez-les ou archivez-les d’abord.
6. Cochez « Afficher les catégories archivées » pour retrouver une catégorie archivée, puis touchez « Restaurer ».

Unités

1. Ouvrez « Unités » sous « Catalogue ». Cherchez par « Code ou nom ».
2. Touchez « Ajouter une unité ». Saisissez le « Code » (jusqu’à 10 majuscules, chiffres ou _, comme CARTON12) et le « Nom », et choisissez la « Nature » : « Quantité », « Poids », « Volume », « Longueur » ou « Durée ».
3. Touchez « Ajouter une unité » pour enregistrer.
4. Touchez « Modifier » pour changer une unité, « Archiver » pour la retirer ou « Restaurer » pour la remettre. Les unités sont communes à toutes les sociétés : un changement s’applique à toutes. Une unité utilisée par des articles actifs ne peut pas être archivée : modifiez d’abord ces articles.

Touchez « Historique » sur une ligne pour voir qui l’a modifiée et quand. Pour utiliser une catégorie ou une unité sur un article, voir « Ajouter un article avec ses unités ».
