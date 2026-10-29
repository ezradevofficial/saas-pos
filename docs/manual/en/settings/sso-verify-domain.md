---
id: sso-verify-domain
title: Verify an email domain for single sign-on
module: core
order: 300
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
Only people whose email address is at a verified domain sign in with your single sign-on connections. You prove that you own the domain with a DNS record. Only the Owner can set up single sign-on.

1. Open Settings, then "Single sign-on".
2. Under "Email domains", type the domain in "Email domain", for example company.co.ke, then tap "Add domain".
3. The domain shows "Waiting for DNS" with a record: "Type", "Name" and "Value". Add this TXT record at your DNS provider.
4. When the record is in place, tap "Check now". The status changes to "Verified".

If the status is "Not verified", the line under it says why: the record is not there yet, the DNS lookup failed, another organisation verified the domain first, or the record was removed. Fix it, then check again.

To stop a domain from using single sign-on, tap "Remove" and confirm with "Remove domain". People at that domain can no longer sign in with single sign-on. Their accounts stay.
