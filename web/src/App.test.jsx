import { screen } from '@testing-library/react'
import { renderApp, resetSession } from '@/test/renderApp'

describe('App', () => {
  beforeEach(() => resetSession())

  it('shows the app name from VITE_APP_NAME on the sign-in page', async () => {
    renderApp('/sign-in')
    expect(await screen.findByText(import.meta.env.VITE_APP_NAME)).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
  })
})
