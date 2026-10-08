// The flow graph the API stores (WF-03..WF-09, spec 6.4) and the pure
// operations the builder applies to it. The graph is the one source of
// truth: React Flow's nodes and edges are derived from it on every render,
// and every edit produces a new graph (undo/redo keeps the old ones).
//
// Graph: { nodes: [{ id, type, name?, position: {x, y}, ...per type }],
//          edges: [{ from, to, branch? }] }

export const NODE_TYPES = ['start', 'stage', 'approval', 'condition', 'parallel', 'join', 'action', 'end']

/** The API's node and branch id pattern (GraphValidator::ID). */
export const ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/

/** Card sizes on the canvas: React Flow needs them before it can measure (and jsdom never measures). */
export const NODE_SIZE = {
  start: { width: 240, height: 48 },
  end: { width: 240, height: 48 },
  condition: { width: 280, height: 96 },
  default: { width: 280, height: 96 },
}
export const sizeOf = (type) => NODE_SIZE[type] ?? NODE_SIZE.default

const RANK_GAP = 150
const COLUMN_GAP = 320

/** The handle an edge leaves from: its branch, else the single "out" handle. */
export const OUT = 'out'
export const IN = 'in'

/**
 * The outgoing handles a node offers: `key` is the edge's branch (null for
 * the plain "out" handle), `multiple` when several edges may leave it.
 */
export function outputsOf(node) {
  switch (node?.type) {
    case 'end':
      return []
    case 'approval':
      return [{ key: 'approved' }, { key: 'rejected' }]
    case 'condition':
      return Array.isArray(node.branches)
        ? [...node.branches.map((branch) => ({ key: branch.key })), { key: 'else' }]
        : [{ key: 'yes' }, { key: 'no' }]
    case 'parallel':
      return [{ key: null, multiple: true }]
    default:
      return [{ key: null }]
  }
}

export const hasInput = (node) => node?.type !== 'start'

/** A new id for a node of `type` that no node of the graph has yet. */
export function newId(graph, type) {
  const taken = new Set(graph.nodes.map((node) => node.id))
  let n = graph.nodes.filter((node) => node.type === type).length + 1
  while (taken.has(`${type}_${n}`)) n += 1
  return `${type}_${n}`
}

/**
 * A new node of a palette kind at `position`. Kinds: the node types, plus
 * `notify` and `create_document` (action nodes with their action set).
 * `names` gives the translated default name per kind.
 */
export function createNode(graph, kind, position, names = {}) {
  const type = kind === 'notify' || kind === 'create_document' ? 'action' : kind
  const node = { id: newId(graph, type), type, position: { x: Math.round(position.x), y: Math.round(position.y) } }
  if (names[kind]) node.name = names[kind]
  switch (kind) {
    case 'stage':
      node.mandatory = true
      break
    case 'approval':
      node.approval = { approver: { type: 'branch_manager' }, mode: 'any' }
      break
    case 'condition':
      node.condition = null
      break
    case 'join':
      node.mode = 'all'
      break
    case 'notify':
      node.action = 'notify'
      node.config = { to: [] }
      break
    case 'create_document':
      node.action = 'create_document'
      node.config = { on_cancel: 'keep' }
      break
    case 'end':
      node.outcome = 'approved'
      break
    default:
      break
  }
  return node
}

/** Adds a node; a parallel split brings its join along, already paired. */
export function addNode(graph, kind, position, names = {}) {
  const node = createNode(graph, kind, position, names)
  if (kind !== 'parallel') return { graph: { ...graph, nodes: [...graph.nodes, node] }, id: node.id }
  const withSplit = { ...graph, nodes: [...graph.nodes, node] }
  const join = createNode(withSplit, 'join', { x: position.x, y: position.y + RANK_GAP * 2 }, names)
  join.split = node.id
  return { graph: { ...graph, nodes: [...withSplit.nodes, join] }, id: node.id }
}

/** Replaces one node's settings (never its id or type). */
export function updateNode(graph, id, changes) {
  return {
    ...graph,
    nodes: graph.nodes.map((node) => (node.id === id ? { ...node, ...changes, id: node.id, type: node.type } : node)),
  }
}

/** Removes nodes with their edges; a join loses its split when the split goes. */
export function removeNodes(graph, ids) {
  const gone = new Set(ids)
  return {
    nodes: graph.nodes
      .filter((node) => !gone.has(node.id))
      .map((node) => (node.type === 'join' && gone.has(node.split) ? { ...node, split: null } : node)),
    edges: graph.edges.filter((edge) => !gone.has(edge.from) && !gone.has(edge.to)),
  }
}

export const edgeId = (edge) => `${edge.from}->${edge.to}${edge.branch ? `:${edge.branch}` : ''}`

export function removeEdges(graph, ids) {
  const gone = new Set(ids)
  const edges = graph.edges.filter((edge) => !gone.has(edgeId(edge)))
  return edges.length === graph.edges.length ? graph : { ...graph, edges }
}

/**
 * Connects `from` (through its `branch` handle, null for "out") to `to`.
 * A handle that takes one edge has its old edge replaced, so a stage never
 * leads two ways; a split never connects twice to the same step; loops onto
 * the node itself and edges into the start are refused (returns the graph unchanged).
 */
