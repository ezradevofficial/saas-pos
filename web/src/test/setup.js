import '@testing-library/jest-dom/vitest'
import '../i18n'

// jsdom lacks a few element APIs that Radix menus and selects call.
if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {}
if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false
if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {}
// React Flow (workflow builder) reads the viewport transform with DOMMatrixReadOnly
// and SVG text sizes with getBBox; jsdom has neither.
if (!globalThis.DOMMatrixReadOnly) {
  globalThis.DOMMatrixReadOnly = class {
    constructor(transform) {
      const scale = /scale\(([\d.]+)\)/.exec(String(transform ?? ''))?.[1]
      this.m22 = scale === undefined ? 1 : Number(scale)
    }
  }
}
if (typeof SVGElement !== 'undefined' && !SVGElement.prototype.getBBox) {
  SVGElement.prototype.getBBox = () => ({ x: 0, y: 0, width: 0, height: 0 })
}
// Radix Switch measures its thumb with ResizeObserver.
if (!globalThis.ResizeObserver) {
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
