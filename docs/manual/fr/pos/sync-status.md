---
id: pos-sync-status
title: Lire l'état de synchronisation
module: pos
order: 140
permissions: [pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:AppHeader]
---
L'état de synchronisation se trouve en haut de la caisse. C'est un point accompagné de mots.

| État | Ce que cela signifie |
| --- | --- |
| En ligne · tout est synchronisé | La caisse est connectée et tout ce qu'elle détient est sur le serveur. |
| Synchronisation… | La caisse envoie ou reçoit des données. Continuez à vendre. |
| Hors ligne · la vente continue | Il n'y a pas de connexion. Les ventes restent sur la caisse. |
| 3 ventes en attente | Le nombre de ventes pas encore envoyées. Il retombe à zéro dès que la caisse est en ligne. |

## Synchroniser

Touchez « Synchroniser » pour que la caisse envoie et reçoive tout de suite, par exemple après qu'un administrateur vous a donné un rôle.

## Messages sur la caisse

- « Dernière synchronisation » indique quand la caisse a reçu des données pour la dernière fois.
- « enregistrements ont été refusés par le serveur et doivent être examinés » : prévenez votre responsable. Le back-office a le détail.
- « Cette caisse a perdu son accès » : l'appareil a été dissocié ou suspendu. Les ventes non envoyées restent sur la caisse. Demandez à un administrateur de réactiver l'appareil.
- « Le module Point de vente n'est pas actif pour votre entreprise » : les ventes restent sur la caisse et sont envoyées quand il sera de nouveau actif.
- « L'abonnement est en retard de paiement » : la caisse ne peut plus envoyer de ventes tant que le propriétaire n'a pas réglé la facture ouverte. Les ventes restent sur la caisse.
