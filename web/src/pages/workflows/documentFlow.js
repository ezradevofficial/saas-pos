/**
 * WF-11: the stages the document may be sent back to, as the API lists
 * them (`return_targets`: [{node_id, name}]), as picker options.
 */
export function returnTargets(flow) {
  if (!Array.isArray(flow?.return_targets)) return []
  return flow.return_targets.filter((target) => target?.node_id).map((target) => ({ value: target.node_id, label: target.name ?? target.node_id }))
}

/**
 * WF-09, WF-10: a history event in words. A create-document action that
 * found the document it created before a return (`result.kept`) says so;
 * a reminder names its number.
 */
export function eventLabel(t, event) {
  const step = event.node_name ?? ''
  if (event.type === 'action' && event.data?.result?.kept === true) return t('documentWorkflow.events.actionKept', { step })
  if (event.type === 'reminded' && Number.isInteger(event.data?.reminder)) return t('documentWorkflow.events.remindedNumber', { step, number: event.data.reminder })
  return t(`documentWorkflow.events.${event.type}`, { defaultValue: event.type, step })
}
