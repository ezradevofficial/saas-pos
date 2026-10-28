---
id: pos-sign-in
title: Sign in at the till
module: pos
order: 10
permissions: [pos.till.sign_in]
roles: [cashier, branch_manager]
screens: [pos:StaffSignInScreen, pos:ChangePinScreen]
---
You sign in at the till with your name and your PIN. An admin must have paired the till and given you a role at its location.

1. Open the {{app_name}} app on the till.
2. On "Who is selling?", tap your name.
3. Type your PIN on the keypad. Use "Delete last digit" to correct one digit, or "Clear" to start again.
4. Tap "Sign in".

If you tapped the wrong name, tap "Choose someone else".

## If your PIN was reset

After a reset, the till shows "Choose a new PIN". Type a new PIN in "New PIN", type it again in "Enter the new PIN again", then tap "Save PIN". A PIN has 4 to 6 digits. Managers who approve voids, refunds or price changes need 6 digits.

## If sign-in does not work

- After 5 wrong tries your PIN is locked on this till. Ask a manager to reset it, or set a new one in the back office (see "Set your POS PIN").
- If you have no PIN yet, set one in the back office under My POS PIN.
- If your name is not in the list, ask an admin to give you a role at this location, then tap "Sync now".
