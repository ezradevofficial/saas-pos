import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ds'
import { barPath, niceMax } from '@/lib/chartScale'
import { cn } from '@/lib/utils'

// Drawing units of the SVG; it scales to the width of its card.
const WIDTH = 600
const HEIGHT = 200
const TOP = 12
const BOTTOM = 24
const LEFT = 56
const RIGHT = 8
const GRID_LINES = 3

/**
 * LAY-01: a small single-series chart, bar or line, drawn in SVG with token
 * colours only (BR-01): a hairline baseline and gridlines, thin marks in
 * `primary`, labels in muted ink. Hovering a point (or focusing the chart
 * and using the arrow keys) shows its value; a table of the same figures
 * is always there for screen readers and one click away for everyone.
 *
 * points: [{ label, value }] (value null: no figure, drawn as a gap);
 * formatValue(value) and formatLabel(label) give the words shown.
 */
export function Chart({ type = 'bar', points = [], title, valueLabel, formatValue = String, formatLabel = String, className }) {
  const { t } = useTranslation()
  const id = useId()
  const [active, setActive] = useState(null)
  const [showTable, setShowTable] = useState(false)

  const values = points.map((point) => (point.value == null ? null : Number(point.value)))
  const max = niceMax(Math.max(0, ...values.filter((value) => value != null)))
  const plotWidth = WIDTH - LEFT - RIGHT
  const plotHeight = HEIGHT - TOP - BOTTOM
  const band = points.length ? plotWidth / points.length : plotWidth
  const xOf = (index) => LEFT + band * index + band / 2
  const yOf = (value) => TOP + plotHeight - (value / max) * plotHeight
  const baseline = TOP + plotHeight
  const labelled = new Set([0, Math.floor((points.length - 1) / 2), points.length - 1])

  const line = values
    .map((value, index) => (value == null ? null : `${xOf(index)},${yOf(value)}`))
    .reduce((segments, point) => {
      if (point === null) return [...segments, []]
      segments[segments.length - 1].push(point)
      return segments
    }, [[]])
    .filter((segment) => segment.length > 0)

  const onKeyDown = (event) => {
    if (!points.length) return
    if (event.key === 'ArrowRight') setActive((current) => Math.min(points.length - 1, (current ?? -1) + 1))
    else if (event.key === 'ArrowLeft') setActive((current) => Math.max(0, (current ?? points.length) - 1))
    else if (event.key === 'Escape') setActive(null)
    else return
    event.preventDefault()
  }

  const tip = active === null ? null : points[active]
  const tipText = tip ? `${formatLabel(tip.label)}: ${tip.value == null ? t('layouts.chart.noValue') : formatValue(tip.value)}` : ''
  const tipX = active === null ? 0 : Math.min(Math.max(xOf(active), LEFT + 80), WIDTH - RIGHT - 80)

  return (
    <figure className={cn('flex min-w-0 flex-col gap-2', className)}>
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        role="img"
        aria-labelledby={`${id}-title`}
        tabIndex={0}
        onKeyDown={onKeyDown}
        onMouseLeave={() => setActive(null)}
        onBlur={() => setActive(null)}
        className="h-auto w-full overflow-visible"
        data-chart-type={type}
      >
        <title id={`${id}-title`}>{title}</title>
        {Array.from({ length: GRID_LINES + 1 }, (_, step) => {
          const value = (max / GRID_LINES) * step
          const y = yOf(value)
          return (
            <g key={step}>
              <line x1={LEFT} x2={WIDTH - RIGHT} y1={y} y2={y} className={step === 0 ? 'stroke-border-strong' : 'stroke-border'} strokeWidth={1} />
              <text x={LEFT - 8} y={y} dy="0.32em" textAnchor="end" className="fill-ink-muted text-caption tabular-nums">
                {formatValue(value)}
              </text>
            </g>
          )
        })}
        {points.map((point, index) =>
          labelled.has(index) ? (
            <text key={`label-${point.label}`} x={xOf(index)} y={HEIGHT - 6} textAnchor="middle" className="fill-ink-muted text-caption">
              {formatLabel(point.label)}
            </text>
          ) : null,
        )}

        {type === 'bar'
          ? values.map((value, index) =>
              value == null || value <= 0 ? null : (
                <path
                  key={points[index].label}
                  d={barPath(xOf(index) - Math.min(band * 0.3, 16), yOf(value), Math.min(band * 0.6, 32), baseline - yOf(value), 4)}
                  className={cn('fill-primary transition-opacity', active !== null && active !== index && 'opacity-60')}
                  data-testid="chart-bar"
                />
              ),
            )
          : null}
        {type === 'line' ? (
          <>
            {line.map((segment) => (
              <polyline key={segment[0]} points={segment.join(' ')} fill="none" className="stroke-primary" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" data-testid="chart-line" />
            ))}
            {active !== null && values[active] != null ? (
              <>
                <line x1={xOf(active)} x2={xOf(active)} y1={TOP} y2={baseline} className="stroke-border-strong" strokeWidth={1} />
                <circle cx={xOf(active)} cy={yOf(values[active])} r={4} className="fill-primary stroke-surface-200" strokeWidth={2} />
              </>
            ) : null}
          </>
        ) : null}

        {/* Hit targets wider than the marks: one band per point. */}
        {points.map((point, index) => (
          <rect key={`hit-${point.label}`} x={LEFT + band * index} y={TOP} width={band} height={plotHeight} fill="transparent" onMouseEnter={() => setActive(index)} />
        ))}

        {tip ? (
          <g aria-hidden="true" data-testid="chart-tooltip">
            <rect x={tipX - 80} y={0} width={160} height={22} rx={6} className="fill-surface-200 stroke-border-strong" strokeWidth={1} />
            <text x={tipX} y={11} dy="0.32em" textAnchor="middle" className="fill-ink text-caption tabular-nums">
              {tipText}
            </text>
          </g>
        ) : null}
      </svg>
      <figcaption className="flex items-center justify-between gap-2">
        <span aria-live="polite" className="sr-only">
          {tipText}
        </span>
        <Button variant="ghost" className="ml-auto px-2" aria-expanded={showTable} onClick={() => setShowTable((shown) => !shown)}>
          {showTable ? t('layouts.chart.hideTable') : t('layouts.chart.showTable')}
        </Button>
      </figcaption>
      <table className={cn('w-full text-caption', !showTable && 'sr-only')}>
        <caption className="sr-only">{title}</caption>
        <thead>
          <tr className="border-b border-border text-ink-muted">
            <th scope="col" className="py-1 text-left font-normal">
              {t('layouts.chart.day')}
            </th>
            <th scope="col" className="py-1 text-right font-normal">
              {valueLabel ?? title}
            </th>
          </tr>
        </thead>
        <tbody>
          {points.map((point) => (
            <tr key={point.label} className="border-b border-border">
              <th scope="row" className="py-1 text-left font-normal text-ink">
                {formatLabel(point.label)}
              </th>
              <td className="py-1 text-right text-ink tabular-nums">{point.value == null ? t('layouts.chart.noValue') : formatValue(point.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  )
}
