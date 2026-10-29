---
id: stores-acknowledge-issue
title: Accuser réception des articles du magasin
module: stores
order: 20
permissions: [stores.issue.view, stores.issue.acknowledge]
roles: [branch_manager, owner]
screens: [/stores/issues, /stores/issues/:issueId]
---
Quand le magasin sort des articles, le service confirme qu’il les a reçus. Le magasin garde qui a confirmé et quand.

1. Ouvrez « Sorties » sous « Magasins ». Les sorties qui attendent le service affichent « Sortie » ; celles confirmées affichent « Réception confirmée ».
2. Ouvrez la sortie et comparez ses lignes à ce que vous avez reçu.
3. Touchez « Accuser réception », puis confirmez dans la fenêtre.
4. La sortie indique alors qui l’a reçue, sous « Reçue par ».

Pour une copie papier, touchez « Imprimer » sur la sortie et choisissez « Bon de sortie » ou « Bon de livraison » ; touchez « Télécharger le PDF » pour un fichier. Les deux s’impriment en noir sur blanc. Utilisez « Rechercher par numéro ou note » et les filtres « Magasin » et « Statut » pour retrouver une sortie. Si les articles sont valorisés par le module Stocks, les valeurs apparaissent une fois qu’il les a valorisés.
