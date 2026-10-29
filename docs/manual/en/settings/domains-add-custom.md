---
id: domains-add-custom
title: Add your own domain
module: core
order: 350
permissions: [core.domain.manage]
roles: [owner, admin]
screens: [/settings/domains]
---
Warning: a custom domain is where your staff and customers reach {{app_name}}. Removing it, or a wrong DNS record, stops sign-in on that address and the emails sent from it at once.

1. Open Settings, then "Domains".
2. Under "Custom domains", type the full name in "Domain", without http:// or a path, for example erp.company.co.ke.
3. Tap "Add domain".
4. The domain shows "Waiting for DNS" with a record: "Type", "Name" and "Value". Create it with your domain provider. The page checks every few minutes, or tap "Check now".
5. When the status is "Verified", point the domain at the address shown with a CNAME record.

A certificate is issued the first time someone opens the verified domain. To stop using a domain, tap "Remove" and confirm with "Remove domain". You can add it again later.
