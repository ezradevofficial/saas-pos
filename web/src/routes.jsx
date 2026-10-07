import { lazy, Suspense } from 'react'
import { GuestOnly, RequireAuth } from './auth/RequireAuth'
import { RequirePermission } from './auth/RequirePermission'
import { AppShell } from './layouts/AppShell'
import { AuthLayout } from './layouts/AuthLayout'
import { ORGANISATION_VIEW } from './layouts/navigation'
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
import InviteUser from './pages/settings/InviteUser'
import Organisation from './pages/settings/Organisation'
import RoleDetail from './pages/settings/RoleDetail'
import Roles from './pages/settings/Roles'
import Sessions from './pages/settings/Sessions'
import UserDetail from './pages/settings/UserDetail'
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
    ],
  },
  // Open to everyone: a signed-in user is asked to sign out first.
  { element: <AuthLayout />, children: [{ path: '/invitations/:token', element: <AcceptInvitation /> }] },
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
          <RequirePermission permission={ORGANISATION_VIEW}>
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
        path: '/settings/users/invite',
        element: (
          <RequirePermission permission="core.user.invite">
            <InviteUser />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/users/:userId',
        element: (
          <RequirePermission permission="core.user.view">
            <UserDetail />
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
      {
        path: '/settings/roles/new',
        element: (
          <RequirePermission permission="core.role.create">
            <RoleDetail />
          </RequirePermission>
        ),
      },
      {
        path: '/settings/roles/:roleId',
        element: (
          <RequirePermission permission="core.role.view">
            <RoleDetail />
          </RequirePermission>
        ),
      },
      { path: '/settings/appearance', element: <Appearance /> },
      { path: '/settings/sessions', element: <Sessions /> },
      { path: '*', element: <NotFound /> },
    ],
  },
]
