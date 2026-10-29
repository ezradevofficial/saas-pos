---
id: api-keys-rotate-revoke
title: Renouveler ou révoquer une clé d'API
module: core
order: 380
permissions: [core.api_key.manage]
roles: [owner, admin]
screens: [/settings/api-keys]
---
Renouvelez une clé pour remplacer son secret sans arrêter le système qui l'utilise. Révoquez une clé pour couper l'accès de ce système.

Avertissement : une révocation ne peut pas être annulée. Les systèmes qui utilisent la clé s'arrêtent immédiatement. Créez une nouvelle clé si vous en avez de nouveau besoin.

Pour renouveler :

1. Ouvrez Paramètres, puis « Clés d’API ».
2. Touchez « Renouveler » à côté de la clé.
3. Choisissez combien de temps l'ancien secret continue de fonctionner, sous « L’ancien secret reste actif pendant ». Cela vous laisse le temps de mettre à jour vos systèmes.
4. Touchez « Renouveler la clé ».
5. Copiez la nouvelle clé avec « Copier la clé », conservez-la, puis touchez « J’ai enregistré la clé ». Elle n'est affichée qu'une fois.

Pour révoquer :

1. Touchez « Révoquer » à côté de la clé.
2. Touchez « Révoquer la clé ».

Une clé révoquée affiche « Révoquée » dans la liste.
