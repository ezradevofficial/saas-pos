# ApprovalCard

One item in the approvals inbox, with document summary, requester, due time and the approve, reject and return actions.

**Props the consumer provides:** `docType`, `number`, `title`, `requester`, `amount` and `currency` (omit both for documents without a value, such as leave), optional `branch`, `onBehalfOf` (when approving as a delegate), `due`, `overdue`, `escalatesIn`, and `onApprove`, `onReject`, `onReturn`.

**Do:** show "Delegated from" whenever the approver acts for someone else; ask for a reason on Reject and Return.
**Don't:** show Approve to the requester of their own document.
