---
id: workflows-create-publish
title: Changer l'approbation d'un document et la publier
module: core
order: 830
permissions: [core.workflow.view, core.workflow.edit, core.workflow.publish]
roles: [owner, admin]
screens: [/settings/workflows]
---
Un flux de travail définit les étapes, approbations et actions par lesquelles passe un type de document, par société. Modifiez un brouillon, testez-le, puis publiez-le. Rien ne change pour les documents avant la publication.

1. Ouvrez Automatisation, puis « Workflows ».
2. Touchez « Créer un workflow ». Choisissez le « Type de document » et la « Société », puis touchez « Créer et ouvrir ». Le flux démarre comme une copie brouillon du flux par défaut. Pour en modifier un existant, ouvrez-le depuis la liste.
3. Ajoutez des étapes depuis « Glissez sur le flux » : une étape, une approbation, une condition, des étapes parallèles, une notification, un document à créer et une fin. Faites glisser une étape sur le flux et reliez-la à la suivante.
4. Sélectionnez une étape pour la modifier dans « Réglages de l’étape ». Pour une approbation, choisissez qui approuve (« Qui approuve ») et ce qui se passe si plusieurs personnes le peuvent (« Si plusieurs personnes peuvent approuver »). Le demandeur ne peut jamais approuver son propre document.
5. Touchez « Rechercher les problèmes » pour trouver les problèmes. Corrigez ceux listés sous « Problèmes à corriger avant la publication ».
6. Touchez « Tester avec un exemple », saisissez des valeurs d'exemple et la décision à chaque approbation, puis touchez « Lancer le test » pour voir le chemin suivi. Rien n'est enregistré ni envoyé.
7. Le brouillon s'enregistre tout seul (« Brouillon enregistré »). Quand il n'a aucun problème, touchez le bouton « Publier » avec le numéro de la prochaine version, puis confirmez.

Les nouveaux documents suivent ensuite la version publiée. Les documents déjà en cours restent sur la version avec laquelle ils ont commencé.
