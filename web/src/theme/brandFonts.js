// BR-02: the curated fonts beyond Geist are loaded only when a theme
// selects them (a separate chunk each), so the default bundle stays as it is.
const LOADERS = {
  'IBM Plex Sans': () => import('@fontsource-variable/ibm-plex-sans'),
  Newsreader: () => import('@fontsource-variable/newsreader'),
}

const loaded = new Map()

/** Loads the font files a set of token values names (font-sans, font-display). Resolves when done. */
export function loadBrandFonts(values = {}) {
  const families = [values['font-sans'], values['font-display']].filter(Boolean).join(',')
  const wanted = Object.keys(LOADERS).filter((name) => families.includes(name))
  return Promise.all(
    wanted.map((name) => {
      if (!loaded.has(name)) loaded.set(name, LOADERS[name]().catch(() => loaded.delete(name)))
      return loaded.get(name)
    }),
  )
}
