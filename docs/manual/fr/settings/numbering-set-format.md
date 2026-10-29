---
id: numbering-set-format
title: Définir la numérotation des documents
module: core
order: 500
permissions: [core.numbering.view, core.numbering.edit]
roles: [owner, admin]
screens: [/settings/numbering]
---
Chaque type de document, comme un reçu ou une facture, a un format de numéro. Vous pouvez définir un format pour toute l'organisation, et un autre pour une société ou une succursale.

1. Ouvrez Paramètres, puis « Numérotation ».
2. Trouvez le type de document. Un type sans format propre utilise le format par défaut et affiche un exemple de numéro.
3. Touchez « Ajouter un format » pour donner à une société ou à une succursale son propre format, ou « Modifier » pour en modifier un.
4. Choisissez la « Société » et, si le format concerne une seule succursale, la « Succursale ».
5. Saisissez le « Modèle ». Utilisez des lettres, des chiffres, - / _ . et un compteur, par exemple {000001} ; ses zéros fixent la largeur minimale. Touchez un jeton sous « Insérer » pour l'insérer.
6. Choisissez le « Compteur » : il ne redémarre jamais, ou redémarre chaque année. Un compteur qui redémarre chaque année exige {YYYY} ou {YY} dans le modèle.
7. Activez « Sans trous » si chaque numéro doit suivre le précédent sans en sauter. Les documents s'attendent alors les uns les autres pendant leur numérotation.
8. Vérifiez l'« Exemple », puis touchez « Enregistrer le format ».

Les caisses numérotent les reçus à partir de plages reçues à l'avance : les numéros de reçus peuvent donc comporter des trous.
