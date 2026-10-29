---
id: sso-sign-in-rules
title: Require single sign-on and set the default role
module: core
order: 320
permissions: [core.sso.manage]
roles: [owner]
screens: [/settings/sso]
---
Warning: when you require single sign-on, people at your verified domains can no longer sign in with a password. If the identity provider or the connection is wrong, they are locked out. Owners keep their password and two-step sign-in as a way back in.

1. Open Settings, then "Single sign-on".
2. Check that at least one connection is enabled and tested.
3. Under "Sign-in rules", switch on "Require single sign-on" if everyone at your domains must use single sign-on. Nobody can then create a new account with a password.
4. Switch on "Create accounts on first sign-in" if a person with no account or invitation should get one the first time they sign in. Choose the "Default role for new accounts" they receive. When this is off, only existing users and invited people can sign in.
5. Tap "Save sign-in rules". The page confirms "Sign-in rules saved."

Owners link their own account under Settings, then "Security", with the button "Link my account with" followed by the connection's name. They are never linked by email.
