import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ds'

/** Server pagination under a list: "Page 2 of 5", Previous and Next. Hidden for one page. */
export function Pager({ page, lastPage, onPage, label }) {
  const { t } = useTranslation()
  if (!lastPage || lastPage <= 1) return null
  return (
    <nav aria-label={label ?? t('pager.label')} className="flex flex-wrap items-center justify-end gap-3">
      <span className="text-caption text-ink-muted">{t('pager.pageOf', { page, last: lastPage })}</span>
      <Button variant="ghost" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        {t('pager.previous')}
      </Button>
      <Button variant="ghost" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
        {t('pager.next')}
      </Button>
    </nav>
  )
}
