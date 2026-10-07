import { render, screen } from '@testing-library/react'
import { Button } from './Button'
import { Checkbox } from './Checkbox'
import { Dialog } from './Dialog'
import { PosTile } from './PosTile'
import { Switch } from './Switch'
import { Tabs } from './Tabs'

// Stock shadcn classes include outline-none, which in Tailwind 4 sets
// --tw-outline-style: none. Every ds control must restore a solid outline on
// focus. jsdom cannot compute styles, so this guards the class list; the
// computed outline is checked in the browser (Playwright).
const FOCUS = ['focus-visible:outline-2', 'focus-visible:outline-solid', 'focus-visible:outline-offset-2', 'focus-visible:outline-focus']

describe('focus ring', () => {
  it.each([
    ['Button', () => render(<Button>Save</Button>), () => screen.getByRole('button')],
    ['pay Button', () => render(<Button variant="pay">Charge</Button>), () => screen.getByRole('button')],
    ['Switch', () => render(<Switch checked={false} label="Sell offline" />), () => screen.getByRole('switch')],
    ['Tab', () => render(<Tabs items={[{ value: 'a', label: 'All' }]} value="a" />), () => screen.getByRole('tab')],
    ['PosTile', () => render(<PosTile name="Milk" price={6000} currency="KES" />), () => screen.getByRole('button')],
    ['Checkbox', () => render(<Checkbox label="Track stock" />), () => screen.getByRole('checkbox')],
    ['Dialog close', () => render(<Dialog open title="Void?" onClose={() => {}} />), () => screen.getByRole('button', { name: 'Close' })],
  ])('%s restores a solid focus outline', (_name, mount, find) => {
    mount()
    expect(find()).toHaveClass(...FOCUS)
  })
})
