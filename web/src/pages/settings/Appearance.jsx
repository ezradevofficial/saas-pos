import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Card, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { cn } from '@/lib/utils'
import { useTheme } from '@/theme/ThemeProvider'
import { sampleBrand } from '@/theme/sampleBrand'
import { THEMES } from '@/theme/themes'

function ThemeOption({ id, label, description, checked, onChange }) {
  return (
    <label
      className={cn(
        'flex cursor-pointer flex-col gap-1 rounded-lg border border-border bg-surface-200 p-4 transition-colors hover:border-border-strong',
        'has-checked:border-primary has-checked:bg-primary-tint',
        'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus has-focus-visible:outline-solid',
      )}
    >
      <span className="flex items-center gap-2">
        <input
          type="radio"
          name="theme"
          value={id}
          checked={checked}
          onChange={() => onChange(id)}
          className="size-4 accent-primary focus-visible:outline-0"
        />
        <span className="font-medium text-ink">{label}</span>
      </span>
      <span className="pl-6 text-caption text-ink-muted">{description}</span>
    </label>
  )
}

/** BR-01, BR-02: the four themes, applied at once, and a tenant brand preview. */
export default function Appearance() {
  const { t } = useTranslation()
  const { theme, setTheme, clearTheme, overrides, setOverrides } = useTheme()
  // Still on after leaving and coming back: the overrides stay until reset or reload.
  const [brandPreview, setBrandPreview] = useState(() => Object.keys(overrides ?? {}).length > 0)

  // The sample brand is derived again for dark themes (lighter hover, darker tint).
  const chooseTheme = (next) => {
    setTheme(next)
    if (brandPreview) setOverrides(sampleBrand(next))
  }

  const names = {
    light: t('ds.theme.light'),
    dark: t('ds.theme.dark'),
    executive: t('ds.theme.executive'),
    warm: t('ds.theme.warm'),
  }
  const descriptions = {
    light: t('appearance.themes.light'),
    dark: t('appearance.themes.dark'),
    executive: t('appearance.themes.executive'),
    warm: t('appearance.themes.warm'),
  }

  const toggleBrand = () => {
    setOverrides(brandPreview ? {} : sampleBrand(theme))
    setBrandPreview(!brandPreview)
  }

  const reset = () => {
    setBrandPreview(false)
    setOverrides({})
    // Back to the business's own look (BR-02), or Light when it has none.
    clearTheme()
  }

  return (
    <>
      <PageHeader
        title={t('appearance.title')}
        description={t('appearance.description')}
        actions={<Button onClick={reset}>{t('appearance.reset')}</Button>}
      />

      <section aria-labelledby="theme-heading" className="flex flex-col gap-3">
        <h2 id="theme-heading" className="text-h2 text-ink">
          {t('appearance.theme')}
        </h2>
        <div role="radiogroup" aria-labelledby="theme-heading" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {THEMES.map(({ id }) => (
            <ThemeOption key={id} id={id} label={names[id]} description={descriptions[id]} checked={theme === id} onChange={chooseTheme} />
          ))}
        </div>
      </section>

      <section aria-labelledby="brand-heading" className="flex flex-col gap-3">
        <h2 id="brand-heading" className="text-h2 text-ink">
          {t('appearance.brand')}
        </h2>
        <p className="text-ink-muted">{t('appearance.brandText')}</p>
        <div>
          <Button onClick={toggleBrand} aria-pressed={brandPreview}>
            {brandPreview ? t('appearance.brandOff') : t('appearance.brandOn')}
          </Button>
        </div>
        {brandPreview ? <Alert tone="info" title={t('appearance.brandNoticeTitle')}>{t('appearance.brandNoticeText')}</Alert> : null}
      </section>

      <Card title={t('appearance.preview')} subtitle={t('appearance.previewText')}>
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="primary">{t('appearance.sample.primary')}</Button>
            <Button>{t('appearance.sample.secondary')}</Button>
            <Button variant="pay">{t('appearance.sample.decisive')}</Button>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <StatusBadge tone="success">{t('appearance.sample.paid')}</StatusBadge>
            <StatusBadge tone="warning">{t('appearance.sample.pending')}</StatusBadge>
            <StatusBadge tone="danger">{t('appearance.sample.overdue')}</StatusBadge>
            <span className="font-medium text-primary">{t('appearance.sample.link')}</span>
          </div>
        </div>
      </Card>
    </>
  )
}
