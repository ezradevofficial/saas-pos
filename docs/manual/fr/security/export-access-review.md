---
id: security-export-access-review
title: Exporter la revue des accès
module: core
order: 210
permissions: [core.user.view, core.access_review.export]
roles: [owner, admin]
screens: [/settings/users]
---
La revue des accès est un fichier qui liste qui a quel rôle et où. Utilisez-la pour vérifier que les accès sont justes, par exemple chaque trimestre ou avant un audit.

1. Ouvrez Paramètres, puis « Utilisateurs ».
2. Touchez « Exporter la revue des accès ».
3. Ouvrez le fichier téléchargé dans un tableur.

Pour chaque personne, vérifiez les rôles, les endroits où ils s'appliquent et si la double authentification est activée. Retirez les rôles devenus inutiles (voir « Donner un rôle à une personne »), et désactivez les personnes parties (voir « Désactiver un utilisateur ou le déconnecter »).

Chaque changement de rôle, d'autorisation ou d'affectation est enregistré dans le journal d'audit.
