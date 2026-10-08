import { clsx } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

// tailwind-merge must know the token theme's names, or it would treat
// `text-label` as a colour and drop it next to `text-ink` (BR-01).
const twMerge = extendTailwindMerge({
  extend: {
    theme: {
      text: ['display', 'h1', 'h2', 'h3', 'body-lg', 'body', 'label', 'caption', 'amount', 'amount-lg'],
      radius: ['pill'],
      shadow: ['sm', 'lg'],
    },
    classGroups: {
      h: [{ h: ['control'] }],
      w: [{ w: ['picker', 'panel', 'palette', 'inspector', 'operator'] }],
      'max-h': [{ 'max-h': ['picker', 'panel'] }],
      'min-h': [{ 'min-h': ['textbox'] }],
      size: [{ size: ['icon-btn', 'dot'] }],
      gap: [{ gap: ['tight'] }],
    },
  },
})

export function cn(...inputs) {
  return twMerge(clsx(inputs))
}
