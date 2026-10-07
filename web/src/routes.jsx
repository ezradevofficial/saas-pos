import { lazy, Suspense } from 'react'
import { GuestOnly, RequireAuth } from './auth/RequireAuth'
import { RequirePermission } from './auth/RequirePermission'
import { AppShell } from './layouts/AppShell'
import { AuthLayout } from './layouts/AuthLayout'
import AcceptInvitation from './pages/auth/AcceptInvitation'
import ForgotPassword from './pages/auth/ForgotPassword'
import ResetPassword from './pages/auth/ResetPassword'
import SignIn from './pages/auth/SignIn'
import SignUp from './pages/auth/SignUp'
import TwoFactor from './pages/auth/TwoFactor'
import TwoFactorEnrol from './pages/auth/TwoFactorEnrol'
import Verify from './pages/auth/Verify'
import Home from './pages/Home'
import NotFound from './pages/NotFound'
import Appearance from './pages/settings/Appearance'
import Organisation from './pages/settings/Organisation'
import Roles from './pages/settings/Roles'
import Sessions from './pages/settings/Sessions'
import Users from './pages/settings/Users'

// Development only: every design-system component in its states.
const ComponentGallery = import.meta.env.DEV ? lazy(() => import('./dev/ComponentGallery.jsx')) : null
const devRoutes = ComponentGallery
  ? [
      {
        path: '/dev/components',
        element: (
          <Suspense fallback={null}>
            <ComponentGallery />
          </Suspense>
        ),
      },
    ]
  : []

export const routes = [
  ...devRoutes,
  {
    element: (
      <GuestOnly>
        <AuthLayout />
      </GuestOnly>
    ),
    children: [
      { path: '/sign-in', element: <SignIn /> },
      { path: '/sign-up', element: <SignUp /> },
      { path: '/verify', element: <Verify /> },
      { path: '/two-factor', element: <TwoFactor /> },
      { path: '/forgot-password', element: <ForgotPassword /> },
      { path: '/reset-password', element: <ResetPassword /> },
      { path: '/invitations/:token', element: <AcceptInvitation /> },
    ],
  },
  {
    element: (
      <RequireAuth allowEnrolment>
        <AuthLayout />
      </RequireAuth>
    ),
    children: [{ path: '/two-factor/enrol', element: <TwoFactorEnrol /> }],
  },
  {
    element: (
      <RequireAuth>
        <AppShell />
      </RequireAuth>
    ),
    children: [
      { path: '/', element: <Home /> },
      {
        path: '/settings/organisation',
        element: (
          <RequirePermission permission="core.company.view">
            <Organisation />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/users',
        element: (
          <RequirePermission permission="core.user.view">
            <Users />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/roles',
        element: (
          <RequirePermission permission="core.role.view">
            <Roles />
          </RequirePermission>
        ),
      },
      { path: '/settings/appearance', element: <Appearance /> },
      { path: '/settings/sessions', element: <Sessions /> },
      { path: '*', element: <NotFound /> },
    ],
  },
]
