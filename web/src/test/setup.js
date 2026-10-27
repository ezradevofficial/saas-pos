import '@testing-library/jest-dom/vitest'
import { configure } from '@testing-library/react'
import { i18nReady, loadLocale, loadModule, moduleNames } from '../i18n'

// The app loads one catalogue at a time (NFR-02); screen tests switch with
// i18n.changeLanguage, so both are loaded up front here, with every module's strings. src/i18n/i18n.test.js
// unloads French to cover the lazy switch.
await i18nReady
await Promise.all(moduleNames.map(loadModule))
await loadLocale('fr')

// CI runners are slower than a laptop: waitFor/findBy wait up to 4 s, not the default 1 s.
configure({ asyncUtilTimeout: 4000 })

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
