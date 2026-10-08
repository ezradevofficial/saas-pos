import { addNode, connect, edgeId, layout, moveNodes, outputsOf, removeEdges, removeNodes, syncBranches, updateNode } from './graph'

const base = () => ({
  nodes: [
    { id: 'start', type: 'start' },
    { id: 'review', type: 'stage', name: 'Review' },
    { id: 'check', type: 'condition', name: 'Big?', condition: null },
    { id: 'end', type: 'end', outcome: 'approved' },
  ],
  edges: [
    { from: 'start', to: 'review' },
    { from: 'review', to: 'end' },
  ],
})

describe('workflow graph (WF-03, WF-05, WF-06)', () => {
  it('lays out a default flow without positions top to bottom', () => {
    const graph = layout(base())
    const y = Object.fromEntries(graph.nodes.map((node) => [node.id, node.position.y]))
    expect(y.start).toBeLessThan(y.review)
    expect(y.review).toBeLessThan(y.end)
    expect(graph.nodes.every((node) => Number.isFinite(node.position.x))).toBe(true)
  })

  it('replaces a stage’s only outgoing link and refuses loops and links into the start', () => {
    let graph = connect(base(), 'review', 'check')
    expect(graph.edges.filter((edge) => edge.from === 'review')).toEqual([{ from: 'review', to: 'check' }])
    expect(connect(graph, 'review', 'review')).toBe(graph)
    expect(connect(graph, 'check', 'start', 'yes')).toBe(graph)
    graph = connect(connect(graph, 'check', 'end', 'yes'), 'check', 'review', 'no')
    expect(graph.edges.filter((edge) => edge.from === 'check')).toEqual([
      { from: 'check', to: 'end', branch: 'yes' },
      { from: 'check', to: 'review', branch: 'no' },
    ])
  })

  it('adds a parallel split with its join and keeps ids unique', () => {
    const { graph, id } = addNode(base(), 'parallel', { x: 0, y: 0 })
    const join = graph.nodes.find((node) => node.type === 'join')
    expect(join.split).toBe(id)
    expect(new Set(graph.nodes.map((node) => node.id)).size).toBe(graph.nodes.length)
    const split = graph.nodes.find((node) => node.id === id)
    const two = connect(connect(graph, split.id, 'review'), split.id, 'end')
    expect(two.edges.filter((edge) => edge.from === split.id)).toHaveLength(2)
  })

  it('removes a node with its links, and edges by id', () => {
    const graph = removeNodes(base(), ['review'])
    expect(graph.edges).toEqual([])
    const other = removeEdges(base(), [edgeId({ from: 'start', to: 'review' })])
    expect(other.edges).toEqual([{ from: 'review', to: 'end' }])
  })

  it('drops the links of removed condition branches', () => {
    let graph = updateNode(base(), 'check', { branches: [{ key: 'b1', condition: null }, { key: 'b2', condition: null }] })
    expect(outputsOf(graph.nodes[2]).map((out) => out.key)).toEqual(['b1', 'b2', 'else'])
    graph = connect(connect(graph, 'check', 'end', 'b2'), 'check', 'review', 'else')
    graph = syncBranches(updateNode(graph, 'check', { branches: [{ key: 'b1', condition: null }] }), 'check')
    expect(graph.edges.filter((edge) => edge.from === 'check')).toEqual([{ from: 'check', to: 'review', branch: 'else' }])
  })
})

describe('moveNodes', () => {
  const placed = { nodes: [{ id: 'a', type: 'start', position: { x: 10, y: 20 } }], edges: [] }

  it('returns the same graph when a click reports a step where it already is, so no draft is saved', () => {
    expect(moveNodes(placed, { a: { x: 10.2, y: 19.8 } })).toBe(placed)
  })

  it('records a real move, rounded to whole pixels', () => {
    const moved = moveNodes(placed, { a: { x: 40.6, y: 20 } })
    expect(moved).not.toBe(placed)
    expect(moved.nodes[0].position).toEqual({ x: 41, y: 20 })
  })
})
