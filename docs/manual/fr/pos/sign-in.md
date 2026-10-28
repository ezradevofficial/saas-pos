---
id: pos-sign-in
title: Se connecter à la caisse
module: pos
order: 10
permissions: [pos.till.sign_in]
roles: [cashier, branch_manager]
screens: [pos:StaffSignInScreen, pos:ChangePinScreen]
---
Vous vous connectez à la caisse avec votre nom et votre code PIN. Un administrateur doit avoir associé la caisse et vous avoir donné un rôle sur son emplacement.

1. Ouvrez l'application {{app_name}} sur la caisse.
2. Sur « Qui vend ? », touchez votre nom.
3. Saisissez votre code PIN sur le clavier. Utilisez « Effacer le dernier chiffre » pour corriger un chiffre, ou « Effacer tout » pour recommencer.
4. Touchez « Se connecter ».

Si vous avez touché le mauvais nom, touchez « Choisir une autre personne ».

## Si votre code PIN a été réinitialisé

Après une réinitialisation, la caisse affiche « Choisir un nouveau code PIN ». Saisissez un nouveau code dans « Nouveau code PIN », saisissez-le à nouveau dans « Saisissez à nouveau le nouveau code PIN », puis touchez « Enregistrer le code PIN ». Un code PIN compte 4 à 6 chiffres. Les responsables qui approuvent des annulations, des remboursements ou des changements de prix ont besoin de 6 chiffres.

## Si la connexion ne fonctionne pas

- Après 5 essais incorrects, votre code PIN est bloqué sur cette caisse. Demandez à un responsable de le réinitialiser, ou définissez-en un nouveau dans le back-office (voir « Définir votre code PIN caisse »).
- Si vous n'avez pas encore de code PIN, définissez-en un dans le back-office, sous « Mon code PIN caisse ».
- Si votre nom n'est pas dans la liste, demandez à un administrateur de vous donner un rôle sur cet emplacement, puis touchez « Synchroniser ».
