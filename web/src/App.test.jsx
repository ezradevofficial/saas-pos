import { render, screen } from '@testing-library/react'
import App from './App'

describe('App', () => {
  it('shows the app name from VITE_APP_NAME', () => {
    render(<App />)

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
      import.meta.env.VITE_APP_NAME,
    )
  })
})
