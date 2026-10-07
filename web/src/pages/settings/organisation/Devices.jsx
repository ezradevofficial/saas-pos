import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, Icon, StatusBadge, TextField } from '@/components/ds'
import { formatDateTime, formatTime } from '@/lib/dates'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { ConfirmDialog } from '../ConfirmDialog'
import { orgErrorMessage } from './orgErrors'

const TONES = { pending: 'info', active: 'success', suspended: 'warning', unpaired: 'neutral' }

/** The one-time pairing code (TEN-05): shown once, copyable, with its expiry. */
export function PairingCodeDialog({ device, pairing, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(pairing.code)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }

  return (
    <Dialog
      open
      title={t('devices.codeTitle', { name: device.name })}
      onClose={onClose}
      footer={
        <Button variant="primary" onClick={onClose}>
          {t('devices.done')}
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <p>{t('devices.codeIntro')}</p>
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface-100 px-4 py-3">
          <output aria-label={t('devices.code')} className="font-mono text-h1 text-ink">
            {pairing.code}
          </output>
          <Button icon={copied ? 'check' : 'copy'} onClick={copy}>
            {copied ? t('devices.copied') : t('devices.copy')}
          </Button>
          <span role="status" className="sr-only">
            {copied ? t('devices.copied') : ''}
          </span>
        </div>
        <p>{t('devices.codeExpiry', { time: formatTime(pairing.expires_at, locale) })}</p>
        <p className="text-caption">{t('devices.codeOnce')}</p>
      </div>
    </Dialog>
  )
}

function AddDeviceDialog({ location, canPair, onClose, onPaired }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [name, setName] = useState('')

  const mutation = useMutation({
    mutationFn: async () => {
      const created = await api.post(`locations/${location.id}/devices`, { name: name.trim() })
      // Creating and pairing go together: the code is what the person at the till needs next.
      const pairing = canPair ? await api.post(`devices/${created.data.id}/pairing-code`) : null
      return { device: created.data, pairing }
    },
    onSuccess: async ({ device, pairing }) => {
      await queryClient.invalidateQueries({ queryKey: ['devices', location.id] })
      if (pairing) onPaired(device, pairing)
      else onClose()
    },
  })
  const errors = formErrors(mutation.error, ['name'])
  const formError = errors.form ? orgErrorMessage(mutation.error, 'device') : null
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('devices.addTitle', { location: location.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {canPair ? t('devices.addAndPair') : t('devices.add')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="flex flex-col gap-4 pt-1"
      >
        {formError ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={formError} />
          </div>
        ) : null}
        <TextField
          label={t('devices.name')}
          help={t('devices.nameHelp')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={errors.fields.name}
          maxLength={100}
          required
        />
      </form>
    </Dialog>
  )
}

/** POS devices of one location: add and pair, suspend and resume, unpair (TEN-05). */
export function Devices({ location, chain, archived }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const [adding, setAdding] = useState(false)
  const [code, setCode] = useState(null) // { device, pairing }
  const [confirm, setConfirm] = useState(null) // { kind: 'suspend' | 'unpair', device }

  const canCreate = !archived && canWithin('core.device.create', chain)
  const canPair = canWithin('core.device.pair', chain)
  const canSuspend = canWithin('core.device.archive', chain)

  const devices = useQuery({
    queryKey: ['devices', location.id],
    queryFn: () => api.get(`locations/${location.id}/devices?per_page=200`),
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['devices', location.id] })

  const issue = useMutation({
    mutationFn: (device) => api.post(`devices/${device.id}/pairing-code`),
    onSuccess: (pairing, device) => setCode({ device, pairing }),
  })
  const resume = useMutation({ mutationFn: (device) => api.post(`devices/${device.id}/resume`), onSuccess: refresh })
  const change = useMutation({
    mutationFn: ({ kind, device }) => api.post(`devices/${device.id}/${kind}`),
    onSuccess: async () => {
      await refresh()
      setConfirm(null)
    },
  })

  const rowError = issue.error ?? resume.error
  const list = devices.data?.data ?? []

  const meta = (device) => {
    if (device.last_seen_at) return t('devices.lastSeen', { date: formatDateTime(device.last_seen_at, locale) })
    if (device.paired_at) return t('devices.pairedAt', { date: formatDateTime(device.paired_at, locale) })
    return t('devices.notPaired')
  }

  return (
    <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-label text-ink">{t('devices.title', { location: location.name })}</h3>
        {canCreate ? (
          <Button icon="plus" onClick={() => setAdding(true)}>
            {t('devices.add')}
          </Button>
        ) : null}
      </div>
      {devices.isError ? <Alert tone="danger" title={errorMessage(devices.error)} /> : null}
      {rowError ? <Alert tone="danger" title={orgErrorMessage(rowError, 'device')} /> : null}
      {devices.isPending ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : list.length === 0 ? (
        <p className="text-ink-muted">{canCreate ? t('devices.empty') : t('devices.emptyReadOnly')}</p>
      ) : (
        <ul className="divide-y divide-border">
          {list.map((device) => (
            <li key={device.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-2">
              <div className="flex min-w-0 flex-1 items-start gap-2">
                <Icon name="device" className="mt-0.5 text-ink-muted" />
                <div className="flex min-w-0 flex-col">
                  <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="font-medium text-ink">{device.name}</span>
                    <StatusBadge tone={TONES[device.status]}>{t(`devices.status.${device.status}`)}</StatusBadge>
                  </span>
                  <span className="text-caption text-ink-muted">{meta(device)}</span>
                </div>
              </div>
              <div className="flex flex-wrap gap-1">
                {canPair && !archived && (device.status === 'pending' || device.status === 'unpaired') ? (
                  <Button
                    variant="ghost"
                    icon="key"
                    loading={issue.isPending && issue.variables?.id === device.id}
                    onClick={() => issue.mutate(device)}
                    aria-label={t('devices.getCodeFor', { name: device.name })}
                  >
                    {t('devices.getCode')}
                  </Button>
                ) : null}
                {canSuspend && device.status === 'suspended' ? (
                  <Button
                    variant="ghost"
                    loading={resume.isPending && resume.variables?.id === device.id}
                    onClick={() => resume.mutate(device)}
                    aria-label={t('devices.resumeFor', { name: device.name })}
                  >
                    {t('devices.resume')}
                  </Button>
                ) : null}
                {canSuspend && device.status === 'active' ? (
                  <Button
                    variant="ghost"
                    onClick={() => setConfirm({ kind: 'suspend', device })}
                    aria-label={t('devices.suspendFor', { name: device.name })}
                  >
                    {t('devices.suspend')}
                  </Button>
                ) : null}
                {canPair && device.status === 'active' ? (
                  <Button
                    variant="ghost"
                    onClick={() => setConfirm({ kind: 'unpair', device })}
                    aria-label={t('devices.unpairFor', { name: device.name })}
                  >
                    {t('devices.unpair')}
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <AddDeviceDialog
          location={location}
          canPair={canPair}
          onClose={() => setAdding(false)}
          onPaired={(device, pairing) => {
            setAdding(false)
            setCode({ device, pairing })
          }}
        />
      ) : null}
      {code ? (
        <PairingCodeDialog
          device={code.device}
          pairing={code.pairing}
          onClose={() => {
            setCode(null)
            issue.reset()
          }}
        />
      ) : null}
      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm ? t(`devices.${confirm.kind}Title`, { name: confirm.device.name }) : ''}
        confirmLabel={confirm ? t(`devices.${confirm.kind}Confirm`) : ''}
        cancelLabel={t('devices.keep')}
        pending={change.isPending}
        error={orgErrorMessage(change.error, 'device')}
        failure={change.error}
        onConfirm={() => change.mutate(confirm)}
        onClose={() => {
          setConfirm(null)
          change.reset()
        }}
      >
        {confirm ? (
          <div className="flex flex-col gap-2">
            <p>{t(`devices.${confirm.kind}Text`)}</p>
            {confirm.kind === 'suspend' ? <p>{t('devices.suspendLost')}</p> : null}
          </div>
        ) : null}
      </ConfirmDialog>
    </div>
  )
}
