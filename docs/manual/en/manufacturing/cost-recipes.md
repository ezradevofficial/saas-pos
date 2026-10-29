---
id: mfg-cost-recipes
title: Cost your recipes and BOMs
module: manufacturing
order: 8
permissions: [manufacturing.report.view, manufacturing.cost.view]
roles: [accountant, branch_manager, owner, admin]
screens: [/manufacturing/recipe-costing]
---
Recipe costing shows what each BOM in force costs now: ingredients at current stock costs, plus labour and overhead, less by-products.

1. Open "Recipe costing" under "Manufacturing".
2. Search with "Search by item" and filter by kind and company.
3. Read each row: the "Ingredients cost", "Labour", "Overhead", "By-product credit", the "Batch cost" and the "Cost per unit". For items sold at the till, "Price" and "Cost % of price before tax" show the margin.
4. "Without a cost" counts the ingredients that have no stock cost yet. A BOM that cannot be costed shows why, for example "No version in force" or "The recipe loops back on itself".
5. Tap "Export" and choose a format to take the table away.

Labour and overhead use the rates in "Manufacturing settings". See "Set labour and overhead rates".
