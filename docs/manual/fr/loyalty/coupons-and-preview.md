---
id: loyalty-coupons-and-preview
title: Ajouter des codes de coupon et voir l'aperçu d'une promotion
module: loyalty
order: 40
permissions: [loyalty.promotion.view, loyalty.promotion.edit]
roles: [owner, admin, branch_manager]
screens: [/loyalty/promotions/:promotionId]
---
Ouvrez une promotion qui « Exige un code de coupon ».

1. Dans la carte « Coupons de réduction », saisissez les codes sous « Codes de réduction », séparés par des espaces ou des virgules. Ou utilisez « Ou en générer » pour que les codes soient créés.
2. Réglez « Utilisations par code », ou laissez vide pour aucune limite. Touchez « Ajouter les codes ».
3. La liste montre chaque « Code », ses « Utilisations » et son « État » (« Utilisable » ou « Archivé »). Touchez « Archiver » pour arrêter un code. Les utilisations sont comptées quand les ventes arrivent au serveur.
4. Pour tester la promotion, utilisez la carte « Aperçu » : ajoutez l'« Article », la « Quantité » et le « Prix unitaire » de chaque ligne, choisissez l'« Agence », la date sous « Le » et un « Code de coupon » si besoin, puis touchez « Voir l'aperçu ».
5. Le résultat montre ce qui s'applique et la « Remise totale », ou « Rien ne s'applique à ce panier. »
