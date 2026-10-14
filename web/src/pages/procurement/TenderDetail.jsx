import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatBytes } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { Amount, Back, CancelDialog, Detail, DetailState, EmailDialog, LineName, Qty, Status, TextAreaField, When } from './ProcurementParts'
import { useDocumentAction, useDownload } from './procurementHelpers'
import { basisPoints, namedOptions, procurementKey, useSuppliers } from './procurementData'

/** PRC-08: invite a bidder: a supplier party (its email unless one is typed) or an outsider by name, email and tax ID. */
function InviteDialog({ tender, open, onClose, onDone }) {
  const { t } = useTranslation()
  const suppliers = useSuppliers()
  const [who, setWho] = useState('party')
  const [party, setParty] = useState('')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [taxId, setTaxId] = useState('')
  const invite = useMutation({
    mutationFn: () =>
      api.post(`procurement/tenders/${tender.id}/bidders`, who === 'party' ? { party_id: party, ...(email.trim() ? { email: email.trim() } : {}) } : { name: name.trim(), email: email.trim(), ...(taxId.trim() ? { tax_id: taxId.trim() } : {}) }),
    onSuccess: (response) => onDone(response),
  })
  const errors = formErrors(invite.error, ['party_id', 'name', 'email', 'tax_id'])
  const ready = who === 'party' ? Boolean(party) : Boolean(name.trim() && email.trim())
  return (
    <Dialog
      open={open}
      title={t('procurement.bidders.inviteTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('procurement.actions.back')}
          </Button>
          <Button variant="primary" icon="mail" loading={invite.isPending} disabled={!ready} onClick={() => invite.mutate()}>
            {t('procurement.bidders.sendLink')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('procurement.bidders.inviteHelp')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select
          label={t('procurement.bidders.who')}
          options={[
            { value: 'party', label: t('procurement.bidders.whoParty') },
            { value: 'outsider', label: t('procurement.bidders.whoOutsider') },
          ]}
          value={who}
          onChange={(event) => setWho(event.target.value)}
        />
        {who === 'party' ? (
          <Select label={t('procurement.fields.supplier')} placeholder={t('procurement.pickers.supplier')} options={namedOptions(suppliers.active)} value={party} required error={errors.fields.party_id} onChange={(event) => setParty(event.target.value)} />
        ) : (
          <>
            <TextField label={t('procurement.fields.bidderName')} value={name} maxLength={200} required error={errors.fields.name} onChange={(event) => setName(event.target.value)} />
            <TextField label={t('procurement.fields.taxId')} value={taxId} maxLength={60} error={errors.fields.tax_id} onChange={(event) => setTaxId(event.target.value)} />
          </>
        )}
        <TextField
          type="email"
          label={t('procurement.fields.email')}
          help={who === 'party' ? t('procurement.bidders.partyEmailHelp') : undefined}
          value={email}
          maxLength={254}
          required={who !== 'party'}
          error={errors.fields.email}
          onChange={(event) => setEmail(event.target.value)}
        />
      </div>
    </Dialog>
  )
}

/** PRC-07, PRC-08: the bidders, their links (resend) and removal. */
function Bidders({ tender, actionKey }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [inviting, setInviting] = useState(false)
  const action = useDocumentAction(actionKey)
  const company = tender.company?.id
  const columns = [
    {
      key: 'name',
      label: t('procurement.columns.bidder'),
      wrap: true,
      render: (bidder) => (
        <span className="flex flex-col">
          <span className="text-ink">{bidder.party?.name ?? bidder.name}</span>
          <span className="text-caption text-ink-muted">{[bidder.email, bidder.tax_id].filter(Boolean).join(' · ')}</span>
        </span>
      ),
    },
    { key: 'source', label: t('procurement.columns.source'), render: (bidder) => t(`procurement.bidders.sources.${bidder.source}`, { defaultValue: bidder.source }) },
    { key: 'link_sent_at', label: t('procurement.columns.linkSent'), render: (bidder) => <When value={bidder.link_sent_at} companyId={company} /> },
    { key: 'last_seen_at', label: t('procurement.columns.lastSeen'), render: (bidder) => <When value={bidder.last_seen_at} companyId={company} /> },
    {
      key: 'actions',
      label: t('procurement.columns.actions'),
      align: 'end',
      render: (bidder) =>
        bidder.removed_at ? (
          <StatusBadge tone="neutral">{t('procurement.bidders.removed')}</StatusBadge>
        ) : tender.can?.invite ? (
          <span className="flex justify-end gap-2">
            {/* PRC-07: a link is resent only while the tender is published and open for bids. */}
            {bidder.can?.resend ? (
              <Button variant="ghost" aria-label={t('procurement.bidders.resendOf', { name: bidder.name })} onClick={() => action.mutate({ path: `procurement/tender-bidders/${bidder.id}/resend`, done: 'procurement.bidders.resent' })}>
                {t('procurement.bidders.resend')}
              </Button>
            ) : null}
            {bidder.can?.remove ? (
              <Button variant="ghost" aria-label={t('procurement.bidders.removeOf', { name: bidder.name })} onClick={() => action.mutate({ path: `procurement/tender-bidders/${bidder.id}/remove`, done: 'procurement.bidders.removedToast' })}>
                {t('procurement.bidders.remove')}
              </Button>
            ) : null}
          </span>
        ) : null,
    },
  ]
  return (
    <Card
      title={t('procurement.bidders.title')}
      subtitle={t(tender.kind === 'open' ? 'procurement.bidders.openHelp' : 'procurement.bidders.invitedHelp')}
      actions={tender.can?.invite ? <Button icon="plus" onClick={() => setInviting(true)}>{t('procurement.bidders.invite')}</Button> : null}
    >
      <DataTable caption={t('procurement.bidders.title')} columns={columns} rows={tender.bidders ?? []} emptyText={t('procurement.bidders.empty')} />
      {tender.can?.invite ? (
        <InviteDialog
          key={inviting ? 'open' : 'closed'}
          tender={tender}
          open={inviting}
          onClose={() => setInviting(false)}
          onDone={async (response) => {
            setInviting(false)
            if (response?.data) queryClient.setQueryData(actionKey, response)
            toast.success(t('procurement.bidders.invited'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </Card>
  )
}

/** PRC-09, decision 22: before the opening, staff see only how many bids arrived (not who sent them). */
function SealedBids({ tender }) {
  const { t } = useTranslation()
  return (
    <Card title={t('procurement.bids.title')}>
      <div className="flex flex-col gap-4">
        <Alert tone="info" title={t('procurement.bids.sealedTitle')}>
          {t('procurement.bids.sealedHelp')}
        </Alert>
        <p className="text-body text-ink">{tender.bids_count ? t('procurement.bids.received', { count: tender.bids_count }) : t('procurement.bids.empty')}</p>
      </div>
    </Card>
  )
}

/** PRC-10: the committee opening: each opener's confirmation; two different openers open the bids at or after the opening time. */
function OpeningPanel({ tender, actionKey }) {
  const { t } = useTranslation()
  const action = useDocumentAction(actionKey)
  const company = tender.company?.id
  const confirmed = (user) => (tender.openings ?? []).find((opening) => opening.user?.id === user.id)
  return (
    <Card title={t('procurement.opening.title')}>
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">
          {t('procurement.opening.help')} <When value={tender.opening_at} companyId={company} />
        </p>
        <ul aria-label={t('procurement.opening.openers')} className="flex flex-col gap-2">
          {(tender.openers ?? []).map((user) => {
            const confirmation = confirmed(user)
            return (
              <li key={user.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2">
                <span className="text-ink">{user.name}</span>
                {confirmation ? (
                  <span className="flex items-center gap-2">
                    <StatusBadge tone="success">{t('procurement.opening.confirmed')}</StatusBadge>
                    <span className="text-caption text-ink-muted">
                      <When value={confirmation.confirmed_at} companyId={company} />
                    </span>
                  </span>
                ) : (
                  <StatusBadge tone="neutral">{t('procurement.opening.waiting')}</StatusBadge>
                )}
              </li>
            )
          })}
        </ul>
        {(tender.openers ?? []).length < 2 ? <Alert tone="warning" title={t('procurement.opening.tooFew')} /> : null}
        {tender.can?.open ? (
          <div>
            <Button variant="pay" icon="lock" loading={action.isPending} onClick={() => action.mutate({ path: `procurement/tenders/${tender.id}/open`, done: 'procurement.opening.done' })}>
              {t('procurement.opening.confirm')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** PRC-09, PRC-10: an opened bid: totals, price per line, technical response and attachments. */
function OpenedBid({ tender, bid }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const download = useDownload()
  const lineOf = (id) => (tender.lines ?? []).find((line) => line.id === id)
  if (bid.status === 'tampered') {
    return (
      <Card title={bid.bidder?.name}>
        <Alert tone="danger" title={t('procurement.bids.tampered')}>
          {t('procurement.bids.tamperedHelp')}
        </Alert>
      </Card>
    )
  }
  return (
    <Card title={bid.bidder?.name} subtitle={t('procurement.bids.version', { version: bid.version })} actions={<Status status={bid.outcome ?? bid.status} />}>
      <div className="flex flex-col gap-4">
        <dl className="grid gap-4 sm:grid-cols-3">
          <Detail label={t('procurement.columns.total')}>
            <Amount value={bid.total} />
          </Detail>
          <Detail label={t('procurement.columns.baseTotal')}>
            <Amount value={bid.base_total} />
          </Detail>
          <Detail label={t('procurement.columns.fingerprint')}>
            <span className="font-mono text-caption break-all text-ink-muted">{bid.fingerprint}</span>
          </Detail>
        </dl>
        {bid.complete === false ? <Alert tone="warning" title={t('procurement.bids.incomplete')} /> : null}
        <DataTable
          caption={t('procurement.bids.linesOf', { name: bid.bidder?.name })}
          columns={[
            { key: 'line', label: t('procurement.columns.item'), wrap: true, render: (row) => (lineOf(row.tender_line_id) ? <LineName line={lineOf(row.tender_line_id)} /> : '') },
            { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (row) => <Qty value={lineOf(row.tender_line_id)?.qty} unit={lineOf(row.tender_line_id)?.uom?.code} /> },
            { key: 'unit_price', label: t('procurement.columns.unitPrice'), align: 'end', render: (row) => <Amount value={row.unit_price} /> },
            { key: 'amount', label: t('procurement.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
          ]}
          rows={(bid.lines ?? []).map((line) => ({ ...line, id: line.tender_line_id }))}
        />
        <div className="flex flex-col gap-1">
          <h4 className="text-label text-ink">{t('procurement.bids.technicalResponse')}</h4>
          <p className="text-body whitespace-pre-line text-ink-muted">{bid.technical_response || t('procurement.bids.noTechnicalResponse')}</p>
        </div>
        {bid.files?.length ? (
          <ul aria-label={t('procurement.bids.filesOf', { name: bid.bidder?.name })} className="flex flex-col gap-2">
            {bid.files.map((file) => (
              <li key={file.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2">
                <span className="flex flex-col">
                  <span className="text-ink">{file.name}</span>
                  <span className="text-caption text-ink-muted">{formatBytes(file.size, locale)}</span>
                </span>
                <Button variant="ghost" icon="download" aria-label={t('procurement.bids.downloadFile', { name: file.name })} onClick={() => download.mutate({ path: `procurement/bids/${bid.id}/files/${file.id}`, name: file.name })}>
                  {t('procurement.bids.download')}
                </Button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </Card>
  )
}

/** PRC-11: an evaluator's score sheet for one bid: per criterion a score (0 to its maximum) and a comment. */
function ScoreSheet({ tender, bid, actionKey }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const mine = (criterionId) => (bid.scores ?? []).find((score) => score.criterion_id === criterionId && score.evaluator_id === user?.id)
  const [scores, setScores] = useState(() => Object.fromEntries((tender.criteria ?? []).map((criterion) => [criterion.id, { score: mine(criterion.id)?.score ?? '', comment: mine(criterion.id)?.comment ?? '' }])))
  const save = useMutation({
    mutationFn: () =>
      api.put(`procurement/bids/${bid.id}/scores`, {
        scores: (tender.criteria ?? [])
          .filter((criterion) => scores[criterion.id]?.score !== '' && scores[criterion.id]?.score !== null)
          .map((criterion) => ({ criterion_id: criterion.id, score: scores[criterion.id].score, ...(scores[criterion.id].comment.trim() ? { comment: scores[criterion.id].comment.trim() } : {}) })),
      }),
    onSuccess: async (response) => {
      if (response?.data) queryClient.setQueryData(actionKey, response)
      toast.success(t('procurement.scores.saved'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
    },
  })
  const set = (id, patch) => setScores((current) => ({ ...current, [id]: { ...current[id], ...patch } }))
  return (
    <fieldset className="flex flex-col gap-3 rounded-md border border-border p-4">
      <legend className="px-1 text-label text-ink">{bid.bidder?.name}</legend>
      {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      {(tender.criteria ?? []).map((criterion) => (
        <div key={criterion.id} className="grid gap-3 sm:grid-cols-3">
          <DecimalInput
            label={t('procurement.scores.scoreOf', { criterion: criterion.name, max: criterion.max_score })}
            help={t('procurement.scores.weight', { weight: criterion.weight })}
            maxDecimals={2}
            maxIntegerDigits={4}
            positive={false}
            value={scores[criterion.id]?.score ?? ''}
            onChange={(value) => set(criterion.id, { score: value ?? '' })}
          />
          <TextField label={t('procurement.scores.commentOf', { criterion: criterion.name })} value={scores[criterion.id]?.comment ?? ''} maxLength={1000} onChange={(event) => set(criterion.id, { comment: event.target.value })} className="sm:col-span-2" />
        </div>
      ))}
      <div>
        <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
          {t('procurement.scores.save', { name: bid.bidder?.name })}
        </Button>
      </div>
    </fieldset>
  )
}

/** PRC-11: award a bid; a reason is needed when it is not the top-ranked. */
function AwardDialog({ bid, open, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const top = bid?.rank === 1
  const award = useMutation({ mutationFn: () => api.post(`procurement/bids/${bid.id}/award`, reason.trim() ? { reason: reason.trim() } : {}), onSuccess: (response) => onDone(response) })
  if (!bid) return null
  return (
    <Dialog
      open={open}
      title={t('procurement.award.title', { name: bid.bidder?.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('procurement.actions.back')}
          </Button>
          <Button variant="pay" loading={award.isPending} disabled={!top && !reason.trim()} onClick={() => award.mutate()}>
            {t('procurement.award.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('procurement.award.help')}</p>
        {award.isError ? <Alert tone="danger" title={errorMessage(award.error)} /> : null}
        {top ? null : <Alert tone="warning" title={t('procurement.award.notTop')} />}
        <TextAreaField label={t('procurement.award.reason')} value={reason} maxLength={1000} required={!top} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** PRC-11, PRC-12: the ranking (technical, financial and combined scores), the award, and the award and regret letters. */
function Ranking({ tender, actionKey }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const download = useDownload()
  const [awarding, setAwarding] = useState(null)
  const [emailing, setEmailing] = useState(null)
  const bids = (tender.bids ?? []).filter((bid) => !bid.sealed && bid.status !== 'withdrawn').sort((a, b) => (a.rank ?? 999) - (b.rank ?? 999))
  const columns = [
    { key: 'rank', label: t('procurement.columns.rank'), numeric: true, render: (bid) => bid.rank ?? '—' },
    { key: 'bidder', label: t('procurement.columns.bidder'), render: (bid) => bid.bidder?.name },
    { key: 'base_total', label: t('procurement.columns.baseTotal'), align: 'end', render: (bid) => <Amount value={bid.base_total} /> },
    { key: 'technical', label: t('procurement.columns.technicalScore'), align: 'end', numeric: true, render: (bid) => basisPoints(bid.technical_score_bp, locale) },
    { key: 'financial', label: t('procurement.columns.financialScore'), align: 'end', numeric: true, render: (bid) => basisPoints(bid.financial_score_bp, locale) },
    { key: 'combined', label: t('procurement.columns.combinedScore'), align: 'end', numeric: true, render: (bid) => basisPoints(bid.combined_score_bp, locale) },
    { key: 'outcome', label: t('procurement.columns.outcome'), render: (bid) => <Status status={bid.outcome ?? bid.status} /> },
    {
      key: 'actions',
      label: t('procurement.columns.actions'),
      align: 'end',
      render: (bid) => (
        <span className="flex justify-end gap-2">
          {tender.can?.award && bid.status === 'opened' && bid.outcome !== 'disqualified' ? (
            <Button variant="primary" aria-label={t('procurement.award.awardTo', { name: bid.bidder?.name })} onClick={() => setAwarding(bid)}>
              {t('procurement.award.action')}
            </Button>
          ) : null}
          {tender.can?.letter && bid.outcome && bid.outcome !== 'disqualified' ? (
            <Button variant="ghost" icon="print" aria-label={t('procurement.letters.downloadOf', { name: bid.bidder?.name })} onClick={() => download.mutate({ path: `procurement/bids/${bid.id}/letter`, name: `${tender.number}-${bid.bidder?.name}.pdf` })}>
              {t('procurement.letters.download')}
            </Button>
          ) : null}
          {tender.can?.email_letter && bid.outcome && bid.outcome !== 'disqualified' ? (
            <Button variant="ghost" icon="mail" aria-label={t('procurement.letters.emailOf', { name: bid.bidder?.name })} onClick={() => setEmailing(bid)}>
              {t('procurement.letters.email')}
            </Button>
          ) : null}
        </span>
      ),
    },
  ]
  return (
    <Card title={t('procurement.ranking.title')} subtitle={t('procurement.ranking.subtitle', { technical: tender.technical_weight, financial: tender.financial_weight, pass: tender.pass_mark })}>
      <DataTable caption={t('procurement.ranking.title')} columns={columns} rows={bids} emptyText={t('procurement.bids.empty')} />
      {tender.award_reason ? <p className="pt-3 text-caption text-ink-muted">{t('procurement.award.reasonGiven', { reason: tender.award_reason })}</p> : null}
      <AwardDialog
        key={awarding?.id ?? 'none'}
        bid={awarding}
        open={Boolean(awarding)}
        onClose={() => setAwarding(null)}
        onDone={async (response) => {
          setAwarding(null)
          if (response?.data) queryClient.setQueryData(actionKey, response)
          toast.success(t('procurement.award.done'))
          await queryClient.invalidateQueries({ queryKey: procurementKey })
        }}
      />
      {emailing ? (
        <EmailDialog
          open
          title={t('procurement.letters.emailTitle', { name: emailing.bidder?.name })}
          help={t('procurement.letters.emailHelp')}
          path={`procurement/bids/${emailing.id}/letter/email`}
          onClose={() => setEmailing(null)}
          onDone={(response) => {
            setEmailing(null)
            toast.success(t('procurement.letters.sent', { email: response?.data?.sent_to ?? '' }))
          }}
        />
      ) : null}
    </Card>
  )
}

/**
 * PRC-06 to PRC-12: one tender: its terms, lines and criteria, Publish
 * and Edit while a draft, the public notice link of an open tender, the
 * bidders, the sealed bid list, the committee opening, and after the
 * opening the bids, the evaluators' score sheets, the ranking, the award
 * and the letters.
 */
export default function TenderDetail() {
  const { t } = useTranslation()
  const { tenderId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const key = [...procurementKey, 'tender', tenderId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/tenders/${tenderId}`) })
  const action = useDocumentAction(key)
  const tender = query.data?.data
  const back = <Back to="/procurement/tenders">{t('procurement.tenders.back')}</Back>
  if (!tender) return <DetailState query={query} back={back} notFound={t('procurement.tender.notFound')} />

  const company = tender.company?.id
  const opened = Boolean(tender.opened_at)
  const live = !['draft', 'cancelled'].includes(tender.status)
  const openedBids = (tender.bids ?? []).filter((bid) => !bid.sealed && bid.status !== 'withdrawn')
  const copyNotice = async () => {
    try {
      await navigator.clipboard.writeText(tender.notice_url)
      toast.success(t('procurement.tender.noticeCopied'))
    } catch {
      toast.error(t('procurement.tender.noticeCopyFailed'))
    }
  }

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.tender.eyebrow')}
        title={tender.number ?? t('procurement.draftNumber')}
        description={<Status status={tender.status} />}
        actions={
          <>
            {tender.can?.edit ? <Button icon="edit" onClick={() => navigate(`/procurement/tenders/${tender.id}/edit`)}>{t('procurement.actions.edit')}</Button> : null}
            {tender.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.tender.cancel')}
              </Button>
            ) : null}
            {tender.can?.publish ? (
              <Button variant="pay" loading={action.isPending} onClick={() => action.mutate({ path: `procurement/tenders/${tender.id}/publish`, done: 'procurement.tender.published' })}>
                {t('procurement.tender.publish')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.title')}>{tender.title}</Detail>
            <Detail label={t('procurement.fields.branch')}>{[tender.company?.name, tender.branch?.name].filter(Boolean).join(' · ')}</Detail>
            <Detail label={t('procurement.fields.kind')}>{t(`procurement.tenderKinds.${tender.kind}`, { defaultValue: tender.kind })}</Detail>
            <Detail label={t('procurement.fields.currency')}>{tender.currency}</Detail>
            <Detail label={t('procurement.fields.deadline')}>
              <When value={tender.deadline_at} companyId={company} />
            </Detail>
            <Detail label={t('procurement.fields.opening')}>
              <When value={tender.opening_at} companyId={company} />
            </Detail>
            <Detail label={t('procurement.tender.weights')}>{t('procurement.tender.weightsValue', { technical: tender.technical_weight, financial: tender.financial_weight })}</Detail>
            <Detail label={t('procurement.fields.passMark')}>{tender.pass_mark}</Detail>
            <Detail label={t('procurement.tender.committee')}>
              <span className="flex flex-col">
                <span>{t('procurement.tender.openersList', { names: (tender.openers ?? []).map((user) => user.name).join(', ') || '—' })}</span>
                <span>{t('procurement.tender.evaluatorsList', { names: (tender.evaluators ?? []).map((user) => user.name).join(', ') || '—' })}</span>
              </span>
            </Detail>
            {tender.description ? <Detail label={t('procurement.fields.description')}>{tender.description}</Detail> : null}
            {tender.order ? (
              <Detail label={t('procurement.tender.awardOrder')}>
                <Link to={`/procurement/orders/${tender.order.id}`} className="text-primary hover:text-primary-hover">
                  {tender.order.number ?? t('procurement.draftNumber')}
                </Link>
              </Detail>
            ) : null}
            {tender.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={tender.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{tender.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        {tender.notice_url ? (
          <Card title={t('procurement.tender.notice')} subtitle={t('procurement.tender.noticeHelp')}>
            <div className="flex flex-wrap items-center gap-3">
              <a href={tender.notice_url} target="_blank" rel="noreferrer" className="font-mono text-caption break-all text-primary hover:text-primary-hover">
                {tender.notice_url}
              </a>
              <Button icon="copy" onClick={copyNotice}>
                {t('procurement.tender.copyNotice')}
              </Button>
            </div>
          </Card>
        ) : null}
        <Card title={t('procurement.lines.title')}>
          <DataTable
            caption={t('procurement.lines.title')}
            columns={[
              { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
              { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
              { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
            ]}
            rows={tender.lines ?? []}
          />
        </Card>
        <Card title={t('procurement.tender.criteria')}>
          <DataTable
            caption={t('procurement.tender.criteria')}
            columns={[
              { key: 'name', label: t('procurement.fields.criterionName'), wrap: true, render: (criterion) => criterion.name },
              { key: 'weight', label: t('procurement.fields.weight'), align: 'end', numeric: true, render: (criterion) => `${criterion.weight} %` },
              { key: 'max_score', label: t('procurement.fields.maxScore'), align: 'end', numeric: true, render: (criterion) => criterion.max_score },
            ]}
            rows={tender.criteria ?? []}
            emptyText={t('procurement.tender.noCriteria')}
          />
        </Card>
        {tender.status !== 'draft' || tender.can?.invite ? <Bidders tender={tender} actionKey={key} /> : null}
        {live && !opened ? (
          <>
            <SealedBids tender={tender} />
            {tender.status !== 'cancelled' ? <OpeningPanel tender={tender} actionKey={key} /> : null}
          </>
        ) : null}
        {opened ? (
          <>
            <section aria-label={t('procurement.bids.openedTitle')} className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('procurement.bids.openedTitle')}</h2>
              <p className="text-body text-ink-muted">
                {t('procurement.bids.openedAt')} <When value={tender.opened_at} companyId={company} />
              </p>
              {openedBids.map((bid) => (
                <OpenedBid key={bid.id} tender={tender} bid={bid} />
              ))}
            </section>
            {tender.can?.score ? (
              <Card title={t('procurement.scores.title')} subtitle={t('procurement.scores.help')}>
                <div className="flex flex-col gap-4">
                  {openedBids
                    .filter((bid) => bid.status === 'opened')
                    .map((bid) => (
                      <ScoreSheet key={bid.id} tender={tender} bid={bid} actionKey={key} />
                    ))}
                </div>
              </Card>
            ) : null}
            <Ranking tender={tender} actionKey={key} />
          </>
        ) : null}
      </div>
      {tender.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.tender.cancelTitle', { number: tender.number ?? t('procurement.draftNumber') })}
          help={t('procurement.tender.cancelHelp')}
          path={`procurement/tenders/${tender.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.tender.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
