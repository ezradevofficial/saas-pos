---
id: domains-set-senders
title: Définir votre sous-domaine, l'expéditeur des courriels et des SMS
module: core
order: 360
permissions: [core.domain.manage]
roles: [owner, admin]
screens: [/settings/domains]
---
Avertissement : les courriels et les SMS partent vers vos clients et votre personnel en votre nom. Vérifiez l'expéditeur avant d'enregistrer et ajoutez les enregistrements DNS pour que les serveurs de messagerie ne classent pas vos courriels comme indésirables.

1. Ouvrez Paramètres, puis « Domaines ».
2. Pour votre propre adresse sur la plateforme, ouvrez « Sous-domaine », saisissez le « Sous-domaine » (lettres minuscules, chiffres et tirets) et touchez « Enregistrer le sous-domaine ». Si les sous-domaines ne sont pas activés sur la plateforme, la page l'indique ; utilisez alors un domaine personnalisé.
3. Pour envoyer les courriels de notification depuis votre propre domaine, ouvrez « Expéditeur des e-mails », remplissez « Nom de l'expéditeur » et « Adresse de l'expéditeur » (sur l'un de vos domaines vérifiés), puis touchez « Enregistrer l'expéditeur ».
4. Ajoutez au DNS de votre domaine les enregistrements SPF et DKIM que la page affiche sous « Ajoutez ceci au DNS de votre domaine ».
5. Pour les SMS, ouvrez « Identifiant d'expéditeur SMS », saisissez l'« Identifiant d'expéditeur » (3 à 11 lettres ou chiffres) et touchez « Enregistrer l'identifiant ». Il est enregistré auprès du fournisseur de SMS avant d'être utilisé.

« Marque de la plateforme » indique si la mention « Powered by » apparaît dans la barre latérale et sur la page de connexion. Cela dépend de votre forfait.
