import { lazy, Suspense } from 'react'

// The builder brings the canvas library (@xyflow/react), so it loads on its own (spec 6.4).
const WorkflowBuilder = lazy(() => import('./WorkflowBuilder.jsx'))

export default function LazyWorkflowBuilder() {
  return (
    <Suspense fallback={null}>
      <WorkflowBuilder />
    </Suspense>
  )
}
