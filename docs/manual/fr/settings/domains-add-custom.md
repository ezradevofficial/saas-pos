---
id: domains-add-custom
title: Ajouter votre propre domaine
module: core
order: 350
permissions: [core.domain.manage]
roles: [owner, admin]
screens: [/settings/domains]
---
Avertissement : un domaine personnalisé est l'adresse par laquelle votre personnel et vos clients accèdent à {{app_name}}. Le supprimer, ou un enregistrement DNS erroné, arrête immédiatement la connexion sur cette adresse et les courriels envoyés depuis celle-ci.

1. Ouvrez Paramètres, puis « Domaines ».
2. Sous « Domaines personnalisés », saisissez le nom complet dans « Domaine », sans http:// ni chemin, par exemple erp.company.co.ke.
3. Touchez « Ajouter le domaine ».
4. Le domaine affiche « En attente du DNS » avec un enregistrement : « Type », « Nom » et « Valeur ». Créez-le chez votre fournisseur de domaine. La page vérifie toutes les quelques minutes, ou touchez « Vérifier maintenant ».
5. Quand le statut est « Vérifié », faites pointer le domaine vers l'adresse indiquée avec un enregistrement CNAME.

Un certificat est émis la première fois que quelqu'un ouvre le domaine vérifié. Pour cesser d'utiliser un domaine, touchez « Retirer » et confirmez avec « Retirer le domaine ». Vous pourrez l'ajouter à nouveau plus tard.
