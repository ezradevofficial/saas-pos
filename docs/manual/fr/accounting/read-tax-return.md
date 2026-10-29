---
id: accounting-read-tax-return
title: Lire le récapitulatif de la déclaration fiscale
module: accounting
order: 70
permissions: [accounting.report.view, accounting.period.view]
roles: [accountant]
screens: [/accounting/tax-returns]
---
« Déclarations fiscales » est un récapitulatif pour préparer votre déclaration. Ce n’est pas le formulaire déposé. Recopiez ses chiffres vous-même dans le formulaire officiel de l’administration fiscale ; les cases du formulaire ne sont pas remplies pour vous. Au Kenya, la page est le « Récapitulatif de la déclaration de TVA » ; en RD Congo, le « Récapitulatif de la déclaration de TVA » de la DGI.

1. Ouvrez « Déclarations fiscales » sous « Taxes ». Si vous avez plusieurs sociétés, choisissez-la dans « Société ».
2. Choisissez la « Période ». La liste reprend vos périodes comptables ; « Dates libres » permet de fixer un début et une fin. Le début ne doit pas être après la fin.
3. Lisez « Taxe collectée » (sur les ventes) et « Taxe déductible » (sur les achats). Chaque ligne est un code de taxe et un taux, avec « Base imposable », « Taxe » et « Lignes ».
4. Touchez une ligne pour voir les documents et écritures qui la composent. Touchez « Fermer » ensuite.
5. Lisez « Net à payer » (ou « Net récupérable » quand la taxe déductible est supérieure) et si le total « Concorde avec le grand livre ». S’il indique « Ne concorde pas avec le grand livre », contactez le support avant de déposer.
6. Quand le pays l’exige, une ligne indique combien de factures et d’avoirs l’administration fiscale a acceptés (eTIMS au Kenya, la DGI en RD Congo).
7. Touchez « Exporter en CSV » pour conserver les chiffres ou les travailler dans un tableur.

Ce que signifient les marques :

- Une ligne marquée * n’a pas de base imposable. Les ventes comptabilisées par d’autres modules ne conservent pas la base par code de taxe dans leurs écritures ; seule la taxe est affichée. N’ajoutez pas la base de cette ligne ; prenez-la dans vos rapports de ventes.
- « Sans code de taxe » rassemble les lignes de taxe sans code, par exemple des écritures manuelles ou antérieures à la conservation des codes. Vérifiez ces écritures si le montant n’est pas faible.
- « Taux à confirmer » signifie qu’un code de taxe n’a pas de taux confirmé. Confirmez-le dans les réglages des taxes, puis rouvrez la page.
