---
id: automation-create-rule
title: Create an automation rule
module: core
order: 850
permissions: [core.automation.view, core.automation.edit]
roles: [owner, admin]
screens: [/settings/automation-rules, /settings/automation-rules/:ruleId]
---
An automation rule acts on its own when something happens to a document: it can send a notification, update a field, change a stage, assign a person, create a document, set a credit hold or call a webhook.

Warning: a rule acts without asking. An action that calls a webhook sends data outside {{app_name}}, to an https:// address you enter. Test the rule first, and save it switched off until you are sure.

1. Open Automation, then "Automation rules".
2. Tap "Create rule". To start from a ready-made rule, use "Start from a template" instead; it is saved switched off.
3. Type the "Name" and choose the "Document type" and, if needed, the "Company". The document type can't change after the rule is created.
4. Under "When", choose the "Trigger", for example "Record created", "Stage entered", "Date arrives" or "Schedule", and fill in its details.
5. Under "Only if", add conditions if the rule should act only for some documents.
6. Under "Then", tap "Add action" for each thing the rule does. They run in the order shown.
7. Read "In plain words" to check the rule says what you mean.
8. Open the "Test" tab and tap "Run test" on sample values or a real document. Nothing is changed.
9. Tap "Save switched off", then "Enable" when you are ready.

A rule that calls a webhook gets a signing secret, shown once. Copy it with "Copy secret" and give it to the receiving system.
