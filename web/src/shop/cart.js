// ECOM-09: the cart lives in the browser (per store): product ids and
// quantities only. Prices, totals and availability always come from the
// server's quote, never from here (ECOM-12).
import { useCallback, useEffect, useState } from 'react'

const key = (store) => `app.shop.cart.${store}`
const listeners = new Set()

function read(store) {
  try {
    const raw = window.localStorage.getItem(key(store))
    const lines = raw ? JSON.parse(raw) : []
    return Array.isArray(lines) ? lines.filter((line) => line && typeof line.product_id === 'string' && typeof line.qty === 'string') : []
  } catch {
    return []
  }
}

function write(store, lines) {
  try {
    window.localStorage.setItem(key(store), JSON.stringify(lines))
  } catch {
    // Storage can be blocked: the cart holds for this visit only.
  }
  listeners.forEach((listener) => listener(store, lines))
}

/** The store's cart and its changes: add, set a quantity (0 removes), clear. */
export function useCart(store) {
  const [lines, setLines] = useState(() => read(store))
  useEffect(() => {
    const listener = (changed, next) => changed === store && setLines(next)
    listeners.add(listener)
    return () => listeners.delete(listener)
  }, [store])
  const add = useCallback((productId, qty = 1) => {
    const current = read(store)
    const found = current.find((line) => line.product_id === productId)
    const next = found
      ? current.map((line) => (line.product_id === productId ? { ...line, qty: String(Number(line.qty) + qty) } : line))
      : [...current, { product_id: productId, qty: String(qty) }]
    write(store, next)
  }, [store])
  const setQty = useCallback((productId, qty) => {
    const next = read(store).map((line) => (line.product_id === productId ? { ...line, qty: String(qty) } : line)).filter((line) => Number(line.qty) > 0)
    write(store, next)
  }, [store])
  const clear = useCallback(() => write(store, []), [store])
  const count = lines.reduce((sum, line) => sum + Number(line.qty || 0), 0)
  return { lines, add, setQty, clear, count }
}
