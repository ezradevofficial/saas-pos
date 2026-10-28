---
id: rbac-create-role
title: Créer un rôle et fixer ses limites
module: core
order: 110
permissions: [core.role.view, core.role.create, core.role.edit]
roles: [owner, admin]
screens: [/settings/roles, /settings/roles/new, /settings/roles/:roleId]
---
Les rôles système comme Caissier ou Responsable de succursale ne peuvent pas être modifiés. Copiez-en un pour faire votre propre version, ou partez de zéro.

## Copier un rôle système

1. Ouvrez Paramètres, puis « Rôles ».
2. Touchez « Copier » à côté du rôle.
3. Confirmez avec « Copier le rôle ». La copie reprend les mêmes autorisations et vous pouvez la modifier.

## Créer un rôle

1. Sur « Rôles », touchez « Créer un rôle ».
2. Saisissez le « Nom du rôle » et une « Description du rôle ».
3. Dans la grille des autorisations, cochez ce que le rôle permet. Chaque ligne est une ressource et chaque colonne une action.
4. Pour obliger les personnes ayant ce rôle à configurer la double authentification, activez « Exiger la double authentification ».
5. Touchez « Créer un rôle ».

## Fixer des limites

1. Ouvrez le rôle.
2. Sous « Limites », saisissez une valeur pour chaque limite : « Remise en caisse » (un pourcentage), « Montant de remboursement » et « Montant d'approbation » (dans la devise de base de la société). Laissez une limite vide pour n'en fixer aucune.
3. Touchez « Enregistrer les limites ».

Au-delà d'une limite, une personne ayant une limite plus élevée approuve ou autorise.

## Archiver un rôle

Touchez « Archiver », puis « Archiver le rôle ». Les personnes ayant ce rôle perdent immédiatement ses autorisations.
