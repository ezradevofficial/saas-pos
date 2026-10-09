/**
 * The inline offset dnd-kit asks for while a sortable row is dragged. It is
 * a runtime position, never a design value, so it is the one inline style
 * the designers use (BR-01 covers colours, type, spacing, radii, shadows).
 */
export const dragStyle = (transform, transition) =>
  transform ? { transform: `translate3d(${Math.round(transform.x)}px, ${Math.round(transform.y)}px, 0)`, transition } : undefined
