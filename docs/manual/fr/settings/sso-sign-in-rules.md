---
id: sso-sign-in-rules
title: Exiger l'authentification unique et choisir le rôle par défaut
module: core
order: 320
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
Avertissement : quand vous exigez l'authentification unique, les personnes de vos domaines vérifiés ne peuvent plus se connecter avec un mot de passe. Si le fournisseur d'identité ou la connexion est mal réglé, elles sont bloquées. Les propriétaires gardent leur mot de passe et leur connexion en deux étapes pour revenir.

1. Ouvrez Paramètres, puis « Authentification unique ».
2. Vérifiez qu'au moins une connexion est activée et testée.
3. Sous « Règles de connexion », activez « Exiger l’authentification unique » si tout le monde sur vos domaines doit utiliser l'authentification unique. Personne ne peut alors créer un nouveau compte avec un mot de passe.
4. Activez « Créer les comptes à la première connexion » si une personne sans compte ni invitation doit en obtenir un à sa première connexion. Choisissez le « Rôle par défaut des nouveaux comptes » qu'elle reçoit. Quand l'option est désactivée, seuls les utilisateurs existants et les personnes invitées peuvent se connecter.
5. Touchez « Enregistrer les règles de connexion ». La page confirme « Règles de connexion enregistrées. »

Les propriétaires lient leur propre compte sous Paramètres, puis « Sécurité », avec le bouton « Lier mon compte avec » suivi du nom de la connexion. Ils ne sont jamais liés par courriel.
