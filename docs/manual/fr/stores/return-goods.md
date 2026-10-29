---
id: stores-return-goods
title: Retourner des articles au magasin
module: stores
order: 30
permissions: [stores.issue.view, stores.return.view, stores.return.create]
roles: [storekeeper, branch_manager, owner]
screens: [/stores/returns, /stores/issues/:issueId, /stores/returns/:returnId]
---
Les articles qu’un service n’utilise pas retournent au magasin, contre la sortie dont ils viennent.

1. Ouvrez « Sorties » sous « Magasins » et ouvrez la sortie.
2. Touchez « Retourner des articles ».
3. Pour chaque ligne, saisissez dans « Retourner » la quantité qui revient, dans l’unité de la ligne. La fenêtre indique combien ont été sortis. Laissez une ligne vide pour ne rien retourner.
4. Choisissez le « Motif » : « Non utilisé », « Plus que nécessaire », « Mauvais article », « Endommagé » ou « Autre ». Ajoutez une « Remarque » si utile.
5. Touchez « Retourner au magasin ». Les articles reviennent en stock sous un nouveau numéro de retour.

Ouvrez « Retours » sous « Magasins » pour voir tous les retours, avec leur sortie, leur motif et qui les a retournés. Ouvrez un retour pour lire ses lignes. Le rapport de consommation d’un service compte les sorties moins les retours.
