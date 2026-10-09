// Development-only page at /dev/components (see main.jsx): every design-system
// component in its states, with the theme switcher and a test tenant brand,
// for Playwright checks. Never shipped in production builds.
import tokens from '@app/tokens'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Alert,
  ApprovalCard,
  Button,
  Card,
  Checkbox,
  DataTable,
  Dialog,
  KpiTile,
  Money,
  PosTile,
  SaleTotal,
  Select,
  StageTracker,
  StatusBadge,
  Switch,
  SyncStatus,
  Tabs,
  TextField,
  VersionBar,
} from '@/components/ds'
import { formatAmount } from '@/lib/money'
import { useTheme } from '@/theme/ThemeProvider'
import { isDarkTheme, THEMES } from '@/theme/themes'

// A deliberately different brand (burnt brown) to prove tenant overrides apply at runtime.
const TEST_TENANT_PRIMARY = '#7c2d12'

// Sample records: proper nouns and document numbers are data, not UI copy.
const SUPPLIERS = { a: 'Bidco Africa', b: 'Unga Group', c: 'Kapa Oil Refineries' }
const PEOPLE = { requester: 'Amina Otieno', delegate: 'Peter Mwangi' }
const BRANCHES = ['Westlands', 'Kilimani', 'Gombe']
// LAY-06: a layout with v2 live, a discarded v3 and draft v4 (the version strip).
const CONFIG_LIVE = { id: 'v2', version: 2, status: 'published', source: 'draft', published_at: '2026-10-08T09:00:00Z' }
const CONFIG_DOCUMENT = {
  published: CONFIG_LIVE,
  draft: { id: 'v4', version: 4, status: 'draft', source: 'draft' },
  history: [
    { id: 'v4', version: 4, status: 'draft', source: 'draft' },
    { id: 'v3', version: 3, status: 'archived', source: 'draft', discarded_at: '2026-10-08T10:00:00Z' },
    CONFIG_LIVE,
    { id: 'v1', version: 1, status: 'archived', source: 'draft', published_at: '2026-10-07T09:00:00Z' },
  ],
}
const CONFIG_TARGETS = BRANCHES.map((label, index) => ({ type: 'branch', id: `b${index}`, label }))
// Long enough to show the search; accents prove "societe" finds "Société".
const SUPPLIER_CHOICES = ['Bidco Africa', 'Brookside Dairy', 'Kapa Oil Refineries', 'Société Congolaise des Brasseries', 'Unga Group', 'Équateur Distribution', { value: 'old', label: 'Mumias Sugar (archived)', disabled: true }]

function Section({ title, children }) {
  return (
    <section className="flex flex-col gap-4">
      <h2 className="text-h2 text-ink">{title}</h2>
      {children}
    </section>
  )
}

function Row({ children }) {
  return <div className="flex flex-wrap items-center gap-3">{children}</div>
}

