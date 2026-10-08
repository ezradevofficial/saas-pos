import { Background, BackgroundVariant, Controls, MarkerType, MiniMap, ReactFlow, useReactFlow } from '@xyflow/react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { branchLabel, displayName, kindLabel, summarize } from './describe'
import { FlowNode } from './FlowNode'
import { connect, edgeId, IN, moveNodes, OUT, outputsOf, removeEdges, removeNodes, sizeOf, DRAG_TYPE } from './graph'

const NODE_TYPES = { flow: FlowNode }

function edgeTaken(edge, path) {
  if (!path || !path.has(edge.from) || !path.has(edge.to)) return false
  return !edge.branch || edge.branch === path.get(edge.from)
}

/**
 * The flow canvas (spec 6.4): drag to move, connect handles to link steps,
 * Delete or Backspace removes the selected step or link, zoom controls and
 * a mini-map. Read-only (a phone, or no edit right) still pans, zooms and
 * selects. Every change goes through `commit` (undoable); dragging previews
 * and is recorded once when the drag ends.
 */
export function FlowCanvas({ graph, readOnly, selectedId, onSelect, commit, preview, settle, problems, path, context, onDropStep, label }) {
  const { t } = useTranslation()
  const flow = useReactFlow()
  const [measured, setMeasured] = useState({})
  const [selectedEdge, setSelectedEdge] = useState(null)

  const byId = useMemo(() => new Map(graph.nodes.map((node) => [node.id, node])), [graph.nodes])

  const nodes = useMemo(
    () =>
      graph.nodes.map((node) => {
        const size = sizeOf(node.type)
        const title = displayName(t, node)
        const kind = kindLabel(t, node)
        const outputs = outputsOf(node).map((output) => ({ id: output.key ?? OUT, label: output.key ? branchLabel(t, node, output.key) : null }))
        return {
          id: node.id,
          type: 'flow',
          position: node.position ?? { x: 0, y: 0 },
          width: size.width,
          initialHeight: size.height,
          measured: measured[node.id],
          selected: node.id === selectedId,
          deletable: !readOnly,
          ariaLabel: `${kind}: ${title}`,
          data: {
            node,
            kind,
            title,
            summary: summarize(t, node, context),
            outputs,
            problem: problems?.has(node.id) ?? false,
            // APR-01: an approval needs somewhere to go when it is rejected.
            hint: node.type === 'approval' && !graph.edges.some((edge) => edge.from === node.id && edge.branch === 'rejected') ? t('workflows.canvas.noRejected') : null,
            path: path?.get(node.id) ?? null,
            dimmed: Boolean(path && path.size > 0 && !path.has(node.id)),
          },
        }
      }),
    [graph.nodes, graph.edges, measured, selectedId, readOnly, problems, path, context, t],
  )

  const edges = useMemo(
    () =>
      graph.edges.map((edge) => {
        const id = edgeId(edge)
        const taken = edgeTaken(edge, path)
        const from = byId.get(edge.from)
        const to = byId.get(edge.to)
        const branch = branchLabel(t, from, edge.branch)
        return {
          id,
          source: edge.from,
          target: edge.to,
          sourceHandle: edge.branch ?? OUT,
          targetHandle: IN,
          type: 'smoothstep',
          selected: id === selectedEdge,
          deletable: !readOnly,
          className: taken ? 'workflow-edge-taken' : undefined,
          markerEnd: { type: MarkerType.ArrowClosed, color: taken ? 'var(--success)' : 'var(--border-strong)' },
          ariaLabel: t('workflows.canvas.edge', {
            from: from ? displayName(t, from) : edge.from,
            to: to ? displayName(t, to) : edge.to,
            branch: branch ? ` (${branch})` : '',
          }),
        }
      }),
    [graph.edges, byId, path, selectedEdge, readOnly, t],
  )

  const onNodesChange = (changes) => {
    const moved = {}
    let finished = false
    const removed = []
    const sizes = {}
    for (const change of changes) {
      if (change.type === 'position' && change.position) {
        moved[change.id] = change.position
        if (!change.dragging) finished = true
      } else if (change.type === 'position' && change.dragging === false) {
        finished = true
      } else if (change.type === 'select') {
        if (change.selected) onSelect(change.id)
        else if (change.id === selectedId) onSelect(null)
      } else if (change.type === 'remove') {
        removed.push(change.id)
      } else if (change.type === 'dimensions' && change.dimensions) {
        sizes[change.id] = change.dimensions
      }
    }
    if (Object.keys(sizes).length > 0) setMeasured((current) => ({ ...current, ...sizes }))
    if (readOnly) return
    if (removed.length > 0) {
      commit((current) => removeNodes(current, removed))
      if (removed.includes(selectedId)) onSelect(null)
      return
    }
    if (Object.keys(moved).length > 0) {
      if (finished) commit((current) => moveNodes(current, moved))
      else preview((current) => moveNodes(current, moved))
    } else if (finished) {
      // The drag ended without a last move: record the gesture as it stands.
      settle()
    }
  }

  const onEdgesChange = (changes) => {
    const removed = []
    for (const change of changes) {
      if (change.type === 'select') setSelectedEdge(change.selected ? change.id : null)
      else if (change.type === 'remove') removed.push(change.id)
    }
    if (!readOnly && removed.length > 0) commit((current) => removeEdges(current, removed))
  }

  const onConnect = ({ source, sourceHandle, target }) => {
    if (readOnly) return
    commit((current) => connect(current, source, target, sourceHandle && sourceHandle !== OUT ? sourceHandle : null))
  }

  const onDrop = (event) => {
    const kind = event.dataTransfer?.getData(DRAG_TYPE)
    if (!kind || readOnly) return
    event.preventDefault()
    const point = flow.screenToFlowPosition({ x: event.clientX, y: event.clientY })
    const size = sizeOf(kind)
    onDropStep(kind, { x: point.x - size.width / 2, y: point.y - size.height / 2 })
  }

  return (
    <ReactFlow
      aria-label={label}
      nodes={nodes}
      edges={edges}
      nodeTypes={NODE_TYPES}
      onNodesChange={onNodesChange}
      onEdgesChange={onEdgesChange}
      onConnect={onConnect}
      onPaneClick={() => {
        onSelect(null)
        setSelectedEdge(null)
      }}
      onDragOver={(event) => {
        if (readOnly) return
        event.preventDefault()
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
      }}
      onDrop={onDrop}
      nodesDraggable={!readOnly}
      nodesConnectable={!readOnly}
      edgesReconnectable={false}
      deleteKeyCode={readOnly ? null : ['Delete', 'Backspace']}
      multiSelectionKeyCode={null}
      selectionKeyCode={null}
      fitView
      fitViewOptions={{ padding: 0.2, maxZoom: 1 }}
      minZoom={0.25}
      maxZoom={1.5}
      ariaLabelConfig={{
        'node.a11yDescription.default': t('workflows.canvas.a11y.node'),
        'node.a11yDescription.keyboardDisabled': t('workflows.canvas.a11y.nodeKeyboard'),
        'node.a11yDescription.ariaLiveMessage': ({ direction, x, y }) => t('workflows.canvas.a11y.moved', { direction, x, y }),
        'edge.a11yDescription.default': t('workflows.canvas.a11y.edge'),
        'controls.ariaLabel': t('workflows.canvas.controls'),
        'controls.zoomIn.ariaLabel': t('workflows.canvas.zoomIn'),
        'controls.zoomOut.ariaLabel': t('workflows.canvas.zoomOut'),
        'controls.fitView.ariaLabel': t('workflows.canvas.fitView'),
        'controls.interactive.ariaLabel': t('workflows.canvas.interactive'),
        'minimap.ariaLabel': t('workflows.canvas.minimap'),
        'handle.ariaLabel': t('workflows.canvas.handle'),
      }}
      className="workflow-canvas"
    >
      <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
      <Controls showInteractive={false} />
      <MiniMap pannable zoomable className="hidden md:block" nodeBorderRadius={6} />
    </ReactFlow>
  )
}
