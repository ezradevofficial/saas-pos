import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { formatWhen } from '@/lib/format'
import { Alert } from './Alert'
import { Button } from './Button'
import { Dialog } from './Dialog'
import { Select } from './Select'
import { StatusBadge } from './StatusBadge'

const TONES = { draft: 'info', published: 'success', archived: 'neutral', discarded: 'neutral' }

const statusOf = (version) => (version.discarded_at ? 'discarded' : version.status)

/**
 * Runs an action and closes its dialog once it succeeded; a failure stays
 * on screen. A draft changed by someone else (config_changed) is left to
 * the conflict banner when the bar has one (onReload: the hook sets
 * `conflict` on that answer).
 */
async function attempt(action, close, setError, { onFailure, conflictShown = false } = {}) {
  setError(null)
  try {
    await action?.()
    close()
  } catch (error) {
    if (onFailure?.(error)) return
    if (conflictShown && error?.code === 'config_changed') return
    setError(errorMessage(error))
  }
}

/**
 * The version strip of a designer (LAY-06): where the configuration stands
 * (draft, live version, what blocks publishing), and Publish, Discard draft,
 * History with roll back, and Copy to another company, branch or location.
 * Pair it with useConfigDocument:
 *
 *   const config = useConfigDocument('list_view', 'items', scope)
 *   <VersionBar document={config.document} problems={config.problems} pending={config.pending}
 *     conflict={config.conflict} onReload={config.reload}
 *     onPublish={config.publish} onDiscard={config.discardDraft} onRollback={config.rollback}
 *     onCopy={(target, from, options) => config.copyTo(target, from, options)} copyTargets={places} canEdit canPublish />
 *
 * copyTargets: [{ type, id, label }] the user may copy to (the API checks
 * again). Actions may return promises; dialogs close when they resolve.
 * When the place already has a draft (config_draft_exists) the copy dialog
 * asks to replace it and calls onCopy(target, from, { replace: true }).
 * conflict (from useConfigDocument): someone else changed the draft; the
 * bar says so, offers Reload (onReload) and holds Publish back.
 * Publish is the screen's one decisive action (near-black).
 */
