---
id: workflows-copy-rollback
title: Copier un flux vers une autre société ou revenir à une version antérieure
module: core
order: 840
permissions: [core.workflow.view, core.workflow.edit, core.workflow.publish]
roles: [owner, admin]
screens: [/settings/workflows]
---
1. Ouvrez Automatisation, puis « Workflows ».
2. Ouvrez le flux dans la liste.
3. Pour le copier, ouvrez « Plus », puis « Copier vers une autre société ». Choisissez la société et si vous copiez la version en service ou le brouillon, puis touchez « Copier le workflow ». La copie devient le brouillon de cette société. Son flux en service ne change pas tant que quelqu'un ne publie pas le brouillon.
4. Pour revenir à une version antérieure, ouvrez « Versions », choisissez la version et touchez son bouton de retour en arrière. Une copie de cette version est publiée comme nouvelle version en service.
5. Pour repartir du flux par défaut du type de document, ouvrez « Plus », puis « Rétablir le modèle par défaut ». Cela remplace seulement le brouillon ; la version en service continue jusqu'à votre publication.
6. Pour abandonner les modifications non publiées, ouvrez « Plus », puis « Abandonner le brouillon ».

Utilisez « Volumes par étape » pour voir combien de documents se trouvent à chaque étape.
