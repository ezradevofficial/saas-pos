// L10N-02: status and enum words are looked up with dynamic keys
// (t(`approvals.state.${status}`)), which the i18n check cannot see. This
// test pins each key set to the values the API sends, copied from the API
// source named beside each list: when the API adds a value, add its words
// in en and fr (and here).
import en from '@/locales/en.json'
import fr from '@/locales/fr.json'
import { RUN_OUTCOMES } from '@/pages/automation/automationData'

const keys = (messages, path) => Object.keys(path.split('.').reduce((node, key) => node?.[key], messages) ?? {}).sort()
const sorted = (values) => [...values].sort()

// api/app/Core/Approvals/Models/ApprovalRequest.php (PENDING … EXPIRED)
const APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'returned', 'cancelled', 'expired']
// api/app/Core/Approvals/Models/ApprovalAssignment.php (PENDING … REASSIGNED)
const ASSIGNMENT_STATUSES = ['pending', 'approved', 'rejected', 'returned', 'closed', 'reassigned']
// api/app/Core/Approvals/ApprovalConfig.php (FINAL): an escalation's final outcome.
const APPROVAL_FINAL_OUTCOMES = ['approve', 'reject']
// api/app/Core/Notifications/Models/NotificationDelivery.php (STATUSES)
const NOTIFICATION_DELIVERY_STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'skipped', 'pending_digest', 'digested']
// api/app/Core/Automation/Models/AutomationRun.php (OUTCOMES)
const AUTOMATION_RUN_OUTCOMES = ['queued', 'running', 'retrying', 'succeeded', 'skipped', 'failed', 'throttled', 'loop_blocked']
// api/app/Core/Automation/Models/WebhookDelivery.php (PENDING … FAILED)
const WEBHOOK_DELIVERY_STATUSES = ['pending', 'sending', 'retrying', 'delivered', 'failed']
// api/app/Core/Workflow/Models/DocumentWorkflow.php (RUNNING, COMPLETED, CANCELLED)
const DOCUMENT_WORKFLOW_STATUSES = ['running', 'completed', 'cancelled']
// api/app/Core/Workflow/Models/WorkflowVersion.php (DRAFT, PUBLISHED, ARCHIVED);
// the web adds "discarded" for an archived draft that was never live (WF-02).
const WORKFLOW_VERSION_STATUSES = ['draft', 'published', 'archived']

describe.each([
  ['en', en],
  ['fr', fr],
])('status words (%s) match the API values', (_, messages) => {
  it('approval statuses: every decided status has its word; pending reads as one of the waiting states', () => {
    const waiting = ['waitingForYou', 'waiting', 'overdue', 'delegatedFrom', 'blocked']
    expect(keys(messages, 'approvals.state')).toEqual(sorted([...APPROVAL_STATUSES.filter((status) => status !== 'pending'), ...waiting]))
  })

  it('approval assignment statuses', () => {
    expect(keys(messages, 'approvals.assignment')).toEqual(sorted(ASSIGNMENT_STATUSES))
  })

  it('approval final outcomes after escalation', () => {
    expect(keys(messages, 'approvals.route.final')).toEqual(sorted(APPROVAL_FINAL_OUTCOMES.flatMap((outcome) => [`${outcome}At`, `${outcome}AfterEscalation`])))
  })

  it('notification delivery statuses', () => {
    expect(keys(messages, 'notificationDeliveries.statuses')).toEqual(sorted(NOTIFICATION_DELIVERY_STATUSES))
  })

  it('automation run outcomes and webhook delivery statuses', () => {
    expect(keys(messages, 'automation.outcomes')).toEqual(sorted(AUTOMATION_RUN_OUTCOMES))
    expect(keys(messages, 'automation.deliveryStatus')).toEqual(sorted(WEBHOOK_DELIVERY_STATUSES))
  })

  it('workflow statuses: a document’s flow and a flow’s versions', () => {
    expect(keys(messages, 'documentWorkflow.statuses')).toEqual(sorted(DOCUMENT_WORKFLOW_STATUSES))
    expect(keys(messages, 'workflows.versions.status')).toEqual(sorted([...WORKFLOW_VERSION_STATUSES, 'discarded']))
  })
})

it('the run log filter offers every automation run outcome', () => {
  expect(sorted(RUN_OUTCOMES)).toEqual(sorted(AUTOMATION_RUN_OUTCOMES))
})
