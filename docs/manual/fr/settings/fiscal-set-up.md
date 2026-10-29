---
id: fiscal-set-up
title: Connecter une société à l'autorité fiscale
module: core
order: 790
permissions: [core.fiscal.view, core.fiscal.edit, core.fiscal.configure]
roles: [owner, admin]
screens: [/settings/fiscal]
---
Une fois activé, chaque vente, remboursement et annulation de la société est envoyé à KRA eTIMS ou à la DGI de la RDC. La réponse de l'autorité est conservée avec chaque document.

Avertissement : cela envoie de vrais documents fiscaux à l'autorité, et ils ne peuvent pas être retirés. Saisissez l'enregistrement exactement comme l'autorité a enregistré l'entreprise, et testez sur l'environnement de test de l'autorité avant d'activer l'envoi. Seul un propriétaire ou un administrateur peut modifier l'enregistrement et les identifiants. Ne partagez jamais le jeton d'accès ni la clé de communication.

1. Ouvrez Finances, puis « Administration fiscale ».
2. Choisissez la société dans le sélecteur de société.
3. Sous « Paramètres », choisissez l'« Administration » du pays de la société.
4. Remplissez l'enregistrement : « Numéro d’identification fiscale », « Identifiant de succursale auprès de l’administration » et « Numéro de série de l’appareil ». Modifier l'un de ces champs exige de réinitialiser l'appareil.
5. Saisissez les identifiants. Ils sont stockés chiffrés et ne sont plus jamais affichés.
6. Sous « Codes par défaut », réglez les codes par défaut de classification des articles, d'unité d'emballage et d'unité de quantité, utilisés pour les articles sans codes propres.
7. Touchez « Enregistrer les paramètres ». Tant que les réglages ne sont pas enregistrés, « Initialiser l’appareil » et l'interrupteur d'envoi ne sont pas disponibles.
8. Touchez « Initialiser l’appareil », en haut à droite de la carte. La page confirme quand l'autorité a initialisé l'appareil.
9. Activez « Transmettre les documents à l’administration ».

La page liste ce qui manque encore tant que les réglages ne sont pas complets. Suivez les documents après l'envoi avec « Suivre les documents envoyés à l'autorité fiscale ».
