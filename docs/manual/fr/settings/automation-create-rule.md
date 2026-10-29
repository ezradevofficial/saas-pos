---
id: automation-create-rule
title: Créer une règle d'automatisation
module: core
order: 850
permissions: [core.automation.view, core.automation.edit]
roles: [owner, admin]
screens: [/settings/automation-rules]
---
Une règle d'automatisation agit d'elle-même quand quelque chose arrive à un document : elle peut envoyer une notification, mettre à jour un champ, changer d'étape, assigner une personne, créer un document, bloquer le crédit ou appeler un webhook.

Avertissement : une règle agit sans demander. Une action qui appelle un webhook envoie des données hors de {{app_name}}, à une adresse https:// que vous saisissez. Testez d'abord la règle et enregistrez-la désactivée tant que vous n'êtes pas sûr.

1. Ouvrez Automatisation, puis « Règles d’automatisation ».
2. Touchez « Créer une règle ». Pour partir d'une règle prête à l'emploi, utilisez plutôt « Partir d’un modèle » ; elle est enregistrée désactivée.
3. Saisissez le « Nom » et choisissez le « Type de document » et, si besoin, la « Société ». Le type de document ne peut plus changer après la création de la règle.
4. Sous « Quand », choisissez le « Déclencheur », par exemple « Enregistrement créé », « Entrée dans une étape », « Date atteinte » ou « Planification », et remplissez ses détails.
5. Sous « Seulement si », ajoutez des conditions si la règle ne doit agir que pour certains documents.
6. Sous « Alors », touchez « Ajouter l’action » pour chaque chose que la règle fait. Elles s'exécutent dans l'ordre affiché.
7. Lisez « En clair » pour vérifier que la règle dit ce que vous voulez.
8. Ouvrez l'onglet « Tester » et touchez « Lancer le test » sur des valeurs d'exemple ou un document réel. Rien n'est modifié.
9. Touchez « Enregistrer désactivée », puis « Activer » quand vous êtes prêt.

Une règle qui appelle un webhook reçoit un secret de signature, affiché une fois. Copiez-le avec « Copier le secret » et donnez-le au système récepteur.