export function connect(graph, from, to, branch = null) {
  const source = graph.nodes.find((node) => node.id === from)
  const target = graph.nodes.find((node) => node.id === to)
  if (!source || !target || from === to || !hasInput(target)) return graph
  const output = outputsOf(source).find((out) => out.key === (branch ?? null))
  if (!output) return graph
  const edge = branch ? { from, to, branch } : { from, to }
  const others = graph.edges.filter((existing) => {
    if (existing.from !== from) return true
    if (output.multiple) return existing.to !== to
    return (existing.branch ?? null) !== (branch ?? null)
  })
  return { ...graph, edges: [...others, edge] }
}

/** Moves nodes (positions rounded to whole pixels). */
export function moveNodes(graph, positions) {
  return {
    ...graph,
    nodes: graph.nodes.map((node) =>
      positions[node.id] ? { ...node, position: { x: Math.round(positions[node.id].x), y: Math.round(positions[node.id].y) } } : node,
    ),
  }
}

/**
 * Condition branch keys changed: edges of removed branches go, edges of a
 * renamed key follow it (`renames`: old key -> new key).
 */
export function syncBranches(graph, id, renames = {}) {
  const node = graph.nodes.find((one) => one.id === id)
  if (!node) return graph
  const keys = new Set(outputsOf(node).map((out) => out.key))
  return {
    ...graph,
    edges: graph.edges
      .map((edge) => (edge.from === id && edge.branch && renames[edge.branch] ? { ...edge, branch: renames[edge.branch] } : edge))
      .filter((edge) => edge.from !== id || keys.has(edge.branch ?? null)),
  }
}

/**
 * Places nodes that have no position (default flows ship without one):
 * layered top to bottom by their longest distance from the start, side by
 * side within a layer. Nodes that already have a position keep it.
 */
export function layout(graph) {
  const nodes = Array.isArray(graph?.nodes) ? graph.nodes : []
  const edges = Array.isArray(graph?.edges) ? graph.edges : []
  if (nodes.every((node) => node.position && Number.isFinite(node.position.x) && Number.isFinite(node.position.y))) {
    return { nodes, edges }
  }

  const ids = new Set(nodes.map((node) => node.id))
  const outgoing = new Map(nodes.map((node) => [node.id, []]))
  const incoming = new Map(nodes.map((node) => [node.id, 0]))
  for (const edge of edges) {
    if (!ids.has(edge.from) || !ids.has(edge.to)) continue
    outgoing.get(edge.from).push(edge.to)
    incoming.set(edge.to, incoming.get(edge.to) + 1)
  }

  // Longest-path ranks over a topological order (Kahn); nodes on a cycle keep rank 0 plus their order.
  const rank = new Map(nodes.map((node) => [node.id, 0]))
  const remaining = new Map(incoming)
  const queue = nodes.filter((node) => remaining.get(node.id) === 0).map((node) => node.id)
  const seen = new Set()
  while (queue.length > 0) {
    const id = queue.shift()
    seen.add(id)
    for (const to of outgoing.get(id)) {
      rank.set(to, Math.max(rank.get(to), rank.get(id) + 1))
      remaining.set(to, remaining.get(to) - 1)
      if (remaining.get(to) === 0) queue.push(to)
    }
  }
  let extra = Math.max(0, ...rank.values()) + 1
  for (const node of nodes) if (!seen.has(node.id)) rank.set(node.id, extra++)

  const layers = new Map()
  for (const node of nodes) {
    const r = rank.get(node.id)
    if (!layers.has(r)) layers.set(r, [])
    layers.get(r).push(node.id)
  }

  const positions = {}
  for (const [r, members] of layers) {
    members.forEach((id, index) => {
      const node = nodes.find((one) => one.id === id)
      const offset = (index - (members.length - 1) / 2) * COLUMN_GAP
      positions[id] = { x: Math.round(offset - sizeOf(node.type).width / 2), y: r * RANK_GAP }
    })
  }

  return {
    nodes: nodes.map((node) =>
      node.position && Number.isFinite(node.position.x) && Number.isFinite(node.position.y) ? node : { ...node, position: positions[node.id] },
    ),
    edges,
  }
}

/** A graph from the API, made safe to edit (lists present, positions filled in). */
export function normalizeGraph(graph) {
  const nodes = Array.isArray(graph?.nodes) ? graph.nodes.filter((node) => node && typeof node.id === 'string') : []
  const edges = Array.isArray(graph?.edges) ? graph.edges.filter((edge) => edge && typeof edge.from === 'string' && typeof edge.to === 'string') : []
  return layout({ nodes, edges })
}

/** Where a fresh node goes when added without a drop point: below the lowest node. */
export function nextFreePosition(graph) {
  if (graph.nodes.length === 0) return { x: -NODE_SIZE.default.width / 2, y: 0 }
  const lowest = Math.max(...graph.nodes.map((node) => node.position?.y ?? 0))
  const left = Math.min(...graph.nodes.map((node) => node.position?.x ?? 0))
  return { x: left, y: lowest + RANK_GAP }
}

/** The drag-and-drop payload of a palette item. */
export const DRAG_TYPE = 'application/x-workflow-step'

/** The dry run's path (POST workflows/{id}/test): node id -> its result there. */
export function pathOf(result) {
  const nodes = new Map()
  for (const step of result?.path ?? []) nodes.set(step.node_id, step.result)
  return nodes
}
