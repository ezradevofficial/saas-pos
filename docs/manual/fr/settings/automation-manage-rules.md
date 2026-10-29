---
id: automation-manage-rules
title: Activer, désactiver ou archiver une règle et lire ses exécutions
module: core
order: 860
permissions: [core.automation.view, core.automation.edit]
roles: [owner, admin]
screens: [/settings/automation-rules, /settings/automation-runs]
---
1. Ouvrez Automatisation, puis « Règles d’automatisation ».
2. Cherchez dans la liste par nom, ou filtrez par statut, type de document et société.
3. Ouvrez une règle. Touchez « Désactiver » pour l'arrêter ou « Activer » pour la relancer.
4. Ouvrez l'onglet « Exécutions » pour voir chaque exécution de la règle : quand, sur quel document, le résultat et les tentatives. Ouvrez une exécution pour voir quelles conditions ont été remplies et ce que chaque action a fait.
5. Pour retirer une règle, touchez « Archiver la règle », puis « Archiver la règle maintenant ».

Avertissement : une règle archivée cesse de s'exécuter et ne peut plus être modifiée. Son journal d'exécution est conservé.

Les résultats comprennent « Réussie », « Ignorée », « Échouée », « Limitée » et « Boucle bloquée ». Le dernier arrête une règle qui se déclencherait elle-même en boucle. Le journal de toutes les règles se trouve aussi sous « Journal des exécutions ».