export default function ComponentGallery() {
  const { t } = useTranslation()
  const { theme, setTheme, overrides, setOverrides } = useTheme()
  const [tab, setTab] = useState('waiting')
  const [sellOffline, setSellOffline] = useState(true)
  const [lockPrices, setLockPrices] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedId, setSelectedId] = useState('po-2')

  const tenantOn = Boolean(overrides?.primary)
  const toggleTenant = () => {
    if (tenantOn) return setOverrides({})
    const pair = tokens.deriveBrandPair(TEST_TENANT_PRIMARY, { mode: isDarkTheme(theme) ? 'dark' : 'light' })
    setOverrides({ primary: pair.base, 'primary-hover': pair.hover, 'on-primary': pair.on, 'primary-tint': pair.tint })
  }

  const themeLabels = {
    light: t('ds.theme.light'),
    dark: t('ds.theme.dark'),
    executive: t('ds.theme.executive'),
    warm: t('ds.theme.warm'),
  }

  const columns = [
    { key: 'number', label: t('dev.gallery.sample.number') },
    { key: 'supplier', label: t('dev.gallery.sample.supplier') },
    { key: 'status', label: t('dev.gallery.section.status'), render: (row) => row.status },
    { key: 'total', label: t('dev.gallery.sample.total'), align: 'end', numeric: true, render: (row) => <Money amount={row.total} currency={row.currency} /> },
  ]
  const rows = [
    { id: 'po-1', number: 'PO-00229', supplier: SUPPLIERS.a, status: <StatusBadge tone="success">{t('dev.gallery.sample.paid')}</StatusBadge>, total: 1245000, currency: 'KES' },
    { id: 'po-2', number: 'PO-00230', supplier: SUPPLIERS.b, status: <StatusBadge tone="warning">{t('dev.gallery.sample.pending')}</StatusBadge>, total: 13500000, currency: 'CDF' },
    { id: 'po-3', number: 'PO-00231', supplier: SUPPLIERS.c, status: <StatusBadge tone="danger">{t('dev.gallery.sample.overdue')}</StatusBadge>, total: 4850, currency: 'USD' },
  ]

  const stages = [
    t('dev.gallery.sample.stageDraft'),
    t('dev.gallery.sample.stageSubmitted'),
    t('dev.gallery.sample.stageApproved'),
    t('dev.gallery.sample.stageOrdered'),
    t('dev.gallery.sample.stageReceived'),
  ]

  return (
    <main className="mx-auto flex max-w-7xl flex-col gap-10 px-4 py-10 sm:px-10">
      <header className="flex flex-col gap-4">
        <div>
          <h1 className="text-h1 text-ink">{t('dev.gallery.title')}</h1>
          <p className="text-body text-ink-muted">{t('dev.gallery.intro')}</p>
        </div>
        <div className="flex flex-wrap items-end gap-4">
          <div className="flex flex-col gap-1">
            <span className="text-caption text-ink-muted">{t('dev.gallery.theme')}</span>
            <Tabs items={THEMES.map((item) => ({ value: item.id, label: themeLabels[item.id] }))} value={theme} onChange={setTheme} />
          </div>
          <Button variant="secondary" onClick={toggleTenant} aria-pressed={tenantOn}>
            {tenantOn ? t('dev.gallery.tenantOff') : t('dev.gallery.tenantOn')}
          </Button>
        </div>
      </header>

      <Section title={t('dev.gallery.section.actions')}>
        <Row>
          <Button variant="primary">{t('dev.gallery.sample.sendForApproval')}</Button>
          <Button>{t('dev.gallery.sample.saveDraft')}</Button>
          <Button variant="ghost">{t('dev.gallery.sample.clear')}</Button>
          <Button variant="danger">{t('dev.gallery.sample.voidSale')}</Button>
          <Button variant="pay">{t('dev.gallery.sample.completeSale')}</Button>
        </Row>
        <Row>
          <Button icon="check">{t('dev.gallery.sample.markReceived')}</Button>
          <Button variant="primary" loading>
            {t('dev.gallery.sample.saving')}
          </Button>
          <Button variant="primary" disabled>
            {t('dev.gallery.sample.sendForApproval')}
          </Button>
          <Button disabled>{t('dev.gallery.sample.saveDraft')}</Button>
        </Row>
        <Row>
          <Button variant="pay" size="lg">
            {t('dev.gallery.sample.chargeLarge')}
          </Button>
          <Button size="lg">{t('dev.gallery.sample.saveDraft')}</Button>
        </Row>
      </Section>

      <Section title={t('dev.gallery.section.forms')}>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <TextField label={t('dev.gallery.sample.businessName')} defaultValue="Duka Bora Ltd" required />
          <TextField label={t('dev.gallery.sample.kraPin')} help={t('dev.gallery.sample.kraHelp')} />
          <TextField label={t('dev.gallery.sample.kraPin')} defaultValue="P05123" error={t('dev.gallery.sample.kraError')} />
          <TextField label={t('dev.gallery.sample.amount')} prefix="KES" defaultValue={formatAmount(1245000, 'KES')} inputMode="decimal" />
          <TextField label={t('dev.gallery.sample.weight')} suffix="kg" defaultValue="2" inputMode="decimal" />
          <TextField label={t('dev.gallery.sample.companyCode')} defaultValue="KE-001" disabled />
          <Select label={t('dev.gallery.sample.branch')} placeholder={t('dev.gallery.sample.branchPlaceholder')} options={BRANCHES} defaultValue="" />
          <Select label={t('dev.gallery.sample.currency')} options={['KES', 'USD', 'CDF']} defaultValue="KES" />
          <Select label={t('dev.gallery.sample.currency')} options={['KES', 'USD', 'CDF']} defaultValue="" placeholder="—" error={t('dev.gallery.sample.currencyError')} />
          <Select label={t('dev.gallery.sample.supplier')} placeholder={t('dev.gallery.sample.supplierPlaceholder')} options={SUPPLIER_CHOICES} />
          <Select label={t('dev.gallery.sample.currency')} options={['KES', 'USD', 'CDF']} defaultValue="USD" disabled />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-3">
            <Checkbox label={t('dev.gallery.sample.smsReceipts')} help={t('dev.gallery.sample.smsHelp')} defaultChecked />
            <Checkbox label={t('dev.gallery.sample.trackStock')} />
            <Checkbox label={t('dev.gallery.sample.trackStock')} disabled />
          </div>
          <div className="flex flex-col gap-3">
            <Switch checked={sellOffline} onChange={setSellOffline} label={t('dev.gallery.sample.sellOffline')} />
            <Switch checked={lockPrices} onChange={setLockPrices} label={t('dev.gallery.sample.lockPrices')} />
            <Switch checked disabled label={t('dev.gallery.sample.lockPrices')} />
          </div>
        </div>
      </Section>

      <Section title={t('dev.gallery.section.status')}>
        <Row>
          <StatusBadge tone="success">{t('dev.gallery.sample.paid')}</StatusBadge>
          <StatusBadge tone="warning">{t('dev.gallery.sample.pending')}</StatusBadge>
          <StatusBadge tone="danger">{t('dev.gallery.sample.overdue')}</StatusBadge>
          <StatusBadge tone="info">{t('dev.gallery.sample.inProgress')}</StatusBadge>
          <StatusBadge>{t('dev.gallery.sample.draft')}</StatusBadge>
          <StatusBadge tone="accent">{t('dev.gallery.sample.promo')}</StatusBadge>
        </Row>
        <Row>
          <SyncStatus state="online" />
          <SyncStatus state="syncing" pending={1} />
          <SyncStatus state="offline" pending={12} />
        </Row>
        <div className="grid gap-3">
          <Alert tone="info" title={t('dev.gallery.sample.infoTitle')}>
            {t('dev.gallery.sample.infoText')}
          </Alert>
          <Alert tone="success" title={t('dev.gallery.sample.uploadedTitle')} />
          <Alert tone="warning" title={t('dev.gallery.sample.limitTitle')} action={<Button>{t('dev.gallery.sample.upgrade')}</Button>}>
            {t('dev.gallery.sample.limitText')}
          </Alert>
          <Alert tone="danger" title={t('dev.gallery.sample.failedTitle')}>
            {t('dev.gallery.sample.failedText')}
          </Alert>
        </div>
      </Section>

      <Section title={t('dev.gallery.section.data')}>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <KpiTile label={t('dev.gallery.sample.salesToday')} value={<Money amount={8420000} currency="KES" />} delta={12} />
          <KpiTile label={t('dev.gallery.sample.orders')} value="148" delta={-4.5} period={t('dev.gallery.sample.vsYesterday')} />
          <KpiTile label={t('dev.gallery.sample.stockOuts')} value="7" delta={3} lowerIsBetter />
          <KpiTile label={t('dev.gallery.sample.orders')} value="32" />
        </div>
        <Row>
          <Money amount={1245000} currency="KES" />
          <Money amount={13500000} currency="CDF" />
          <Money amount={4850} currency="USD" secondary={{ amount: 13500000, currency: 'CDF' }} />
          <Money amount={-500} currency="KES" tone="danger" />
          <Money amount={4850} currency="USD" size="lg" />
        </Row>
        <DataTable caption={t('dev.gallery.sample.tableCaption')} columns={columns} rows={rows} selectedId={selectedId} onRowClick={(row) => setSelectedId(row.id)} />
        <DataTable columns={columns} rows={[]} emptyText={t('dev.gallery.sample.emptyText')} />
        <DataTable columns={columns} rows={[]} />
      </Section>

      <Section title={t('dev.gallery.section.layout')}>
        <div className="grid gap-5 lg:grid-cols-2">
          <Card title={t('dev.gallery.sample.cardTitle')} subtitle={t('dev.gallery.sample.cardSubtitle')} actions={<Button variant="ghost">{t('dev.gallery.sample.export')}</Button>}>
            <p className="text-body text-ink-muted">{t('dev.gallery.sample.cardBody')}</p>
          </Card>
          <Card>
            <p className="text-body text-ink-muted">{t('dev.gallery.sample.cardBody')}</p>
          </Card>
        </div>
        <Tabs
          items={[
            { value: 'waiting', label: t('dev.gallery.sample.waiting'), count: 4 },
            { value: 'approved', label: t('dev.gallery.sample.approved'), count: 12 },
            { value: 'returned', label: t('dev.gallery.sample.returned') },
          ]}
          value={tab}
          onChange={setTab}
        />
      </Section>

      <Section title={t('dev.gallery.section.dialog')}>
        <Row>
          <Button onClick={() => setDialogOpen(true)}>{t('dev.gallery.sample.openDialog')}</Button>
        </Row>
        <Dialog
          inline
          open
          title={t('dev.gallery.sample.dialogTitle')}
          onClose={() => {}}
          footer={
            <>
              <Button variant="ghost">{t('dev.gallery.sample.keepSale')}</Button>
              <Button variant="danger">{t('dev.gallery.sample.voidSale')}</Button>
            </>
          }
        >
          {t('dev.gallery.sample.dialogBody')}
        </Dialog>
        <Dialog
          open={dialogOpen}
          title={t('dev.gallery.sample.dialogTitle')}
          onClose={() => setDialogOpen(false)}
          footer={
            <>
              <Button variant="ghost" onClick={() => setDialogOpen(false)}>
                {t('dev.gallery.sample.keepSale')}
              </Button>
              <Button variant="danger" onClick={() => setDialogOpen(false)}>
                {t('dev.gallery.sample.voidSale')}
              </Button>
            </>
          }
        >
          {t('dev.gallery.sample.dialogBody')}
        </Dialog>
      </Section>

      <Section title={t('dev.gallery.section.pos')}>
        <div className="grid gap-5 lg:grid-cols-3">
          <div className="grid grid-cols-2 content-start gap-3 lg:col-span-2 lg:grid-cols-3">
            <PosTile name={t('dev.gallery.sample.maize')} price={18500} currency="KES" stock={40} color="var(--success)" />
            <PosTile name={t('dev.gallery.sample.milk')} price={6000} currency="KES" stock={3} color="var(--primary)" />
            <PosTile name={t('dev.gallery.sample.bread')} price={6500} currency="KES" stock={0} color="var(--warning)" />
            <PosTile name={t('dev.gallery.sample.sugar')} price={17000} currency="KES" />
          </div>
          <div className="flex flex-col gap-3">
            <SyncStatus state="offline" pending={3} />
            <SaleTotal currency="KES" subtotal={47500} discount={2500} tax={7200} total={52200} />
            <SaleTotal currency="USD" subtotal={4181} tax={669} total={4850} secondary={{ amount: 13500000, currency: 'CDF' }} />
            <SaleTotal currency="KES" subtotal={0} tax={0} total={0} />
          </div>
        </div>
      </Section>

      <Section title={t('dev.gallery.section.workflow')}>
        <VersionBar document={CONFIG_DOCUMENT} canEdit canPublish copyTargets={CONFIG_TARGETS} saveState="saved" />
        <StageTracker stages={stages} current={stages[2]} />
        <StageTracker stages={stages} current={stages[1]} blocked />
        <div className="grid gap-5 lg:grid-cols-2">
          <ApprovalCard
            docType={t('dev.gallery.sample.purchaseOrder')}
            number="PO-00231"
            title={t('dev.gallery.sample.poTitle')}
            amount={1245000}
            currency="KES"
            requester={PEOPLE.requester}
            branch={BRANCHES[0]}
            due={t('dev.gallery.sample.dueToday')}
          />
          <ApprovalCard
            docType={t('dev.gallery.sample.leave')}
            number="LV-0042"
            title={t('dev.gallery.sample.leaveTitle')}
            requester={PEOPLE.requester}
            onBehalfOf={PEOPLE.delegate}
            overdue
            escalatesIn={t('dev.gallery.sample.inTwoHours')}
          />
        </div>
      </Section>
    </main>
  )
}
