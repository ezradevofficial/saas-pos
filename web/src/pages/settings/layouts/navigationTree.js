// LAY-02: the navigation editor's tree: its payload and drag moves.
import { arrayMove } from '@dnd-kit/sortable'

/** The layout payload of an editable tree (labels typed once; empty means the catalogue's). */
export function treePayload(tree, home) {
  return {
    groups: tree.map((group) => ({
      id: group.id,
      label: group.label?.trim() || null,
      items: group.items.map((item) => ({ id: item.id, label: item.label?.trim() || null, hidden: Boolean(item.hidden) })),
    })),
    home: home || null,
  }
}

/** The tree after a drag: a group moved among groups, or an item moved within or between groups. */
export function moveInTree(tree, activeId, overId) {
  if (!overId || activeId === overId) return tree
  const [activeKind, active] = splitId(activeId)
  const [overKind, over] = splitId(overId)
  if (activeKind === 'g') {
    const target = overKind === 'g' ? over : tree.find((group) => group.items.some((item) => item.id === over))?.id
    const from = tree.findIndex((group) => group.id === active)
    const to = tree.findIndex((group) => group.id === target)
    return from < 0 || to < 0 ? tree : arrayMove(tree, from, to)
  }
  const source = tree.findIndex((group) => group.items.some((item) => item.id === active))
  const target = overKind === 'g' ? tree.findIndex((group) => group.id === over) : tree.findIndex((group) => group.items.some((item) => item.id === over))
  if (source < 0 || target < 0) return tree
  const moving = tree[source].items.find((item) => item.id === active)
  if (source === target) {
    const items = tree[source].items
    const next = arrayMove(items, items.indexOf(moving), overKind === 'g' ? items.length - 1 : items.findIndex((item) => item.id === over))
    return tree.map((group, at) => (at === source ? { ...group, items: next } : group))
  }
  return tree.map((group, at) => {
    if (at === source) return { ...group, items: group.items.filter((item) => item.id !== active) }
    if (at !== target) return group
    const index = overKind === 'g' ? group.items.length : group.items.findIndex((item) => item.id === over)
    return { ...group, items: [...group.items.slice(0, index), moving, ...group.items.slice(index)] }
  })
}

const splitId = (id) => {
  const text = String(id)
  return [text.slice(0, 1), text.slice(2)]
}
