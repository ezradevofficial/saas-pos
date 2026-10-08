<?php

// APR-01..APR-09: approvals.
return [
    'list_title' => 'Approvals',
    'system' => 'The system',

    'approver_types' => [
        'branch_manager' => 'Branch manager',
        'department_head' => 'Department head',
        'cost_centre_owner' => 'Cost centre owner',
        'manager_levels_up' => 'Manager levels up',
        'manager_levels_up_n' => 'Manager :levels level(s) up',
        'role' => 'Role',
        'user' => 'Named user',
        'stage_roles' => 'People who can complete the step',
    ],

    'escalation_targets' => [
        'next_level' => 'the next level’s manager',
    ],

    'outcomes' => [
        'approved' => 'approved',
        'rejected' => 'rejected',
    ],

    'statuses' => [
        'pending' => 'Waiting',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'returned' => 'Returned for changes',
        'cancelled' => 'Cancelled',
        'expired' => 'Closed',
    ],

    'filters' => [
        'waiting' => 'Waiting for you',
        'decided' => 'Decided by you',
        'all' => 'All',
    ],

    'columns' => [
        'received_at' => 'Received',
        'type' => 'Type',
        'number' => 'Number',
        'title' => 'Summary',
        'amount' => 'Amount',
        'step' => 'Step',
        'requester' => 'Requested by',
        'status' => 'Status',
        'due_at' => 'Due',
    ],

    'blocked' => [
        'no_approver' => 'Nobody can approve this step: the approvers are missing or are the requester. An administrator can reassign it.',
    ],

    'history' => [
        'requested' => 'Sent for approval',
        'step' => 'Moved to the next approver',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'returned' => 'Returned for changes',
        'commented' => 'Commented',
        'info_requested' => 'Asked for more information',
        'attached' => 'Attached a file',
        'reminded' => 'Reminder sent',
        'escalated' => 'Escalated',
        'escalation_exhausted' => 'Nobody further to escalate to',
        'reassigned' => 'Reassigned',
        'blocked' => 'Waiting for an approver',
        'auto_approved' => 'Approved automatically at the final time limit',
        'auto_rejected' => 'Rejected automatically at the final time limit',
        'auto_failed' => 'The automatic decision was refused by the flow',
        'closed' => 'Closed',
    ],

    'attributes' => [
        'comment' => 'comment',
        'node' => 'step',
        'reason' => 'reason',
        'file' => 'file',
        'from_user' => 'current approver',
        'to_user' => 'new approver',
        'ids' => 'approvals',
        'delegate' => 'delegate',
        'starts_on' => 'start date',
        'ends_on' => 'end date',
        'document_types' => 'document types',
        'note' => 'note',
    ],

    'errors' => [
        'not_pending' => 'This approval is no longer waiting for a decision. Refresh to see what happened.',
        'not_assignee' => 'This approval is not waiting for you. Ask an administrator to reassign it if you should decide it.',
        'reason_required' => 'Give a reason for this decision.',
        'self_approval' => 'You cannot decide your own request. It stays with the other approvers.',
        'no_requester' => 'This request has no requester to ask. Add a comment instead.',
        'not_pending_approver' => 'That person is not a current approver of this request. Choose one of the approvers waiting to decide.',
        'ineligible_approver' => 'That person cannot approve this request: choose an active user other than the requester.',
        'already_approver' => 'That person is already an approver of this step.',
        'file_type' => 'This type of file cannot be attached. Attach a PDF, image, text, CSV, Word or Excel file.',
        'file_not_stored' => 'The file could not be stored. Try again.',
        'attach_forbidden' => 'Only the approvers and the requester can attach files to this approval.',
        'attachment_limit' => 'An approval can have at most :max files.',
        'bulk_not_allowed' => 'This approval needs a reason, so it cannot be approved in bulk. Open it to decide.',
    ],

    'delegations' => [
        'all_types' => 'all document types',
        'too_long' => 'A delegation can last at most a year.',
    ],

    'email' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'sign_in' => [
            'used' => 'This link was already used. Sign in to see the approval.',
            'expired' => 'This link has expired. Sign in to decide.',
            'not_waiting' => 'This approval is no longer waiting for you. Sign in to see what happened.',
            'two_factor' => 'Your role requires two-step sign-in. Sign in to decide.',
        ],
    ],

    'validation' => [
        'config' => 'The approval settings are not valid.',
        'chain' => 'A sequential chain needs between one and :max approvers.',
        'step' => 'approver :step: :problem',
        'approver' => 'choose who approves',
        'approver_type' => '“:type” is not a kind of approver',
        'mode' => 'choose any one, all or a majority',
        'flag' => 'the :setting setting must be on or off',
        'escalation' => 'the escalation settings are not valid',
        'escalation_after' => 'escalate after a time between 1 and 10,000 hours or days',
        'escalation_to' => 'escalate to the next level, a role or a user',
        'escalation_role' => 'choose the role to escalate to',
        'escalation_user' => 'choose the user to escalate to',
        'escalation_needs_after' => 'say after how long to escalate',
        'escalation_final' => 'at the final time limit, approve, reject or do nothing',
        'final_needs_time' => 'an automatic final decision needs a time limit or an escalation time',
        'reminders' => 'at most :max reminders, each after a time between 1 and 10,000 hours or days',
        'dimension_field' => 'this document type has no :reference field to find the approver from',
        'levels' => 'choose between 1 and :max levels up',
        'role' => 'choose a role',
        'user' => 'choose a user',
    ],

    // Notification texts ({placeholders} are filled per recipient).
    'notifications' => [
        'requested' => [
            'label' => 'Approval requested',
            'subject' => 'Approve {document_type} {document_number}',
            'body' => "Hello {recipient_name},\n\n{requester_name} asks you to approve {document_type} {document_number} {document_title} {amount} at the step “{step}”.",
            'sms' => '{app_name}: approve {document_type} {document_number} {amount}.',
        ],
        'decided' => [
            'label' => 'Approval decided',
            'subject' => '{document_type} {document_number} was {outcome}',
            'body' => "Hello {recipient_name},\n\n{decided_by} {outcome} your {document_type} {document_number} {document_title} at the step “{step}”.\n\n{comment}",
            'sms' => '{app_name}: {document_type} {document_number} was {outcome}.',
        ],
        'returned' => [
            'label' => 'Returned for changes',
            'subject' => '{document_type} {document_number} needs changes',
            'body' => "Hello {recipient_name},\n\n{decided_by} returned your {document_type} {document_number} for changes at the step “{step}”.\n\nReason: {comment}",
            'sms' => '{app_name}: {document_type} {document_number} was returned for changes.',
        ],
        'info_requested' => [
            'label' => 'More information requested',
            'subject' => 'Question about {document_type} {document_number}',
            'body' => "Hello {recipient_name},\n\n{decided_by} needs more information before deciding on your {document_type} {document_number}:\n\n{comment}",
            'sms' => '{app_name}: {decided_by} asks about {document_type} {document_number}.',
        ],
        'reminder' => [
            'label' => 'Approval reminder',
            'subject' => 'Reminder: approve {document_type} {document_number}',
            'body' => "Hello {recipient_name},\n\n{document_type} {document_number} {document_title} {amount} is still waiting for your decision at the step “{step}”. Due: {due}.",
            'sms' => '{app_name}: {document_type} {document_number} still waits for your approval.',
        ],
        'escalated' => [
            'label' => 'Approval escalated',
            'subject' => 'Escalated: approve {document_type} {document_number}',
            'body' => "Hello {recipient_name},\n\n{document_type} {document_number} {document_title} {amount} was escalated to you: {waiting_for} did not decide in time at the step “{step}”.",
            'sms' => '{app_name}: {document_type} {document_number} was escalated to you.',
        ],
        'delegated' => [
            'label' => 'Approvals delegated to you',
            'subject' => '{delegator_name} delegated approvals to you',
            'body' => "Hello {recipient_name},\n\n{delegator_name} delegated their approvals of {document_types} to you from {starts_on} to {ends_on}. Their items show “Delegated from” in your approvals, and your decisions are logged as made on their behalf.",
            'sms' => '{app_name}: {delegator_name} delegated approvals to you from {starts_on} to {ends_on}.',
        ],
    ],
];
