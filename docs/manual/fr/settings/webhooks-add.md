---
id: webhooks-add
title: Ajouter un webhook
module: core
order: 390
permissions: [core.webhook.manage]
roles: [owner, admin]
screens: [/settings/webhooks]
---
Un webhook envoie des événements de {{app_name}} à un autre système au moment où ils se produisent. Chaque événement est signé pour que ce système puisse vérifier qu'il vient d'ici.

Avertissement : les événements quittent {{app_name}} vers l'adresse que vous saisissez. Utilisez uniquement une adresse https:// que vous contrôlez et n'envoyez que les événements dont ce système a besoin. Le secret de signature n'est affiché qu'une fois.

1. Ouvrez Paramètres, puis « Webhooks ».
2. Touchez « Ajouter un webhook ».
3. Saisissez l'« URL de destination », une adresse https:// publique. Ajoutez une « Description » pour que votre équipe sache ce qui reçoit les événements.
4. Sous « Événements », cochez les événements à envoyer. Seuls les événements des modules que vous utilisez sont listés.
5. Sous « Envoyer les événements de », choisissez « Toute l’organisation », « Une société » ou « Une agence ».
6. Touchez « Ajouter le webhook ».
7. Touchez « Copier le secret », conservez le secret dans le système récepteur, puis touchez « J’ai enregistré le secret ». Il n'est affiché qu'une fois.

Ouvrez l'onglet « Guide du développeur » pour voir comment le système récepteur vérifie la signature et ce qui se passe en cas d'échec.
