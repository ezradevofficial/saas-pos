---
id: fuel-review-variances
title: Approuver les écarts et les remises à zéro de compteur
module: fuel
order: 40
permissions: [fuel.variance.view, fuel.variance.approve, fuel.meter.approve_reset]
roles: [station_manager]
screens: [/fuel/settlements, /fuel/reconciliations, /fuel/meter-resets, /fuel/settlements/:settlementId, /fuel/reconciliations/:reconciliationId]
---
Décidez de ce qui dépasse la tolérance. Vous ne pouvez pas approuver un poste que vous avez ouvert ou clôturé, ni votre propre demande.

1. Ouvrez « Revue des écarts ». Elle compare ce que chaque pompiste a remis à ce que disent les compteurs. Ouvrez un règlement pour lire ses chiffres et ses modes de paiement.
2. Touchez « Ouvrir la boîte des approbations » pour l'approuver ou le rejeter.
3. Ouvrez « Rapprochements des cuves » pour le jaugeage de chaque cuve face au stock comptable. Un écart hors tolérance vous attend.
4. Ouvrez « Remises à zéro ». Pour une demande d'un chef de poste, lisez les index et le motif, puis touchez « Approuver » ou « Rejeter ».

Le règlement peut se rouvrir si une vente arrive sur le serveur après la clôture du poste ; les chiffres sont alors recalculés.
