---
id: data-export-request
title: Exporter toutes vos données
module: core
order: 420
permissions: [core.tenant.export]
roles: [owner]
screens: [/settings/data-export]
---
L'export est une copie complète des données de votre organisation, lisible par d'autres systèmes. Seul le propriétaire peut le demander.

Avertissement : le fichier contient toutes les données de votre entreprise, y compris clients, personnel et finances. Conservez-le en lieu sûr, ne le partagez avec personne qui ne doive pas tout voir, et supprimez votre copie quand vous n'en avez plus besoin.

1. Ouvrez Paramètres, puis « Export des données ».
2. Lisez « Contenu de l’export » : chaque enregistrement en fichiers JSON Lines, vos fichiers, et un manifeste avec une somme de contrôle par fichier. Les secrets, comme les empreintes de mots de passe, les secrets d'appareils et les identifiants de paiement, sont exclus.
3. Touchez « Demander l’export ».
4. Saisissez « Votre mot de passe » et confirmez.
5. Patientez pendant que le statut affiche « En préparation ». La page se met à jour quand l'export est « Prêt ».
6. Touchez « Télécharger ». Le fichier reste disponible un nombre limité de jours, indiqué par « disponible jusqu'au ».

Quelques exports seulement peuvent être demandés en 24 heures, un à la fois. Un export qui affiche « Échec » n'a pas pu être préparé ; demandez-en un nouveau. Les exports passés sont listés sous « Exports précédents ».
