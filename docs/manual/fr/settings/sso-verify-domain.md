---
id: sso-verify-domain
title: Vérifier un domaine de courriel pour l'authentification unique
module: core
order: 300
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
Seules les personnes dont l'adresse de courriel appartient à un domaine vérifié se connectent avec vos connexions d'authentification unique. Vous prouvez que le domaine est à vous avec un enregistrement DNS. Seul le propriétaire peut configurer l'authentification unique.

1. Ouvrez Paramètres, puis « Authentification unique ».
2. Sous « Domaines de messagerie », saisissez le domaine dans « Domaine de messagerie », par exemple company.co.ke, puis touchez « Ajouter le domaine ».
3. Le domaine affiche « En attente du DNS » avec un enregistrement : « Type », « Nom » et « Valeur ». Ajoutez cet enregistrement TXT chez votre fournisseur DNS.
4. Quand l'enregistrement est en place, touchez « Vérifier maintenant ». Le statut passe à « Vérifié ».

Si le statut est « Non vérifié », la ligne en dessous donne la raison : l'enregistrement n'est pas encore là, la recherche DNS a échoué, une autre organisation a vérifié le domaine avant vous, ou l'enregistrement a été supprimé. Corrigez, puis vérifiez à nouveau.

Pour retirer un domaine de l'authentification unique, touchez « Retirer » et confirmez avec « Retirer le domaine ». Les personnes de ce domaine ne peuvent plus se connecter par authentification unique. Leurs comptes restent.
