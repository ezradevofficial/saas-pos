---
id: core-follow-document-flow
title: Suivre un document dans son workflow
module: core
order: 150
permissions: [core.workflow.view]
roles: [owner, admin]
screens: [/document-workflows/:documentType/:documentId, /settings/workflows/insights]
---
Chaque document qui suit un workflow a une page d’état. Les liens des notifications et du journal d’exécution des automatisations l’ouvrent.

1. Sur la page, lisez le « Statut » (« En cours », « Terminé » ou « Annulé »), la date de début (« Démarré ») et la « Version du workflow ».
2. Sous « Où il en est », lisez l’étape ouverte, entre les mains de qui elle est, depuis combien de temps le document est « Dans cette étape depuis » et l’« Échéance » ou le retard (« En retard depuis »).
3. Touchez « Ouvrir l’approbation » pour décider, ou « Ouvrir le document » pour le lire.
4. Sous « Historique », lisez chaque étape dans l’ordre : arrivé, sorti, renvoyé, ignoré, relancé ou escaladé.
5. Si vous pouvez agir sur le workflow, « Passer à la suite », « Renvoyer à une étape précédente » et « Annuler le workflow » apparaissent. Renvoyer demande un « Motif du renvoi ». Annuler ferme les étapes ouvertes et est définitif.

Pour voir comment les documents avancent dans tous les workflows en vigueur, ouvrez « Volumes par étape » depuis la liste des workflows. Choisissez un « Type de document », une « Société » et des dates. Pour chaque étape, vous voyez combien de documents sont « En ce moment » dedans, combien sont « Entrés » et « Sortis », la « Durée médiane », les « 10 % les plus lents » et combien sont « En retard ». Les durées sont du temps écoulé, pas des heures ouvrées, et ne comptent que les documents que vous pouvez voir.
