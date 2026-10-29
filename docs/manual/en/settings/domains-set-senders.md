---
id: domains-set-senders
title: Set your subdomain, email sender and SMS sender
module: core
order: 360
permissions: [core.domain.manage]
roles: [owner, admin]
screens: [/settings/domains]
---
Warning: emails and SMS go to your customers and staff in your name. Check the sender before you save, and add the DNS records so that mail servers do not mark your emails as spam.

1. Open Settings, then "Domains".
2. For your own address on the platform, open "Subdomain", type the "Subdomain" (lower-case letters, digits and hyphens) and tap "Save subdomain". If subdomains are not switched on for the platform, the page says so; use a custom domain instead.
3. To send notification emails from your own domain, open "Email sender", fill in "Sender name" and "Sender address" (on one of your verified domains), then tap "Save email sender".
4. Add the SPF and DKIM records the page shows under "Add these to your domain's DNS" to your domain's DNS.
5. For SMS, open "SMS sender ID", type the "Sender ID" (3 to 11 letters or digits) and tap "Save sender ID". It is registered with the SMS provider before it is used.

"Platform branding" shows whether "Powered by" appears in the sidebar and on the sign-in page. This depends on your plan.
