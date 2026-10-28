---
id: organisation-pair-device
title: Ajouter et associer une caisse
module: core
order: 70
permissions: [core.device.view, core.device.create, core.device.pair]
roles: [owner, admin]
screens: [/settings/organisation, pos:PairingScreen]
---
Une caisse est un appareil associé à un emplacement avec un code à usage unique.

## Dans le back-office

1. Ouvrez Paramètres, puis « Organisation ».
2. Sur l'emplacement, touchez « Appareils ».
3. Touchez « Ajouter un appareil ».
4. Saisissez le « Nom de l'appareil » que les gens reconnaissent, comme Caisse 1.
5. Touchez « Ajouter et obtenir un code ».
6. Notez le « Code d'association ». Vous ne le reverrez pas. Il fonctionne une seule fois et expire après 15 minutes. S'il expire, touchez « Obtenir un code d'association » pour en avoir un nouveau.

Pour définir le « Code de l'appareil » (jusqu'à 10 majuscules ou chiffres, comme T01, imprimé dans les numéros de ticket), touchez « Modifier » à côté de l'appareil.

## Sur la caisse

1. Ouvrez l'application {{app_name}}. Elle affiche « Associer cette caisse ».
2. Saisissez le « Code d'association ». Il compte 8 lettres et chiffres.
3. Saisissez le « Nom de la caisse ».
4. Touchez « Associer la caisse ».

La caisse indique ensuite pour quel emplacement elle vend. Le personnel peut se connecter dès qu'elle a synchronisé.
