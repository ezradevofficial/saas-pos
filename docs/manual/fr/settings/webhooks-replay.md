---
id: webhooks-replay
title: Suivre et rejouer les livraisons de webhooks
module: core
order: 410
permissions: [core.webhook.manage]
roles: [owner, admin]
screens: [/settings/webhooks]
---
Le journal des livraisons montre chaque événement envoyé, la réponse et le nombre de tentatives.

1. Ouvrez Paramètres, puis « Webhooks ».
2. Ouvrez l'onglet « Journal des livraisons ».
3. Filtrez par « Statut » ou par type d'événement. Chaque ligne montre l'heure, l'événement, le point de terminaison, le statut, la réponse et les tentatives.
4. Ouvrez une livraison échouée pour voir la réponse et le corps envoyé.
5. Quand le système récepteur fonctionne de nouveau, touchez « Relancer la livraison ». La livraison est remise en file avec le même identifiant d'événement, ce qui permet à l'autre système de l'ignorer s'il l'a déjà.

Les livraisons sont conservées un nombre limité de jours ; l'onglet indique combien.
