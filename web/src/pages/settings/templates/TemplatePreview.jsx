import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button } from '@/components/ds'
import { apiPathOf, downloadFile } from '@/lib/files'
import { useDebounced } from '@/lib/useDebounced'
import { cn } from '@/lib/utils'

/** How long editing must pause before the preview asks the API again. */
const PREVIEW_DELAY_MS = 500

/**
 * The live preview (TPL-01): the server renders the template with sample
 * (or the type's real) data. Its HTML shows in a frame that runs nothing
 * (sandbox=""), black on white as printed; the PDF downloads with the
 * bearer token. Reports the problems the API found in the template.
 */
export function TemplatePreview({ type, payload, scope, variant, onProblems }) {
  const { t } = useTranslation()
  const [downloadError, setDownloadError] = useState(null)
  const [downloading, setDownloading] = useState(false)
  const settled = useDebounced(JSON.stringify(payload), PREVIEW_DELAY_MS)

  const preview = useQuery({
    queryKey: ['templates', 'preview', type.key, scope.type, scope.id, variant ?? null, settled],
    queryFn: () =>
      api.post('templates/preview', {
        type: type.key,
        payload: JSON.parse(settled),
        scope_type: scope.type,
        scope_id: scope.id,
        ...(variant ? { variant } : {}),
      }),
    placeholderData: keepPreviousData,
    retry: false,
  })
  const shown = preview.data?.data
  const problems = shown?.problems

  useEffect(() => {
    if (problems) onProblems?.(problems)
  }, [problems, onProblems])

  const download = async () => {
    if (!shown?.pdf_url) return
    setDownloadError(null)
    setDownloading(true)
    try {
      await downloadFile(apiPathOf(shown.pdf_url), `${type.key}.pdf`)
    } catch (error) {
      setDownloadError(errorMessage(error))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <section aria-label={t('documentTemplates.preview.label')} className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-h3 text-ink">{t('documentTemplates.preview.title')}</h3>
        <Button icon="download" disabled={!shown?.pdf_url} loading={downloading} onClick={download}>
          {t('documentTemplates.preview.downloadPdf')}
        </Button>
      </div>
      {!type.live ? <p className="text-caption text-ink-muted">{t('documentTemplates.sampleData')}</p> : null}
      {downloadError ? <Alert tone="danger" title={downloadError} /> : null}
      {preview.isError && !shown ? <Alert tone="danger" title={errorMessage(preview.error)} /> : null}
      {!shown && !preview.isError ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {shown ? (
        <iframe
          title={t('documentTemplates.preview.frame')}
          sandbox=""
          srcDoc={shown.html}
          data-testid="template-preview"
          className={cn('h-sheet w-full rounded-md border border-border', (preview.isFetching || preview.isError) && 'opacity-40')}
        />
      ) : null}
      {preview.isError && shown ? <p className="text-caption text-ink-muted">{t('documentTemplates.preview.stale')}</p> : null}
    </section>
  )
}
