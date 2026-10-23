// ECOM-27, ECOM-29: a delivery's last position, its trail while under way and
// its proof photo. No map provider is called (the TrackingMap hook has none
// by default): the trail is a small sketch scaled to its own bounds.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, StatusBadge } from '@/components/ds'
import { formatRelative, formatTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { When } from '@/pages/crm/CrmParts'
import { ecomKey } from './ecommerceData'

/** "-1.28640, 36.81720": a reported position (strings from the API, shown as sent). */
const coordinates = (point) => `${point.latitude}, ${point.longitude}`

const VIEW = { width: 100, height: 60, pad: 6 }

/** The trail as a polyline in a 100 × 60 box, north up, scaled to its bounding box. */
function trailPoints(points) {
  const coords = points.map((point) => ({ lat: Number(point.latitude), lng: Number(point.longitude) })).filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))
  if (coords.length === 0) return []
  const lats = coords.map((p) => p.lat)
  const lngs = coords.map((p) => p.lng)
  const [minLat, maxLat, minLng, maxLng] = [Math.min(...lats), Math.max(...lats), Math.min(...lngs), Math.max(...lngs)]
  // A degree of longitude is shorter away from the equator.
  const shrink = Math.cos((((minLat + maxLat) / 2) * Math.PI) / 180)
  const spanX = (maxLng - minLng) * shrink
  const spanY = maxLat - minLat
  const inner = { width: VIEW.width - 2 * VIEW.pad, height: VIEW.height - 2 * VIEW.pad }
  const scale = spanX === 0 && spanY === 0 ? 0 : Math.min(spanX ? inner.width / spanX : Infinity, spanY ? inner.height / spanY : Infinity)
  const offsetX = (inner.width - spanX * scale) / 2 + VIEW.pad
  const offsetY = (inner.height - spanY * scale) / 2 + VIEW.pad
  return coords.map((p) => [
    Number((offsetX + (p.lng - minLng) * shrink * scale).toFixed(2)),
    Number((offsetY + (maxLat - p.lat) * scale).toFixed(2)),
  ])
}

function TrailSketch({ trail }) {
  const { t } = useTranslation()
  const points = trailPoints(trail)
  if (points.length < 2) return null
  const last = points[points.length - 1]
  return (
    <svg
      role="img"
      aria-label={t('ecommerce.tracking.sketch', { total: trail.length })}
      viewBox={`0 0 ${VIEW.width} ${VIEW.height}`}
      className="h-32 w-full rounded-md border border-border bg-surface-100"
    >
      <polyline points={points.map((point) => point.join(',')).join(' ')} className="fill-none stroke-primary" strokeWidth="1.5" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
      <circle cx={points[0][0]} cy={points[0][1]} r="1.5" className="fill-surface-200 stroke-primary" strokeWidth="1" vectorEffect="non-scaling-stroke" />
      <circle cx={last[0]} cy={last[1]} r="2" className="fill-primary" />
    </svg>
  )
}

/** ECOM-29: where the rider last reported, the trail while under way and how long it is kept. */
export function TrackDialog({ delivery, title, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const query = useQuery({
    queryKey: [...ecomKey, 'deliveries', delivery.id, 'track'],
    queryFn: () => api.get(`ecommerce/deliveries/${delivery.id}/track`),
    retry: false,
    refetchInterval: (current) => (current.state.data?.data?.active ? 30_000 : false),
  })
  const track = query.data?.data
  const latest = [...(track?.trail ?? [])].reverse().slice(0, 6)
  return (
    <Dialog
      open
      title={title ?? t('ecommerce.tracking.title')}
      onClose={onClose}
      footer={<Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>}
    >
      <div className="flex flex-col gap-4 pt-1 text-body">
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!track && !query.isError ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {track ? (
          <>
            <StatusBadge tone={track.active ? 'info' : 'neutral'}>{t(track.active ? 'ecommerce.tracking.active' : 'ecommerce.tracking.ended')}</StatusBadge>
            {track.last_location ? (
              <dl className="grid grid-cols-2 gap-x-6 gap-y-1">
                <dt className="text-ink-muted">{t('ecommerce.tracking.position')}</dt>
                <dd className="text-ink tabular-nums">{coordinates(track.last_location)}</dd>
                <dt className="text-ink-muted">{t('ecommerce.tracking.reported')}</dt>
                <dd className="flex flex-col text-ink">
                  <span>{formatRelative(track.last_location.at, locale)}</span>
                  <span className="text-caption text-ink-muted"><When value={track.last_location.at} /></span>
                </dd>
              </dl>
            ) : (
              <p className="text-ink-muted">{t('ecommerce.tracking.noPosition')}</p>
            )}
            <TrailSketch trail={track.trail} />
            {latest.length ? (
              <div className="flex flex-col gap-2">
                <h3 className="text-label text-ink">{t('ecommerce.tracking.latest')}</h3>
                <ol className="flex flex-col gap-1" aria-label={t('ecommerce.tracking.latest')}>
                  {latest.map((point) => (
                    <li key={`${point.at}-${point.latitude}-${point.longitude}`} className="flex justify-between gap-4 tabular-nums">
                      <span className="text-ink-muted">{formatTime(point.at, locale)}</span>
                      <span className="text-ink">{coordinates(point)}</span>
                    </li>
                  ))}
                </ol>
              </div>
            ) : null}
            <p className="text-caption text-ink-muted">{t('ecommerce.tracking.cleared')}</p>
          </>
        ) : null}
      </div>
    </Dialog>
  )
}

/** ECOM-28: the photo the rider took at the door, as a thumbnail that opens it larger. */
export function ProofPhoto({ photo, number }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  if (!photo?.url) return null
  const alt = t('ecommerce.tracking.proofAlt', { number })
  return (
    <div className="flex flex-col gap-1">
      <span className="text-caption text-ink-muted">{t('ecommerce.tracking.proof')}</span>
      <button
        type="button"
        className="w-fit rounded-md focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus"
        aria-label={t('ecommerce.tracking.openProof')}
        onClick={() => setOpen(true)}
      >
        <img src={photo.url} alt={alt} loading="lazy" className="size-16 rounded-md border border-border object-cover" />
      </button>
      {open ? (
        <Dialog open size="lg" title={t('ecommerce.tracking.proof')} onClose={() => setOpen(false)}>
          <figure className="flex flex-col gap-2 pt-1">
            <img src={photo.url} alt={alt} className="h-auto w-full rounded-md border border-border" />
            {photo.at ? <figcaption className="text-caption text-ink-muted"><When value={photo.at} /></figcaption> : null}
          </figure>
        </Dialog>
      ) : null}
    </div>
  )
}
