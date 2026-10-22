# Country pack values from public research (2026-10-17)

The owner asked for these values to be researched and used ("put what is currently being used").

**These are not legal confirmations.** They come from public sources: government notices where available, and law-firm and payroll-vendor summaries otherwise. Each value is labelled with a confidence level:

- **High**: several independent sources agree, or KRA/NSSF published it. **Applied.**
- **Medium**: sources mostly agree, with a minor gap. **Applied** and marked `researched`. Runs show a "researched, not yet confirmed by an accountant" notice.
- **Low**: sources conflict. **Not applied** (until 2026-10-22). The value stays "needs confirmation" and blocks the action that depends on it, as before. Since the owner decision of 2026-10-22, low-confidence values are applied too, with the stronger notice: see [Applied 2026-10-22 by owner decision](#applied-2026-10-22-by-owner-decision).

Values go into the packs with their source and an effective date. When the owner confirms or corrects a value, it is published through the country pack release flow (ADM-04, ADR 022).

## Kenya

### PAYE, monthly (Income Tax Act; unchanged by the Finance Act 2026 per the guides). High.

| Band (KES / month) | Rate |
|---|---|
| 0 – 24,000 | 10% |
| 24,001 – 32,333 | 25% |
| 32,334 – 500,000 | 30% |
| 500,001 – 800,000 | 32.5% |
| above 800,000 | 35% |

- **Personal relief:** KES 2,400 a month, resident employees only. Deducted from tax.
- **Insurance relief:** 15% of premiums, at most KES 5,000 a month (KES 60,000 a year). Medium. Sources disagree on whether SHIF qualifies; we treat SHIF as a deduction, not as an insurance-relief item.
- **Deductions before PAYE** (Tax Laws (Amendment) Act 2024, KRA notice, effective December 2024). High.
  - SHIF contributions;
  - Affordable Housing Levy;
  - NSSF and registered pension, up to KES 30,000 a month;
  - post-retirement medical fund, up to KES 15,000 a month;
  - mortgage interest, up to KES 30,000 a month.
- **Rounding:** each component to the nearest cent (KES 2 decimals), applied once. Medium.

### SHIF. High.
- 2.75% of gross pay, minimum KES 300, no cap.
- Employee only; no employer share.
- Deductible before PAYE.

### Affordable Housing Levy. High.
- 1.5% of gross pay for the employee and 1.5% for the employer, no cap.
- The employee part is deductible before PAYE.

### NSSF, year 4, from February 2026. High.
- Lower earnings limit KES 9,000; upper earnings limit KES 108,000.
- 6% employee and 6% employer, each on Tier I (up to 9,000) and Tier II (9,001–108,000). That gives a maximum of KES 6,480 each.
- Before February 2026 (year 3): 8,000 and 72,000. Both periods are in the pack.
- Litigation on the NSSF Act 2013 is noted in some sources.

### NITA. High.
- KES 50 per employee per month, paid by the employer (casuals included).
- Remitted by the 9th.

### VAT. High.
- Standard rate 16%; zero-rated 0% (VAT Act Second Schedule); exempt supplies have no VAT.
- The temporary 8% rate on selected petroleum products (Legal Notices 69/70 of 2026) ran from 15 April to 14 October 2026. It has lapsed and is not applied.

### Statutory leave (Employment Act). Medium.
| Type | Entitlement | Paid |
|---|---|---|
| Annual | 21 working days after 12 continuous months | Full pay |
| Maternity | 3 months (90 calendar days) | Full pay |
| Paternity | 2 weeks | Full pay |
| Sick | After 2 months' service: up to 30 days full pay, then up to 15 days half pay, in 12 months | As stated |

The statute's "maximum 30 full + 15 half" is used. Some guides say "7 + 7".

### Identifier formats
- **KRA PIN:** `^[AP][0-9]{9}[A-Z]$`. High.
- **National ID:** 7–9 digits, `^[0-9]{7,9}$`. Medium. A format check only, no checksum.

### Tax depreciation (Second Schedule, post-2020 regime). Low. Not applied.
Commonly cited rates:
- commercial buildings 10%;
- computers 25%;
- motor vehicles 25%;
- manufacturing machinery 50% in year 1, then 25%.

Sources disagree on straight line versus reducing balance, and on later Finance Act changes, so the tax book stays refused.

## DR Congo

### IPR (Ordonnance-loi 69/009 art. 84 as amended by the Loi de finances 2020). Medium.
Annual bands, applied monthly as one twelfth:
| Band (CDF / year) | Rate |
|---|---|
| 0 – 1,944,000 | 3% |
| 1,944,001 – 21,600,000 | 15% |
| 21,600,001 – 43,200,000 | 30% |
| above 43,200,000 | 40% |

- Total IPR is capped at 30% of taxable pay; a minimum of CDF 2,000 is cited. Medium.
- **Dependants reduction:** 2% of IPR per dependant, at most 9. Low. Not applied; dependants give no reduction until confirmed.
- One site shows a different "2026" scale with no legal source. It is not used.

### CNSS (Décret 18/041 of 24 November 2018). Medium.
- Pension: 5% employee and 5% employer.
- Occupational risks: 1.5% employer.
- Family benefits: 6.5% employer.
- Total employer 13%.
- Whether the employee's CNSS is deducted from the IPR base is disputed. Low. Not deducted until confirmed.

### INPP. Low. Not applied.
The headcount-based rates conflict between sources: 3/2/1% against 3.5/3/2% (private, by headcount).

### ONEM. Medium.
0.2% employer, on the taxable base (ANAPI).

### VAT. High.
- Standard rate 16%; exports 0%.
- Reduced rates are cited inconsistently (1%, 5% or 8%), so none are applied.

### Statutory leave (Code du travail, Loi 015/2002 as amended by Loi 16/010). Mixed.
| Type | Value | Confidence |
|---|---|---|
| Maternity | 14 weeks, part paid (2/3 pay is cited) | Medium for the weeks, low for the pay. Weeks applied; pay rate left for confirmation. |
| Circumstantial | At most 10 working days a year, not deducted from annual leave (art. 146). Examples: own marriage 2 days, birth of a child 2 days | Medium |
| Annual | 1.5 working days per month (18 a year), or a 15/18/21-day seniority scale | Low. Not applied. |
| Sick | Not found | Not applied |

### Identifier formats
DRC national ID and CNSS number: no reliable format found. Not enforced.

### Tax depreciation
No statutory class table was found. Usual practice is straight line at commonly accepted rates. Low. Not applied.

## What was applied (branch feat/researched-pack-values)

Every applied value is a pack row with `status: researched`, `confidence`,
`researched_on: 2026-10-17` and its `source` URLs (`App\Core\CountryPacks\ResearchStatus`).
A row with several values takes the lowest confidence among them (the
rounding rule makes most KE payroll rows medium). Effective dates: the
pack data start, 2026-01-01, except NSSF (year 3 to 2026-01-31, year 4 from
2026-02-01). Runs, tax lines and leave records that use a researched value
record it, and the web app shows the "from public research" notice.

| Pack | Applied | Confidence |
| --- | --- | --- |
| KE `pack.json` | VAT_STD 16%; VAT_ZERO 0% | High |
| KE `payroll.json` | PAYE bands; rounding to the cent | Medium |
| | Personal relief 2,400 | High |
| | Insurance relief 15%, cap 5,000 | Medium |
| | Pension + NSSF limit 30,000; post-retirement medical 15,000; mortgage interest 30,000 (new `deduction_limit` components) | High |
| | NSSF year 3 (8,000 / 72,000) and year 4 (9,000 / 108,000), 6% + 6%, reduces taxable pay | Medium (rounding) |
| | SHIF 2.75%, minimum 300, reduces taxable pay | Medium (rounding) |
| | Housing levy 1.5% + 1.5%, reduces taxable pay | Medium (rounding) |
| | NITA 50 per employee (employer) | Medium (rounding) |
| KE `hr.json` | Annual 21 working days after 12 months; maternity 90 calendar days paid; paternity 14 days paid; sick 30 full + 15 half after 2 months | Medium |
| | KRA PIN `^[AP][0-9]{9}[A-Z]$` | High |
| | National ID `^[0-9]{7,9}$` | Medium |
| CD `pack.json` | VAT_STD 16%; VAT_ZERO 0% (exports) | High |
| CD `payroll.json` | IPR annual bands ÷ 12, cap 30%, minimum CDF 2,000 (rounding to the franc assumed) | Medium |
| | CNSS 5% + 5% (not deducted from the IPR base and no ceiling: assumed), risks 1.5%, family 6.5% | Medium |
| | ONEM 0.2% employer, on the taxable base | Medium |
| CD `hr.json` | Maternity 14 weeks (98 calendar days; pay null: the pay rate is not confirmed, so payroll runs with maternity days are blocked) | Medium |
| | Circumstantial leave 10 working days a year; own marriage 2, birth of a child 2 | Medium |

Not applied on 2026-10-17 (low confidence or not found), still null and
`needs_confirmation` then. Most of these were applied on 2026-10-22: see
[Applied 2026-10-22 by owner decision](#applied-2026-10-22-by-owner-decision).


- KE tax depreciation (fixed assets tax book stays refused).
- CD INPP: blocks payroll runs of a company with employees until
  confirmed; the company may mark it **not applicable** in its payroll
  settings, with a reason (audited, recorded on each run).
- CD IPR dependants reduction: not applied (dependants give no
  reduction), never blocking.
- CD CNSS deductibility from the IPR base: not deducted (an assumed
  `false`, shown as such).
- CD annual and sick leave ("Days needed"); CD maternity pay rate; CD
  identifier formats (none enforced); CD tax depreciation.
- Also still needed (not covered by the research): KE VAT withholding;
  CD family allowance per child (blocks runs with an employee who has
  children); weekly and bi-weekly pay rules; return and payment file
  layouts.

Known gaps: payroll does not yet pay KE half-pay sick days at half (the
run records and shows them as "half-pay sick days paid in full"); document lines of POS, Accounting, CRM,
E-commerce and Procurement keep only the tax rate they used, so "used a
researched rate" is on the tax calculation result (`TaxAmount::$researched`)
and the tax rate row, not yet stored on each document line (that needs a
schema change in those modules).

Confirming or correcting a value: publish the pack with the row's
`status: confirmed` (and the research fields removed) through the country
pack release flow (ADM-04, ADR 022). Tax rate rows follow the new version
(their research is cleared); HR leave types already copied keep their
days and research until the company confirms or changes them.

## Applied 2026-10-22 by owner decision

On 2026-10-22 the owner decided to use the researched values for every
statutory item that was still blocked, **knowing they have not been
confirmed by an accountant**. This overrides, for these items only, the
rule that low-confidence values are never entered. Every value is a pack
row with `status: researched`, its `confidence`, `researched_on:
2026-10-22` and its `source` URLs. Low-confidence rows are used like the
others, and:

- runs, tax codes, leave types and requests, the statutory rates page and
  the fixed assets tax classes show "Researched, low confidence: not
  confirmed by an accountant" (en, fr);
- the pack release validator warns on every low-confidence row
  (`country_packs.releases.low_confidence`), so approvers see each one.

A research pass on 2026-10-22 checked the items. Where it found stronger
support than the owner's label, the owner's (lower) label is kept and the
finding is noted, so the notice errs on the side of caution.

| Value applied | Confidence | Sources |
| --- | --- | --- |
| **CD INPP** 3% of gross pay up to 50 employees, 2% for 51 to 300, 1% above 300 (employer). The owner chose this scale. | Low | ANAPI https://www.investindrc.cd/fr/spip.php?article181=&lang=fr; Africarrières https://africarrieres.com/rd-congo/pt/guide/employeur-entreprise/charges-employeur; PwC https://taxsummaries.pwc.com/democratic-republic-of-the-congo/individual/other-taxes |
| Rejected: the 3.5/3/2% scale. Reason: owner decision; PwC, ANAPI and Africarrières give 3/2/1%. **Flag:** two payroll vendors report the 3.5/3/2% scale in force from 2026-01-01 by interministerial order (order not found). It is recorded in the INPP period as `rejected` with this reason. | (rejected) | PaySpace https://payspace.com/blog/drc-inpp-contribution-rate-changes; Sage https://communityhub.sage.com/za/sage-vip-payroll-hr/f/announcements/263531/democratic-republic-of-congo-drc-increase-in-national-institute-of-professional-preparation-inpp-contribution-rates |
| **CD IPR dependants reduction** 2% of IPR per dependant, at most 9. (PwC and Sage agree; a Sage note says no rebate above the third band, one source: not applied.) | Low | Monrespro https://business.monrespro.cd/blog/ipr-impot-professionnel-remunerations-rdc; PwC https://taxsummaries.pwc.com/democratic-republic-of-the-congo/individual/deductions; Sage https://communityhub.sage.com/za/sage-vip-payroll-hr/f/announcements/257552/democratic-republic-of-congo-drc-tax-reforms-affecting-payroll-law-no-23-053 |
| **CD CNSS deducted from the IPR base.** The quick pass found the sources leaning this way: PwC (reviewed April 2026) says the employee's share is deductible from the IPR base, and a net-pay calculator agrees; one other calculator disagrees. Applied (`reduces_taxable: true`). | Low | PwC https://taxsummaries.pwc.com/democratic-republic-of-the-congo/individual/deductions; Afrotools https://afrotools.com/fr/rdc/calculateur-salaire-net; ANAPI and Africarrières (rates) |
| **CD IPR minimum against the 30% cap:** the current order is kept (the CDF 2,000 minimum first, the cap wins). Marked researched (the IPR period is low as a whole); rounding and the monthly minimum stay assumed. | Low | Monrespro; PwC deductions (above) |
| **CD annual leave** 18 working days a year (1.5 per month), after 12 months of service, plus 1 working day per 5 years of seniority (art. 141, found). **Flag:** the Code's text gives adults at least 1 working day a month (12 a year) and workers under 18 at least 1.5; 18 days is above the adult minimum. | Low | Africarrières https://africarrieres.com/rd-congo/fr/guide/droit-du-travail/code-du-travail; Code du travail (Wikisource) https://fr.wikisource.org/wiki/Code_du_Travail_de_la_R%C3%A9publique_d%C3%A9mocratique_du_Congo; Legavox https://www.legavox.fr/blog/maxencekiyana/conge-droit-pour-travailleur-32101.htm; ILO https://www.ilo.org/dyn/travail/docs/730/RDC%20-%20Code%20du%20travail%202002.pdf |
| **CD maternity pay** 2/3 of pay for the 14 weeks (98 calendar days), art. 130; replaces `paid: null`. Payroll withholds a third of the daily rate for each day. | Low (the art. 130 text was found; candidate for medium) | actualite.cd https://actualite.cd/2023/05/01/rdc-que-dit-le-code-du-travail-au-sujet-de-la-maternite and https://actualite.cd/2023/05/02/rdc-conge-de-maternite-faut-il-accorder-la-totalite-du-salaire-lemployee-des-kinois; Wikisource; Africarrières; ILO |
| **KE tax depreciation** (Income Tax Act Second Schedule, all "in equal instalments", straight line): buildings (commercial) 10%; machinery (used for manufacture) 50% in the first year of use, then 25% a year of the residue; motor vehicles 25%; computers and software 25%; furniture and fittings 10%; telecommunication equipment 10%; other machinery 10%. The year of first use counts in full (an assumption: no time apportionment found). The fixed assets tax book can compute. | Low (the consolidated Act and PwC agree; candidate for medium) | Kenya Law, Income Tax Act as at 2026-07-01 https://new.kenyalaw.org/akn/ke/act/1973/16/eng@2026-07-01; PwC https://taxsummaries.pwc.com/kenya/corporate/deductions; KRA notice 1529 https://www.kra.go.ke/news-center/public-notices/1529-applicable-capital-deduction-rates-on-written-down-values-residues-of-capital-expenditure; RSM on the Finance Act 2023 https://www.rsm.global/kenya/sites/default/files/media/RSMEA%20Newsletter_%20Overview%20of%20The%20Kenya%20Finance%20Act%202023-24_0.pdf |
| **KE withholding VAT** 2% of the taxable value, withheld by agents the Commissioner appoints (Tax Procedures Act s. 42A; rate from the Finance Act 2019). No later change found; the Finance Act 2026 was not checked. | Medium | KRA https://www.kra.go.ke/images/publications/Overview-of-Withholding-VAT-December-2024.pdf, https://www.kra.go.ke/images/publications/Taxpayer-Guidelines-for-WHVAT.pdf, https://www.kra.go.ke/images/publications/Guidelines-ForAppointed-Vat-Withholding-Agents-061223.pdf |
| **KE public holidays** (Public Holidays Act, Schedule Part I): New Year 1 Jan, Labour Day 1 May, Madaraka 1 June, Mazingira Day 10 Oct (formerly Utamaduni Day, renamed 2024), Mashujaa 20 Oct, Jamhuri 12 Dec, Christmas 25 Dec, Boxing Day 26 Dec; Good Friday and Easter Monday as dates for 2026 to 2030. Section 4: a Part I day on a Sunday moves to the first following day that is not a public holiday (`sunday_rule: next_free_day`); a Saturday holiday does not move. | High | Kenya Law, Public Holidays Act as at 2024-04-26 https://new.kenyalaw.org/akn/ke/act/1912/21/eng@2024-04-26; The Star https://www.the-star.co.ke/news/2024-04-24-october-10-utamaduni-day-renamed-mazingira-day |

**Stayed blocked:**

- **CD sick leave:** "Days needed". The Code (art. 105) keeps 2/3 of cash
  pay for the whole incapacity for ordinary illness (art. 106: the first
  six months for an occupational accident or disease; art. 60: the
  contract may end after six months of incapacity), but no source states
  a number of days. Each company sets its days.
- **CD tax depreciation:** no statutory class table was found; the tax
  book stays refused.
- **CD ID formats** (national ID, CNSS number): no source; not enforced.
- **KE national ID / Maisha Namba:** stays a warning only (no
  authoritative source).
- **KE Idd-ul-Fitr:** gazetted each year; entered when gazetted. Idd-ul-Azha
  and Diwali are holidays for those communities only (Parts II and III).
- Still not covered: CD family allowance per child; weekly and bi-weekly
  pay rules; return and payment file layouts.

**Found, not applied (for the owner):** Law 23/053 replaces IPR with IRPP
from 2026-01-01 (Sage; droitnumerique.cd): bands reported unchanged, the
dependants rebate kept, rounding to the nearest CDF then the nearest CDF
100. Medium (secondary sources). In the CD payroll pack's `todo`.

## Sources

- KRA, "Amendments to PAYE computation pursuant to the Tax Laws (Amendment) Act, 2024": https://www.kra.go.ke/news-center/public-notices/2157-amendments-to-paye-computation-pursuant-to-the-tax-laws-amendment-act%2C-2024
- KRA, capital deduction rates notice: https://www.kra.go.ke/news-center/public-notices/1529-applicable-capital-deduction-rates-on-written-down-values-residues-of-capital-expenditure
- CM Advocates, NSSF from February 2026: https://cmadvocates.com/blog/understanding-the-updated-nssf-contributions-in-kenya-effective-february-2026/
- Statutory payroll deductions in Kenya 2026: https://fnjassociates.co.ke/statutory-payroll-deductions-in-kenya/
- PAYE 2026 guide: https://helloduty.com/blogs/pay-as-you-earn-paye-kra-the-ultimate-guide
- EY, Statute Law (Misc. Amendments) Act 2024 (NITA): https://www.ey.com/en_gl/technical/tax-alerts/kenya-gazettes-statute-law-miscellaneous-amendments-act-2024-with-several-tax-related-changes
- ALN Kenya, temporary VAT on petroleum products 2026: https://aln.africa/wp-content/uploads/2026/04/Temporary-Reduction-of-VAT-on-Selected-Petroleum-Products-Legal-Alert-ALN-Kenya-17042026.pdf
- Orbitax, extension to 14 October 2026: https://orbitax.com/news/country/article/Kenya-Extends-Temporarily-Redu-62662
- KRA PIN structure (python-stdnum): https://arthurdejong.org/python-stdnum/doc/2.0/stdnum.ke.pin
- Employment Act text (HREA Africa): https://hreastafrica.com/employment-act-kenya
- Kenyans.co.ke, leave types: https://www.kenyans.co.ke/news/42601-labour-laws-kenya-maternity-other-types-work-leaves
- IPR RDC (Monrespro): https://business.monrespro.cd/blog/ipr-impot-professionnel-remunerations-rdc
- ANAPI, social contributions RDC: https://www.investindrc.cd/fr/spip.php?article181=&lang=fr
- Africarrières, employer charges RDC 2026: https://africarrieres.com/rd-congo/pt/guide/employeur-entreprise/charges-employeur
- Africarrières, labour law RDC: https://africarrieres.com/rd-congo/fr/guide/droit-du-travail/code-du-travail
- ILO, Code du travail RDC 2002: https://www.ilo.org/dyn/travail/docs/730/RDC%20-%20Code%20du%20travail%202002.pdf
- DRC VAT overview: https://trykintsugi.com/fr-ca/sales-tax-guides/africa/democratic-republic-of-congo
