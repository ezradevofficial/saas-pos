---
id: imports-run
title: Importer des enregistrements depuis un tableur
module: core
order: 530
permissions: [core.import.run]
roles: [owner, admin, accountant]
screens: [/settings/imports, /settings/imports/new, /settings/imports/:importId]
---
Importez des articles, clients, fournisseurs et prix depuis un tableur. Chaque ligne est vérifiée avec les mêmes règles qu'un enregistrement saisi dans l'application, et rien n'est enregistré avant l'import. Vous ne voyez que les types d'import que vous pouvez créer.

1. Ouvrez Paramètres, puis « Importations ».
2. Touchez « Nouvelle importation ».
3. Sous « Que voulez-vous importer ? », choisissez le type, puis touchez « Passer au modèle ».
4. Téléchargez le modèle en français ou en anglais, remplissez une ligne par enregistrement, puis touchez « Passer au téléversement ». Les colonnes obligatoires sont signalées.
5. Touchez « Choisir un fichier » et choisissez votre fichier XLSX ou CSV en UTF-8 (jusqu'à 50 000 lignes et 20 Mo), puis touchez « Téléverser et associer les colonnes ».
6. Sous « Associer les colonnes », choisissez la colonne du fichier pour chaque champ. Choisissez ce qui se passe « Quand un enregistrement existe déjà » : « Ajouter seulement les nouveaux enregistrements », ou « Ajouter les nouveaux et mettre à jour les existants ». Touchez « Enregistrer et prévisualiser ».
7. Lisez l'« Aperçu ». Utilisez « Lignes en erreur » pour voir les lignes en erreur, ou « Modifier l’association » pour changer la correspondance. Touchez « Vérifier tout le fichier ».
8. Quand tout le fichier a été vérifié, les lignes en erreur sont ignorées. Touchez « Télécharger les lignes en erreur » pour les corriger et les téléverser de nouveau, ou importez dès maintenant les autres lignes avec le bouton d'import.
9. L'import se poursuit en arrière-plan si vous quittez la page. La page « Résultat » montre ce qui a été ajouté, mis à jour et non importé, et propose « Télécharger le fichier de résultat ».