export function VersionBar({
  document,
  problems = [],
  canEdit = false,
  canPublish = false,
  copyTargets = [],
  pending = {},
  conflict = null,
  saveState,
  timeZone,
  onPublish,
  onDiscard,
  onRollback,
  onCopy,
  onReload,
}) {
  const { t, i18n } = useTranslation()
  const locale = i18n.resolvedLanguage ?? i18n.language ?? 'en'
  const [dialog, setDialog] = useState(null) // history | copy | discard
  const [error, setError] = useState(null)
  const [confirming, setConfirming] = useState(null)
  const [target, setTarget] = useState('')
  const [from, setFrom] = useState('published')
  // The chosen place already has a draft: the next Copy replaces it.
  const [replacing, setReplacing] = useState(false)

  const live = document?.published ?? null
  const draft = document?.draft ?? null
  const history = document?.history ?? []
  const blocked = problems.length > 0
  const conflictShown = Boolean(conflict && onReload)
  const close = () => {
    setDialog(null)
    setConfirming(null)
    setError(null)
  }
  const open = (name) => {
    setError(null)
    if (name === 'copy') {
      setTarget('')
      setFrom(live ? 'published' : 'draft')
      setReplacing(false)
    }
    setDialog(name)
  }

  const state = draft ? 'draft' : live ? 'published' : null
  const summary = draft
    ? live
      ? t('ds.versionBar.liveIs', { version: live.version })
      : t('ds.versionBar.notPublished')
    : live?.published_at
      ? t('ds.versionBar.publishedAt', { when: formatWhen(live.published_at, locale, timeZone) })
      : null
  const saveText = saveState ? t(`ds.versionBar.save.${saveState}`) : null
  const targets = copyTargets.map((one) => ({ value: `${one.type}:${one.id}`, label: one.label }))

  return (
    <section aria-label={t('ds.versionBar.label')} className="flex flex-wrap items-center gap-3 border-b border-border py-3">
      {state ? (
        <StatusBadge tone={TONES[state]}>{t(`ds.versionBar.state.${state}`, { version: (draft ?? live).version })}</StatusBadge>
      ) : (
        <StatusBadge tone="neutral">{t('ds.versionBar.state.none')}</StatusBadge>
      )}
      {summary ? <span className="text-caption text-ink-muted">{summary}</span> : null}
      {draft && blocked ? <span className="text-caption text-danger">{t('ds.versionBar.problems', { count: problems.length })}</span> : null}
      <div className="flex-1" />
      {saveText ? (
        <span aria-live="polite" className="text-caption text-ink-muted" data-save-state={saveState}>
          {saveText}
        </span>
      ) : null}
      <div className="flex flex-wrap items-center gap-2">
        {history.length > 0 ? (
          <Button variant="ghost" icon="history" onClick={() => open('history')}>
            {t('ds.versionBar.history')}
          </Button>
        ) : null}
        {canEdit && (live || draft) && targets.length > 0 ? (
          <Button variant="ghost" onClick={() => open('copy')}>
            {t('ds.versionBar.copy')}
          </Button>
        ) : null}
        {canEdit && draft ? (
          <Button variant="ghost" onClick={() => open('discard')}>
            {t('ds.versionBar.discard')}
          </Button>
        ) : null}
        {canPublish ? (
          <Button
            variant="pay"
            disabled={!draft || blocked || Boolean(conflict)}
            loading={pending.publish}
            onClick={() => attempt(onPublish, () => {}, setError, { conflictShown: Boolean(onReload) })}
          >
            {t('ds.versionBar.publish', { version: draft?.version ?? (live ? live.version + 1 : 1) })}
          </Button>
        ) : null}
      </div>
      {conflictShown ? (
        <div className="basis-full">
          <Alert
            tone="warning"
            title={t('ds.versionBar.conflict')}
            action={
              <Button variant="secondary" icon="restore" onClick={() => onReload()}>
                {t('ds.versionBar.reload')}
              </Button>
            }
          >
            {t('ds.versionBar.conflictBody')}
          </Alert>
        </div>
      ) : null}
      {error && dialog === null ? (
        <div className="basis-full">
          <Alert tone="danger" title={error} />
        </div>
      ) : null}

      <Dialog open={dialog === 'history'} size="lg" title={t('ds.versionBar.historyTitle')} onClose={close}>
        <div className="flex flex-col gap-3">
          {error ? <Alert tone="danger" title={error} /> : null}
          <ul className="flex flex-col divide-y divide-border">
            {history.map((version) => {
              const status = statusOf(version)
              // A discarded draft was never live, and the live one is already live: no roll back to either.
              const canRollBack = canPublish && status === 'archived'
              return (
                <li key={version.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="flex min-w-0 flex-col gap-1">
                    <span className="flex items-center gap-3 text-ink">
                      <span className="font-medium">{t('ds.versionBar.number', { version: version.version })}</span>
                      <StatusBadge tone={TONES[status] ?? 'neutral'}>{t(`ds.versionBar.status.${status}`)}</StatusBadge>
                    </span>
                    <span className="text-caption text-ink-muted">
                      {[
                        t(`ds.versionBar.source.${version.source}`, { defaultValue: '' }),
                        version.published_at ? t('ds.versionBar.publishedAt', { when: formatWhen(version.published_at, locale, timeZone) }) : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </div>
                  {canRollBack ? (
                    confirming === version.version ? (
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="text-caption text-ink">{t('ds.versionBar.rollBackConfirm', { version: version.version })}</span>
                        <Button variant="ghost" onClick={() => setConfirming(null)}>
                          {t('common.cancel')}
                        </Button>
                        <Button variant="primary" loading={pending.rollback} onClick={() => attempt(() => onRollback?.(version.version), close, setError)}>
                          {t('ds.versionBar.rollBack', { version: version.version })}
                        </Button>
                      </span>
                    ) : (
                      <Button icon="restore" onClick={() => setConfirming(version.version)}>
                        {t('ds.versionBar.rollBack', { version: version.version })}
                      </Button>
                    )
                  ) : null}
                </li>
              )
            })}
          </ul>
        </div>
      </Dialog>

      <Dialog
        open={dialog === 'copy'}
        title={t('ds.versionBar.copyTitle')}
        onClose={close}
        footer={
          <>
            <Button variant="ghost" onClick={close}>
              {t('common.cancel')}
            </Button>
            <Button
              variant={replacing ? 'danger' : 'primary'}
              disabled={!target}
              loading={pending.copy}
              onClick={() => {
                const [type, id] = target.split(':')
                attempt(() => (replacing ? onCopy?.({ type, id }, from, { replace: true }) : onCopy?.({ type, id }, from)), close, setError, {
                  onFailure: (failure) => {
                    if (replacing || failure?.code !== 'config_draft_exists') return false
                    setReplacing(true)
                    return true
                  },
                })
              }}
            >
              {replacing ? t('ds.versionBar.copyReplace') : t('ds.versionBar.copyConfirm')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4 pt-1">
          {error ? <Alert tone="danger" title={error} /> : null}
          {replacing ? <Alert tone="warning" title={t('ds.versionBar.copyDraftExists')} /> : null}
          <p>{t('ds.versionBar.copyBody')}</p>
          <Select
            label={t('ds.versionBar.copyTarget')}
            options={targets}
            placeholder={t('ds.versionBar.copyChoose')}
            value={target}
            onChange={(event) => {
              setTarget(event.target.value)
              setReplacing(false)
            }}
          />
          <Select
            label={t('ds.versionBar.copyFrom')}
            options={[
              ...(live ? [{ value: 'published', label: t('ds.versionBar.copyFromLive', { version: live.version }) }] : []),
              ...(draft ? [{ value: 'draft', label: t('ds.versionBar.copyFromDraft', { version: draft.version }) }] : []),
            ]}
            value={from}
            onChange={(event) => setFrom(event.target.value)}
          />
        </div>
      </Dialog>

      <Dialog
        open={dialog === 'discard'}
        title={t('ds.versionBar.discardTitle', { version: draft?.version })}
        onClose={close}
        footer={
          <>
            <Button variant="ghost" onClick={close}>
              {t('ds.versionBar.keepDraft')}
            </Button>
            <Button variant="danger" loading={pending.discard} onClick={() => attempt(onDiscard, close, setError)}>
              {t('ds.versionBar.discardConfirm')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {error ? <Alert tone="danger" title={error} /> : null}
          <p>{live ? t('ds.versionBar.discardBody', { version: live.version }) : t('ds.versionBar.discardBodyNothingLive')}</p>
        </div>
      </Dialog>
    </section>
  )
}
