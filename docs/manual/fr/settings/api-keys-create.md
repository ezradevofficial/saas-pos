---
id: api-keys-create
title: Créer une clé d'API
module: core
order: 370
permissions: [core.api_key.manage]
roles: [owner, admin]
screens: [/settings/api-keys]
---
Une clé d'API permet à un autre système d'utiliser l'API publique. Chaque clé agit avec un rôle à un endroit, et ses périmètres la restreignent davantage. Un rôle de propriétaire ne peut pas être donné à une clé.

Avertissement : quiconque détient la clé peut agir en son nom. La clé n'est affichée qu'une fois, à sa création. Conservez-la dans le coffre de secrets de votre système et ne la partagez jamais par courriel ou messagerie.

1. Ouvrez Paramètres, puis « Clés d’API ».
2. Touchez « Créer une clé ».
3. Saisissez un « Nom » d'après le système qui l'utilise, par exemple Accounting sync.
4. Sous « Rôle de la clé », choisissez le rôle et l'endroit où il s'applique. La clé ne peut faire que ce que ce rôle permet à cet endroit.
5. Sous « Portées », choisissez « Lecture » ou « Lecture et écriture » pour chaque ressource dont le système a besoin. Utilisez « Trouver une ressource » pour en trouver une.
6. Si la clé doit cesser de fonctionner à une date, renseignez « Expire le ». Laissez vide pour une clé sans expiration.
7. Touchez « Créer la clé ».
8. Touchez « Copier la clé », conservez la clé, puis touchez « J’ai enregistré la clé ». Elle n'est plus affichée ensuite.

La liste montre le « Statut », la « Dernière utilisation » et l'expiration de chaque clé. « Requêtes par jour » trace ses requêtes sur les 30 derniers jours.
