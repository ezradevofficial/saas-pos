---
id: webhooks-test-rotate
title: Tester un webhook et renouveler son secret
module: core
order: 400
permissions: [core.webhook.manage]
roles: [owner, admin]
screens: [/settings/webhooks]
---
Avertissement : un événement de test et un nouveau secret atteignent le système extérieur. Le renouvellement garde l'ancien secret actif quelques heures ; mettez à jour le système récepteur avant la fin de ce délai.

1. Ouvrez Paramètres, puis « Webhooks ».
2. Ouvrez le webhook dans la liste.
3. Touchez « Envoyer un événement de test ». La réponse de l'autre système apparaît dans le journal des livraisons de la page.
4. Pour changer le secret de signature, touchez « Renouveler le secret », puis « Renouveler le secret ». Copiez le nouveau secret avec « Copier le secret » et touchez « J’ai enregistré le secret ».
5. Pour mettre le webhook en pause, touchez « Désactiver ». Touchez « Activer » pour reprendre.
6. Pour l'arrêter définitivement, touchez « Archiver », puis « Archiver le webhook ». Plus aucun événement n'est envoyé. Son journal des livraisons est conservé 30 jours.

Un webhook qui a échoué plusieurs fois de suite affiche « Désactivé après des échecs ». Vérifiez que le système récepteur fonctionne, puis réactivez le webhook.
