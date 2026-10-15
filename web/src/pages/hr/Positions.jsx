import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { ConfirmDialog, FormDialog, HrStatus, NoCompany } from './HrParts'
import { hrKey, PERM, useDepartments, useHrCompany } from './hrData'
import { useHrMutation } from './hrForms'

/** HR-08: the company's positions (code, title, department, grade); archived, never deleted (TEN-06). */
export default function Positions() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [status, setStatus] = useState('active')
  const [search, setSearch] = useState('')
  const term = useDebounced(search.trim(), 300)
  const [dialog, setDialog] = useState(null)
  const params = new URLSearchParams({ company: company?.id ?? '', status, per_page: '100' })
  if (term) params.set('search', term)
  const query = useQuery({ queryKey: [...hrKey, 'positions', params.toString()], queryFn: () => api.get(`hr/positions?${params}`), enabled: Boolean(company) })
  const manage = can(PERM.positionManage)
  const toggle = useHrMutation(({ row, action }) => api.post(`hr/positions/${row.id}/${action}`, {}), { success: (_, { action }) => t(`hr.positions.${action}d`), onDone: () => setDialog(null) })
  if (!company) return <NoCompany />

  return (
    <>
      <PageHeader
        title={t('hr.positions.title')}
        description={t('hr.positions.description', { company: company.name })}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
              {t('hr.positions.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <Tabs value={status} onChange={setStatus} items={['active', 'archived'].map((value) => ({ value, label: t(`hr.statuses.${value}`) }))} className="w-auto" />
        <TextField type="search" label={t('hr.positions.search')} value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.positions.title')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.positions.empty')}
        columns={[
          { key: 'code', label: t('hr.columns.code'), render: (row) => <span className="font-medium tabular-nums">{row.code}</span> },
          { key: 'title', label: t('hr.positions.positionTitle'), render: (row) => row.title },
          { key: 'department', label: t('hr.columns.department'), render: (row) => row.department?.name ?? '' },
          { key: 'grade', label: t('hr.positions.grade'), render: (row) => row.grade ?? '' },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.archived_at ? 'archived' : 'active'} /> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              manage ? (
                <span className="flex justify-end gap-2">
                  {row.archived_at ? null : (
                    <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                      {t('hr.actions.edit')}
                    </Button>
                  )}
                  <Button variant="ghost" icon={row.archived_at ? 'restore' : 'archive'} onClick={() => setDialog({ kind: row.archived_at ? 'restore' : 'archive', row })}>
                    {row.archived_at ? t('hr.actions.restore') : t('hr.actions.archive')}
                  </Button>
                </span>
              ) : null,
          },
        ]}
      />
      {dialog?.kind === 'edit' ? <PositionDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' || dialog?.kind === 'restore' ? (
        <ConfirmDialog
          open
          title={t(`hr.positions.${dialog.kind}Title`, { title: dialog.row.title })}
          text={t(`hr.positions.${dialog.kind}Help`)}
          confirmLabel={dialog.kind === 'archive' ? t('hr.actions.archive') : t('hr.actions.restore')}
          tone={dialog.kind === 'archive' ? 'danger' : 'primary'}
          pending={toggle.isPending}
          error={toggle.error}
          onConfirm={() => toggle.mutate({ row: dialog.row, action: dialog.kind })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

function PositionDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const departments = useDepartments(company.id)
  const [form, setForm] = useState(() => ({ code: row?.code ?? '', title: row?.title ?? '', department_id: row?.department?.id ?? '', grade: row?.grade ?? '' }))
  const save = useHrMutation((body) => (row ? api.patch(`hr/positions/${row.id}`, body) : api.post('hr/positions', { company_id: company.id, ...body })), { success: t('hr.positions.saved'), onDone: onClose })
  const errors = save.errors(['code', 'title', 'department_id', 'grade'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  return (
    <FormDialog
      open
      title={row ? t('hr.positions.editTitle') : t('hr.positions.addTitle')}
      onClose={onClose}
      submitLabel={t('hr.actions.saveChanges')}
      pending={save.isPending}
      disabled={!form.code.trim() || !form.title.trim()}
      errors={errors}
      onSubmit={() => save.mutate({ code: form.code.trim(), title: form.title.trim(), department_id: form.department_id || null, grade: form.grade.trim() || null })}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.columns.code')} value={form.code} required maxLength={40} error={errors.fields.code} onChange={set('code')} />
        <TextField label={t('hr.positions.positionTitle')} value={form.title} required maxLength={150} error={errors.fields.title} onChange={set('title')} />
        <Select
          label={t('hr.fields.department')}
          options={[{ value: '', label: t('hr.pickers.noDepartment') }, ...departments.map((dept) => ({ value: dept.id, label: `${dept.name} · ${dept.code}` }))]}
          value={form.department_id}
          error={errors.fields.department_id}
          onChange={set('department_id')}
        />
        <TextField label={t('hr.positions.grade')} value={form.grade} maxLength={40} error={errors.fields.grade} onChange={set('grade')} />
      </div>
    </FormDialog>
  )
}
