---
id: core-sign-in
title: Se connecter, réinitialiser son mot de passe et accepter une invitation
module: core
order: 20
permissions: []
roles: [owner, admin, branch_manager, cashier, employee_self_service]
screens: [/sign-in, /forgot-password, /reset-password, /invitations/:token]
---
Ces pages sont ouvertes avant la connexion.

## Se connecter

1. Ouvrez la page de connexion de {{app_name}}.
2. Saisissez votre e-mail (ou votre numéro de téléphone, si votre entreprise connecte par téléphone) et votre « Mot de passe », puis touchez « Se connecter ».
3. Si la connexion en deux étapes est activée pour vous, saisissez le code à 6 chiffres de votre application d’authentification.
4. Si votre organisation utilise l’authentification unique, touchez « Continuer avec le SSO », saisissez votre « E-mail professionnel » et touchez « Continuer vers la connexion ». La page de votre organisation s’ouvre. Pour revenir, touchez « Utiliser plutôt votre mot de passe ».

Si vous n’avez pas encore de compte, touchez « Créer un compte ». Voir « Créer votre compte ».

## Réinitialiser un mot de passe oublié

1. Sur la page de connexion, touchez « Mot de passe oublié ? ».
2. Saisissez votre adresse e-mail et touchez « Envoyer le code ». Un code à 6 chiffres arrive en une minute et vaut 30 minutes. La page répond de la même façon qu’un compte existe ou non, pour que personne ne puisse s’en servir pour savoir qui a un compte.
3. Saisissez le « Code à 6 chiffres » et un « Nouveau mot de passe » d’au moins 8 caractères, pas un mot de passe courant. Touchez « Enregistrer le mot de passe ».
4. Connectez-vous avec le nouveau mot de passe. Le changer vous déconnecte partout. Si le code n’est pas arrivé, touchez « Envoyer un nouveau code ».

## Accepter une invitation

1. Ouvrez le lien de votre e-mail d’invitation. La page affiche « Rejoignez votre équipe » et nomme l’entreprise qui vous a invité.
2. Choisissez un mot de passe et touchez « Accepter et se connecter ». Votre rôle est déjà fixé par la personne qui vous a invité.
3. Si la page indique « Cette invitation ne peut pas être utilisée », elle a expiré, déjà servi ou été retirée. Demandez-en une nouvelle à la personne qui vous a invité.
4. Si vous êtes déjà connecté sous un autre nom, la page indique « Vous êtes connecté en tant que ... ». Touchez « Se déconnecter et accepter », ou « Rester connecté » pour garder l’invitation pour plus tard.
