import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { apiPathOf, downloadFile } from '@/lib/files'
import { screenMatches } from '@/lib/helpScreens'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'

/** Topics grouped by module, in the order the API gave them (core first). */
function groupByModule(topics) {
  const groups = []
  for (const topic of topics) {
    let group = groups.find((entry) => entry.module === topic.module)
    if (!group) {
      group = { module: topic.module, label: topic.module_label, topics: [] }
      groups.push(group)
    }
    group.topics.push(topic)
  }
  return groups
}

/**
 * The topic's screenshot. The image route needs the bearer token, so it is fetched
 * through the API client and shown from an object URL (released when it changes or the topic closes).
 */
function TopicImage({ url, alt }) {
  const [src, setSrc] = useState(null)

  useEffect(() => {
    let active = true
    let objectUrl = null
    api
      .download(apiPathOf(url))
      .then(({ blob }) => {
        if (!active) return
        objectUrl = URL.createObjectURL(blob)
        setSrc(objectUrl)
      })
      .catch(() => {
        // The text stands without the picture.
      })
    return () => {
      active = false
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [url])

  if (!src) return null
  return <img src={src} alt={alt} className="w-full rounded-md border border-border" />
}

/** The topic being read: its HTML comes from the API, which escapes raw HTML and refuses unsafe links. */
function Reader({ id, locale }) {
  const { t } = useTranslation()
  const topic = useQuery({
    queryKey: ['manual', 'topic', id, locale],
    queryFn: () => api.get(`manual/topics/${id}?locale=${locale}`),
    enabled: Boolean(id),
  })

  if (!id) return <p className="text-body text-ink-muted">{t('help.pick')}</p>
  if (topic.isError) return <Alert tone="danger">{errorMessage(topic.error)}</Alert>
  if (!topic.data) return <p role="status" className="text-body text-ink-muted">{t('common.loading')}</p>

  const { title, module_label: moduleLabel, html, image_url: imageUrl, image_alt: imageAlt } = topic.data.data
  return (
    <article aria-label={title} className="flex flex-col gap-4">
      <header className="flex flex-col gap-1">
        <p className="text-caption text-ink-muted">{moduleLabel}</p>
        <h2 className="text-h2 text-ink">{title}</h2>
      </header>
      {imageUrl ? <TopicImage key={imageUrl} url={imageUrl} alt={imageAlt ?? title} /> : null}
      <div className="manual-prose" dangerouslySetInnerHTML={{ __html: html }} />
    </article>
  )
}

/**
 * The manual in the app (RBAC-08, L10N-01): the topics the API lists for this
 * user (their modules and permissions), grouped by module, searchable by
 * title, read beside the list, and downloadable as a PDF of exactly those
 * topics. `?screen=/path` narrows the list to the topics written for a page.
 */
export default function Help() {
  const { t } = useTranslation()
  const locale = useLocale()
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState('')
  const [downloading, setDownloading] = useState(false)
  const [downloadError, setDownloadError] = useState(null)

  const topics = useQuery({
    queryKey: ['manual', 'topics', locale],
    queryFn: () => api.get(`manual/topics?locale=${locale}`),
  })

  const screen = params.get('screen')
  const selected = params.get('topic')
  const all = useMemo(() => topics.data?.data ?? [], [topics.data])
  const forScreen = useMemo(() => (screen ? all.filter((topic) => (topic.screens ?? []).some((pattern) => screenMatches(pattern, screen))) : []), [all, screen])
  const scoped = screen !== null && forScreen.length > 0
  const base = scoped ? forScreen : all

  const needle = query.trim().toLowerCase()
  const shown = needle ? base.filter((topic) => topic.title.toLowerCase().includes(needle)) : base
  const groups = groupByModule(shown)

  const open = (id) => {
    const next = new URLSearchParams(params)
    next.set('topic', id)
    setParams(next, { replace: true })
  }

  const showAll = () => {
    const next = new URLSearchParams(params)
    next.delete('screen')
    setParams(next, { replace: true })
  }

  const download = async () => {
    setDownloading(true)
    setDownloadError(null)
    try {
      await downloadFile(`manual/my-guide.pdf?locale=${locale}`, `guide-${locale}.pdf`)
    } catch {
      setDownloadError(t('help.downloadFailed'))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('help.title')}
        description={t('help.description')}
        actions={
          <Button icon="download" loading={downloading} onClick={download}>
            {t('help.download')}
          </Button>
        }
      />
      {downloadError ? <Alert tone="danger">{downloadError}</Alert> : null}
      {topics.isError ? <Alert tone="danger">{errorMessage(topics.error)}</Alert> : null}
      {screen !== null && topics.data ? (
        <Alert
          tone="info"
          action={
            scoped ? (
              <Button variant="ghost" onClick={showAll}>
                {t('help.showAll')}
              </Button>
            ) : null
          }
        >
          {scoped ? t('help.forPage') : t('help.noneForPage')}
        </Alert>
      ) : null}
      <div className="grid gap-6 md:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-4 md:col-span-1">
          <TextField
            label={t('help.searchLabel')}
            placeholder={t('help.searchPlaceholder')}
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <nav aria-label={t('help.listLabel')} className="flex flex-col gap-4">
            {topics.data && all.length === 0 ? <p className="text-body text-ink-muted">{t('help.none')}</p> : null}
            {topics.data && all.length > 0 && shown.length === 0 ? <p className="text-body text-ink-muted">{t('help.empty', { query: query.trim() })}</p> : null}
            {groups.map((group) => (
              <section key={group.module} aria-label={group.label} className="flex flex-col gap-1">
                <h2 className="text-label text-ink-muted">{group.label}</h2>
                <ul className="flex flex-col">
                  {group.topics.map((topic) => (
                    <li key={topic.id}>
                      <button
                        type="button"
                        aria-current={selected === topic.id ? 'true' : undefined}
                        onClick={() => open(topic.id)}
                        className={cn(
                          'flex w-full flex-col items-start gap-0.5 rounded-md px-3 py-2 text-left hover:bg-surface-300',
                          selected === topic.id && 'bg-surface-300',
                        )}
                      >
                        <span className="text-body font-medium text-ink">{topic.title}</span>
                        {topic.excerpt ? <span className="line-clamp-2 text-caption text-ink-muted">{topic.excerpt}</span> : null}
                      </button>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </nav>
        </div>
        <Card className="min-w-0 md:col-span-2">
          <Reader id={selected} locale={locale} />
        </Card>
      </div>
    </div>
  )
}
