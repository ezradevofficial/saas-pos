/**
 * WF-11: the stages the document may be sent back to: the API's
 * `return_targets` when it sends them, else the stages it completed
 * (history "left" as completed) and is not at now, most recent first. The
 * API decides whether a return is allowed (422 return_target).
 */
export function returnTargets(flow) {
  if (Array.isArray(flow?.return_targets)) {
    return flow.return_targets.map((target) => ({ value: target.node_id ?? target.id, label: target.name ?? target.node_name ?? target.node_id ?? target.id }))
  }
  const current = new Set((flow?.current ?? []).map((step) => step.node_id))
  const seen = new Map()
  for (const event of [...(flow?.history ?? [])].reverse()) {
    if (event.type !== 'left' || event.data?.how !== 'completed' || !event.node_id || current.has(event.node_id) || seen.has(event.node_id)) continue
    seen.set(event.node_id, event.node_name ?? event.node_id)
  }
  return [...seen].map(([value, label]) => ({ value, label }))
}
