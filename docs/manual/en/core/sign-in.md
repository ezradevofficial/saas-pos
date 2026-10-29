---
id: core-sign-in
title: Sign in, reset your password and accept an invitation
module: core
order: 20
permissions: []
roles: [owner, admin, branch_manager, cashier, employee_self_service]
screens: [/sign-in, /forgot-password, /reset-password, /invitations/:token]
---
These pages are open before you sign in.

## Sign in

1. Open the sign-in page of {{app_name}}.
2. Type your email (or phone number, if your business uses phone sign-in) and your "Password", then tap "Sign in".
3. If two-factor sign-in is on for you, type the 6-digit code from your authenticator app.
4. If your organisation uses single sign-on, tap "Continue with SSO", type your "Work email" and tap "Continue to sign-in". Your organisation's own page opens. To go back, tap "Use your password instead".

If you have no account yet, tap "Create an account". See "Create your account".

## Reset a forgotten password

1. On the sign-in page, tap "Forgot your password?".
2. Type your email address and tap "Send code". A 6-digit code arrives within a minute and works for 30 minutes. The page says the same thing whether or not an account exists, so no one can use it to find out who has an account.
3. Type the "6-digit code" and a "New password" of at least 8 characters, not a common one. Tap "Save new password".
4. Sign in with the new password. Changing it signs you out everywhere. If the code did not come, tap "Send a new code".

## Accept an invitation

1. Open the link in your invitation email. The page says "Join your team" and names the business that invited you.
2. Choose a password and tap "Accept and sign in". Your role is already set by the person who invited you.
3. If the page says "This invitation cannot be used", it expired, was used or was withdrawn. Ask the person who invited you for a new one.
4. If you are already signed in as someone else, the page says "You are signed in as ...". Tap "Sign out and accept", or "Stay signed in" to leave the invitation for later.
