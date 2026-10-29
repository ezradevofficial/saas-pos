---
id: crm-convert-lead
title: Convert a lead to a customer
module: crm
order: 40
permissions: [crm.lead.view, crm.lead.convert]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/leads, /crm/leads/:leadId]
---
When a lead is ready to buy, turn it into a customer and, if you want, an opportunity.

1. Open "Leads", tap the lead and tap "Convert".
2. Under "Customer record", choose "Create a new customer" and check the "Kind", "Customer name", "Phone" and "Email". Or choose "Use an existing customer" and pick one.
3. Tick "Also create an opportunity" to follow the deal. Then fill in the "Opportunity name", "Pipeline", "Stage", "Value", "Currency" and "Expected close date".
4. Tap "Convert lead".

The lead shows "Converted to a customer", with links to open the customer and the opportunity.
