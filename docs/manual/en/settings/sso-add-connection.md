---
id: sso-add-connection
title: Connect an identity provider
module: core
order: 310
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
A connection links {{app_name}} to Microsoft Entra ID, Google Workspace or another SAML 2.0 identity provider. Verify your email domain first (see "Verify an email domain for single sign-on").

1. Open Settings, then "Single sign-on".
2. Under "Connections", tap "Add connection".
3. Choose the "Identity provider" and give the connection a "Name". The name shows on the sign-in page when there is more than one connection.
4. Fill in the provider's fields:
   - Microsoft Entra ID: "Application (client) ID", "Client secret" and "Directory (tenant) ID".
   - Google Workspace: "Application (client) ID", "Client secret" and, if you want, "Workspace domain".
   - SAML 2.0: the "Metadata URL", or paste the metadata XML, or enter "Identity provider entity ID", "Identity provider sign-in URL" and "Signing certificate" yourself.
5. Tap "Save connection". The client secret is stored encrypted and is never shown again.
6. The connection shows "Values for your identity provider": "Redirect URI" and, for SAML, "Identifier (entity ID)" and "Reply URL (ACS)". Enter them in your identity provider.
7. Tap "Test connection" and sign in at your identity provider. The result shows who signed in, and what would happen to that person on a first sign-in.
8. When the test passes, tap "Enable".

A connection that was never tested shows "Not tested yet. Test it before you enable it.". To change a connection later, use "Edit". "Disable" stops it without deleting it.
