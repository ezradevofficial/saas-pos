---
id: security-require-two-factor
title: Exiger la double authentification pour un rôle
module: core
order: 170
permissions: [core.role.view, core.role.edit]
roles: [owner, admin]
screens: [/settings/roles, /settings/roles/:roleId]
---
La double authentification demande un second code après le mot de passe. Vous l'activez par rôle. Les rôles système ne se modifient pas : copiez-en un d'abord si vous voulez une version modifiée.

1. Ouvrez Paramètres, puis « Rôles ».
2. Ouvrez le rôle. S'il s'agit d'un rôle système, touchez « Copier » et confirmez avec « Copier le rôle », puis ouvrez la copie.
3. Activez « Exiger la double authentification ».
4. La page confirme « La double authentification est désormais exigée pour ce rôle. »

Les personnes ayant ce rôle doivent configurer la double authentification avant de continuer. Voir « Configurer la connexion en deux étapes ». Pour la désactiver, désactivez le réglage. La liste « Rôles » affiche « Obligatoire » dans la colonne « Double authentification ».

La liste « Utilisateurs » affiche « Activée » ou « Désactivée » dans la colonne « Double authentification » pour chaque personne.
