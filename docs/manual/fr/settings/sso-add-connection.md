---
id: sso-add-connection
title: Connecter un fournisseur d'identité
module: core
order: 310
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
Une connexion relie {{app_name}} à Microsoft Entra ID, Google Workspace ou un autre fournisseur d'identité SAML 2.0. Vérifiez d'abord votre domaine de courriel (voir « Vérifier un domaine de courriel pour l'authentification unique »).

1. Ouvrez Paramètres, puis « Authentification unique ».
2. Sous « Connexions », touchez « Ajouter une connexion ».
3. Choisissez le « Fournisseur d’identité » et donnez un « Nom » à la connexion. Ce nom s'affiche sur la page de connexion quand il y a plusieurs connexions.
4. Remplissez les champs du fournisseur :
   - Microsoft Entra ID : « ID d’application (client) », « Secret client » et « ID de l’annuaire (locataire) ».
   - Google Workspace : « ID d’application (client) », « Secret client » et, si vous le souhaitez, « Domaine Workspace ».
   - SAML 2.0 : l'« URL des métadonnées », ou collez le XML des métadonnées, ou saisissez vous-même « ID d’entité du fournisseur d’identité », « URL de connexion du fournisseur d’identité » et « Certificat de signature ».
5. Touchez « Enregistrer la connexion ». Le secret client est stocké chiffré et n'est plus jamais affiché.
6. La connexion affiche « Valeurs pour votre fournisseur d’identité » : « URI de redirection » et, pour SAML, « Identifiant (ID d’entité) » et « URL de réponse (ACS) ». Saisissez-les chez votre fournisseur d'identité.
7. Touchez « Tester la connexion » et connectez-vous chez votre fournisseur d'identité. Le résultat indique qui s'est connecté et ce qui arriverait à cette personne à sa première connexion.
8. Quand le test réussit, touchez « Activer ».

Une connexion jamais testée affiche « Pas encore testée. Testez-la avant de l’activer. ». Pour modifier une connexion plus tard, utilisez « Modifier ». « Désactiver » l'arrête sans la supprimer.
