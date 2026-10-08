import { useCallback, useReducer } from 'react'

const LIMIT = 100

function reducer(state, action) {
  switch (action.type) {
    case 'reset':
      return { past: [], present: action.graph, future: [], pending: null, version: state.version + 1, baseline: state.version + 1 }
    case 'commit': {
      const next = typeof action.update === 'function' ? action.update(state.present) : action.update
      if (next === state.present) return state
      // A gesture in progress (a drag) is undone as one step, from where it began.
      const before = state.pending ?? state.present
      return { ...state, past: [...state.past, before].slice(-LIMIT), present: next, future: [], pending: null, version: state.version + 1 }
    }
    case 'preview': {
      // Intermediate state of a gesture (dragging): shown, not yet an undo step.
      const next = typeof action.update === 'function' ? action.update(state.present) : action.update
      return { ...state, pending: state.pending ?? state.present, present: next }
    }
    case 'settle': {
      // A gesture ended with no last change: record it if it previewed anything.
      if (!state.pending) return state
      return { ...state, past: [...state.past, state.pending].slice(-LIMIT), future: [], pending: null, version: state.version + 1 }
    }
    case 'undo': {
      if (state.past.length === 0) return state
      return { ...state, past: state.past.slice(0, -1), present: state.past.at(-1), future: [state.present, ...state.future], pending: null, version: state.version + 1 }
    }
    case 'redo': {
      if (state.future.length === 0) return state
      return { ...state, past: [...state.past, state.present], present: state.future[0], future: state.future.slice(1), pending: null, version: state.version + 1 }
    }
    default:
      return state
  }
}

/**
 * The graph with undo and redo (spec 6.4). `commit` records a step;
 * `preview` shows a gesture's intermediate state and the next `commit`
 * records the whole gesture as one step. `version` changes on every
 * recorded change (autosave watches it; previews do not count).
 */
export function useGraphHistory(initial) {
  const [state, dispatch] = useReducer(reducer, { past: [], present: initial, future: [], pending: null, version: 0, baseline: 0 })
  return {
    graph: state.present,
    version: state.version,
    /** True once anything changed since the graph was loaded (or reset). */
    edited: state.version !== state.baseline,
    canUndo: state.past.length > 0,
    canRedo: state.future.length > 0,
    commit: useCallback((update) => dispatch({ type: 'commit', update }), []),
    preview: useCallback((update) => dispatch({ type: 'preview', update }), []),
    settle: useCallback(() => dispatch({ type: 'settle' }), []),
    undo: useCallback(() => dispatch({ type: 'undo' }), []),
    redo: useCallback(() => dispatch({ type: 'redo' }), []),
    reset: useCallback((graph) => dispatch({ type: 'reset', graph }), []),
  }
}
