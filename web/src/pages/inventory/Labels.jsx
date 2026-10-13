import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DecimalInput, Select } from '@/components/ds'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { saveFile } from '@/lib/files'
import { useLocale } from '@/lib/useLocale'
import { ItemPicker } from './InventoryParts'
import { useUnitOptions } from './inventoryHelpers'
import { useItemStock, usePlaces, usePriceLists } from './inventoryData'

/** The print frame's sandbox (TPL-04): never allow-same-origin. */
const PRINT_SANDBOX = 'allow-scripts allow-modals'

/** The labels' HTML with its scripts removed and one call to print once it has loaded. */
function printable(html) {
  const clean = String(html ?? '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  const call = '<script>window.addEventListener("load", function () { window.print() })</script>'
  return clean.includes('</body>') ? clean.replace('</body>', `${call}</body>`) : clean + call
}

let nextKey = 0
const blankLabel = () => ({ key: `label-${(nextKey += 1)}`, item: null, uom_id: '', batch_id: '', copies: '1' })

/** One label line: item, unit and batch (optional) and the number of copies. */
function LabelLine({ line, index, onChange, onRemove, removable }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const stock = useItemStock(line.item?.id)
  const detail = stock.data?.data
  const units = useUnitOptions(line.item, detail?.item?.base_uom)
  const batches = detail?.batches ?? []
  const number = index + 1
  return (
    <fieldset className="flex flex-col gap-3 rounded-md border border-border p-4">
      <legend className="px-1 text-label text-ink">{t('inventory.labels.label', { number })}</legend>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <ItemPicker label={t('inventory.fields.item')} value={line.item} type="" required onChange={(item) => onChange({ ...line, item, uom_id: '', batch_id: '' })} />
        <Select
          label={t('inventory.fields.unit')}
          placeholder={t('inventory.labels.anyUnit')}
          options={units}
          value={line.uom_id}
          disabled={!line.item}
          onChange={(event) => onChange({ ...line, uom_id: event.target.value })}
        />
        {batches.length ? (
          <Select
            label={t('inventory.fields.batch')}
            options={[
              { value: '', label: t('inventory.labels.noBatch') },
              ...batches.map((batch) => ({ value: batch.id, label: [batch.batch_number, batch.expires_on ? formatCalendarDate(batch.expires_on, locale) : null].filter(Boolean).join(' · ') })),
            ]}
            value={line.batch_id}
            onChange={(event) => onChange({ ...line, batch_id: event.target.value })}
          />
        ) : null}
        <DecimalInput
          label={t('inventory.labels.copies')}
          value={line.copies}
          maxDecimals={0}
          maxIntegerDigits={3}
          required
          onChange={(value) => onChange({ ...line, copies: value ?? '' })}
        />
      </div>
      {removable ? (
        <div>
          <Button variant="ghost" icon="remove" aria-label={t('inventory.labels.remove', { number })} onClick={onRemove}>
            {t('inventory.lines.removeShort')}
          </Button>
        </div>
      ) : null}
    </fieldset>
  )
}

/**
 * INV-21, TPL-04: barcode labels through the label template: items, units
 * and batches, copies, and the price list the price comes from. Print
 * shows the labels in a sandboxed frame that prints itself; Download PDF
 * saves them as one file.
 */
export default function Labels() {
  const { t } = useTranslation()
  const places = usePlaces()
  const { company: switcherCompany } = useCompanySelection()
  const [lines, setLines] = useState(() => [blankLabel()])
  const [companyId, setCompanyId] = useState('')
  const [priceListId, setPriceListId] = useState('')
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [printing, setPrinting] = useState(null) // { html, n }
  const company = companyId || switcherCompany?.id || places.companies.find((entry) => !entry.archived_at)?.id || ''
  const priceLists = usePriceLists(company)
  const ready = lines.length > 0 && lines.every((line) => line.item && /^[1-9]\d*$/.test(String(line.copies)))

  const body = (format) => ({
    labels: lines.map((line) => ({
      item_id: line.item.id,
      ...(line.uom_id ? { uom_id: line.uom_id } : {}),
      ...(line.batch_id ? { batch_id: line.batch_id } : {}),
      copies: Number.parseInt(line.copies, 10),
    })),
    ...(priceListId ? { price_list_id: priceListId } : {}),
    format,
  })

  const run = async (name, action) => {
    setError(null)
    setBusy(name)
    try {
      await action()
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(null)
    }
  }
  const print = () =>
    run('print', async () => {
      const { blob } = await api.download('inventory/labels', { method: 'POST', body: body('html') })
      const html = await blob.text()
      setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 }))
    })
  const pdf = () =>
    run('pdf', async () => {
      const { blob, filename } = await api.download('inventory/labels', { method: 'POST', body: body('pdf') })
      saveFile(blob, filename ?? 'labels.pdf')
    })

  return (
    <>
      <PageHeader title={t('inventory.labels.title')} description={t('inventory.labels.description')} />
      <div className="flex flex-col gap-5">
        <Card title={t('inventory.labels.priceTitle')} subtitle={t('inventory.labels.priceHelp')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {places.companies.length > 1 ? (
              <Select
                label={t('inventory.fields.company')}
                options={places.companies.filter((entry) => !entry.archived_at).map((entry) => ({ value: entry.id, label: entry.name }))}
                value={company}
                onChange={(event) => {
                  setCompanyId(event.target.value)
                  setPriceListId('')
                }}
              />
            ) : null}
            <Select
              label={t('inventory.fields.priceList')}
              options={[{ value: '', label: t('inventory.labels.defaultPrice') }, ...priceLists.all.map((list) => ({ value: list.id, label: list.name }))]}
              value={priceListId}
              disabled={!company}
              onChange={(event) => setPriceListId(event.target.value)}
            />
          </div>
        </Card>
        <section aria-label={t('inventory.labels.lines')} className="flex flex-col gap-3">
          {lines.map((line, index) => (
            <LabelLine
              key={line.key}
              line={line}
              index={index}
              removable={lines.length > 1}
              onChange={(next) => setLines((current) => current.map((entry) => (entry.key === line.key ? next : entry)))}
              onRemove={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}
            />
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, blankLabel()])}>
              {t('inventory.labels.add')}
            </Button>
          </div>
        </section>
        {error ? <Alert tone="danger" title={error} /> : null}
        <div className="flex flex-wrap gap-2">
          <Button icon="download" loading={busy === 'pdf'} disabled={!ready || Boolean(busy)} onClick={pdf}>
            {t('inventory.labels.downloadPdf')}
          </Button>
          <Button variant="primary" icon="print" loading={busy === 'print'} disabled={!ready || Boolean(busy)} onClick={print}>
            {t('inventory.labels.print')}
          </Button>
        </div>
      </div>
      {printing ? (
        // The labels print themselves in a frame with an opaque origin: allow-scripts runs only the
        // print call added here (the server escapes every value), and without allow-same-origin the
        // frame can reach nothing of this page; allow-modals opens the print dialog.
        <iframe
          key={printing.n}
          title={t('inventory.labels.printFrame')}
          sandbox={PRINT_SANDBOX}
          srcDoc={printable(printing.html)}
          aria-hidden="true"
          tabIndex={-1}
          className="sr-only"
        />
      ) : null}
    </>
  )
}
