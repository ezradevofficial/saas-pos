import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Checkbox, DataTable, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { useDebounced } from '@/lib/useDebounced'
import { PrintFrame } from './FixedAssetsParts'
import { faKey } from './fixedAssetsData'

/** FA-25: asset labels (tag as a barcode) for the assets chosen, printed in the browser or as a PDF. */
export default function Labels() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 300)
  const [chosen, setChosen] = useState({})
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [printing, setPrinting] = useState(null)
  const query = useQuery({ queryKey: [...faKey, 'labels', term], queryFn: () => api.get(`fixed-assets/assets?per_page=50&status=active${term ? `&search=${encodeURIComponent(term)}` : ''}`) })
  const labels = Object.entries(chosen).filter(([, on]) => on).map(([asset_id]) => ({ asset_id, copies: 1 }))
  const run = async (format) => {
    setBusy(format)
    setError(null)
    try {
      const { blob, filename } = await api.download('fixed-assets/labels', { method: 'POST', body: { labels, format } })
      if (format === 'pdf') saveFile(blob, filename ?? 'asset-labels.pdf')
      else {
        const html = await blob.text()
        setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 }))
      }
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(null)
    }
  }
  const columns = [
    {
      key: 'pick',
      label: t('fixedAssets.labels.pick'),
      render: (row) => <Checkbox label={row.number} checked={Boolean(chosen[row.id])} onChange={(event) => setChosen((current) => ({ ...current, [row.id]: event.target.checked }))} />,
    },
    { key: 'name', label: t('fixedAssets.columns.name'), render: (row) => row.name },
    { key: 'location', label: t('fixedAssets.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'serial', label: t('fixedAssets.fields.serialNumber'), render: (row) => row.serial_number ?? '' },
  ]
  return (
    <>
      <PageHeader title={t('fixedAssets.labels.title')} description={t('fixedAssets.labels.description')} />
      <Card title={t('fixedAssets.labels.choose')} subtitle={t('fixedAssets.labels.chosen', { count: labels.length })}>
        <div className="flex flex-col gap-4">
          {error ? <Alert tone="danger" title={error} /> : null}
          <TextField label={t('fixedAssets.labels.search')} value={search} onChange={(event) => setSearch(event.target.value)} />
          <DataTable caption={t('fixedAssets.labels.choose')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} emptyText={t('fixedAssets.assets.empty')} />
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" icon="print" disabled={!labels.length || Boolean(busy)} loading={busy === 'html'} onClick={() => run('html')}>
              {t('fixedAssets.labels.print', { count: labels.length })}
            </Button>
            <Button variant="ghost" icon="download" disabled={!labels.length || Boolean(busy)} loading={busy === 'pdf'} onClick={() => run('pdf')}>
              {t('fixedAssets.labels.pdf')}
            </Button>
          </div>
        </div>
      </Card>
      <PrintFrame printing={printing} />
    </>
  )
}
