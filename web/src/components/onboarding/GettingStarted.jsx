import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { Button, Card, Icon, StatusBadge } from '@/components/ds'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { usePermissions } from '@/auth/usePermissions'
import { CHECKLIST_KEY, useChecklist } from '@/pages/onboarding/useOnboarding'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { cn } from '@/lib/utils'

/** Where each checklist item is done. */
const LINKS = {
  company: '/onboarding/setup/company',
  tax_id: '/onboarding/setup/company',
  item: '/onboarding/setup/items',
  till: '/onboarding/setup/devices',
  first_sale: '/pos/sales',
}

function Item({ item, onNavigate }) {
  const { t } = useTranslation()
  return (
    <li className="flex items-start gap-3 border-b border-border py-3 last:border-b-0">
      <span
        aria-hidden="true"
        className={cn(
          'mt-px flex size-5 shrink-0 items-center justify-center rounded-pill border',
          item.done ? 'border-success bg-success text-surface-200' : 'border-border-strong bg-surface-200',
        )}
      >
        {item.done ? <Icon name="check" size={12} /> : null}
      </span>
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="text-label text-ink">{t(`onboarding.checklist.items.${item.key}.title`)}</span>
        <span className="text-caption text-ink-muted">{t(`onboarding.checklist.items.${item.key}.help`)}</span>
        {item.video_url ? (
          <a href={item.video_url} target="_blank" rel="noreferrer" className="text-caption text-primary hover:text-primary-hover">
            {t('onboarding.checklist.video')}
          </a>
        ) : null}
      </div>
      {item.done ? (
        <StatusBadge tone="success">{t('onboarding.setup.status.done')}</StatusBadge>
      ) : (
        <Link to={LINKS[item.key] ?? '/onboarding/setup'} onClick={onNavigate} className="text-label text-primary hover:text-primary-hover">
          {t('onboarding.checklist.go')}
        </Link>
      )}
    </li>
  )
}

/**
 * ONB-07: the getting-started checklist on Home, from real state (company,
 * tax ID, an item, a paired till, the first sale). It goes away after the
 * first sale or when dismissed; "Show help" opens the panel with short
 * help for each item.
 */
export function GettingStarted() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const { data: checklist } = useChecklist()
  const [panel, setPanel] = useState(false)
  const dismiss = useMutation({
    mutationFn: () => api.post('onboarding/checklist/dismiss'),
    onSuccess: (response) => queryClient.setQueryData(CHECKLIST_KEY, response.data),
  })

  if (!checklist?.visible) return null
  const open = checklist.items.filter((item) => !item.done)

  return (
    <>
      <Card
        title={t('onboarding.checklist.title')}
        subtitle={t('onboarding.checklist.progress', { done: checklist.done, total: checklist.total })}
        actions={
          <>
            <Button variant="ghost" onClick={() => setPanel(true)}>
              {t('onboarding.checklist.showHelp')}
            </Button>
            {can('core.onboarding.edit') ? (
              <Button variant="ghost" loading={dismiss.isPending} onClick={() => dismiss.mutate()}>
                {t('onboarding.checklist.dismiss')}
              </Button>
            ) : null}
          </>
        }
      >
        <ul className="flex flex-col gap-2">
          {open.slice(0, 3).map((item) => (
            <li key={item.key} className="flex items-center justify-between gap-3">
              <span className="text-body text-ink">{t(`onboarding.checklist.items.${item.key}.title`)}</span>
              <Link to={LINKS[item.key] ?? '/onboarding/setup'} className="text-label text-primary hover:text-primary-hover">
                {t('onboarding.checklist.go')}
              </Link>
            </li>
          ))}
        </ul>
      </Card>
      <Sheet open={panel} onOpenChange={setPanel}>
        <SheetContent side="right" className="gap-0 bg-surface-200 p-5 text-body">
          <SheetTitle className="text-h3 text-ink">{t('onboarding.checklist.title')}</SheetTitle>
          <SheetDescription className="text-caption text-ink-muted">{t('onboarding.checklist.panelIntro')}</SheetDescription>
          <ul className="mt-4 flex flex-col">
            {checklist.items.map((item) => (
              <Item key={item.key} item={item} onNavigate={() => setPanel(false)} />
            ))}
          </ul>
        </SheetContent>
      </Sheet>
    </>
  )
}
