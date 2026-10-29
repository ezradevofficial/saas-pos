---
id: developer-apps-submit
title: S'inscrire comme développeur et soumettre une application
module: core
order: 660
permissions: [core.developer.manage]
roles: [owner, admin]
screens: [/settings/developer-apps, /settings/developer-apps/:appId]
---
Créez des applications pour la place de marché. L'équipe de la plateforme examine chaque version avant sa mise en ligne.

1. Ouvrez Paramètres, puis « Applications développeur ».
2. La première fois, renseignez le « Nom public » et les coordonnées, acceptez les conditions de la place de marché et touchez « S’inscrire ».
3. Touchez « Nouvelle application », renseignez les détails de l'application et touchez « Créer l’application ».
4. Copiez les identifiants affichés (« Identifiant client », « Secret client » et « Secret de signature des webhooks »), conservez-les en lieu sûr, puis touchez « Je les ai copiés ». Ils ne sont affichés qu'une fois.
5. Dans la version brouillon, remplissez la « Description », l'« E-mail d’assistance », les « URI de redirection », les « Autorisations » dont l'application a besoin et sa « Tarification ». Touchez « Enregistrer le brouillon ».
6. Quand le brouillon est complet, touchez « Soumettre pour examen ».

Jusqu'à la fin de l'examen, la version affiche « En examen ». Vous pouvez toucher « Retirer de l’examen » pour la retirer. Une version refusée affiche la note de l'examinateur.

Avertissement : les secrets du client et du webhook donnent accès aux connexions de votre application. Renouveler un secret client arrête l'ancien immédiatement.
