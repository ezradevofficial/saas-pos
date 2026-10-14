import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { EmployeeFields } from './Employees'
import { AppraisalsTab, AttendanceTab, ChecklistsTab, ContractsTab, DisciplinaryTab, DocumentsTab, LeaveTab } from './EmployeeTabs'
import { Back, CalendarDate, ConfirmDialog, Detail, DetailGrid, DetailState, Empty, FormDialog, HrStatus, PersonalForm, TextAreaField } from './HrParts'
import { employeeLabel, enumLabel, enumOptions, hrKey, PAY_ACCOUNT_KINDS, PERM, TERMINATION_REASONS, today, useCurrencyCodes } from './hrData'
import { ADDRESS_FIELDS, EMPLOYEE_FIELDS, employeeBody, employeeForm, PERSONAL_FIELDS, personalBody, personalForm, useHrMutation } from './hrForms'

/**
 * HR-02 to HR-11: an employee's profile. The API leaves out what the
 * reader may not see (sensitive groups, field rules: HR-09), and says in
 * `access` what they may see and change; tabs follow both.
 */
export default function EmployeeProfile() {
  const { t } = useTranslation()
  const { employeeId } = useParams()
  const { can } = usePermissions()
  const [params, setParams] = useSearchParams()
  const query = useQuery({ queryKey: [...hrKey, 'employee', employeeId], queryFn: () => api.get(`hr/employees/${employeeId}`) })
  const back = <Back to="/hr/employees">{t('hr.employees.back')}</Back>
  if (!query.data) return <DetailState query={query} back={back} notFound={t('hr.employees.notFound')} />

  const employee = query.data.data
  const types = query.data.meta?.identifier_types ?? []
  const access = employee.access ?? {}
  const tabs = [
    { value: 'overview', show: true },
    { value: 'personal', show: true },
    { value: 'identity', show: 'identifiers' in employee },
    { value: 'payAccounts', show: 'pay_accounts' in employee },
    { value: 'contracts', show: can([PERM.contractView, PERM.contractManage]) },
    { value: 'leave', show: can(PERM.leaveView) },
    { value: 'attendance', show: can(PERM.attendanceView) },
    { value: 'documents', show: can([PERM.documentView, PERM.documentManage]) },
    { value: 'checklists', show: can(PERM.checklistView) },
    { value: 'disciplinary', show: Boolean(access.disciplinary?.view) },
    { value: 'appraisals', show: can(PERM.appraisalView) },
  ].filter((tab) => tab.show)
  const tab = tabs.some((entry) => entry.value === params.get('tab')) ? params.get('tab') : 'overview'

  return (
    <>
      {back}
      <PageHeader
        title={employee.name}
        eyebrow={employee.number}
        description={[employee.position?.title, employee.department?.name, employee.branch?.name ?? employee.company?.name].filter(Boolean).join(' · ')}
        actions={<HrStatus status={employee.status} />}
      />
      <Tabs value={tab} onChange={(next) => setParams({ tab: next }, { replace: true })} items={tabs.map((entry) => ({ value: entry.value, label: t(`hr.profile.tabs.${entry.value}`) }))} />
      <div className="flex flex-col gap-5">
        {tab === 'overview' ? <Overview employee={employee} /> : null}
        {tab === 'personal' ? <Personal employee={employee} /> : null}
        {tab === 'identity' ? <Identity employee={employee} types={types} /> : null}
        {tab === 'payAccounts' ? <PayAccounts employee={employee} /> : null}
        {tab === 'contracts' ? <ContractsTab employee={employee} /> : null}
        {tab === 'leave' ? <LeaveTab employee={employee} /> : null}
        {tab === 'attendance' ? <AttendanceTab employee={employee} /> : null}
        {tab === 'documents' ? <DocumentsTab employee={employee} /> : null}
        {tab === 'checklists' ? <ChecklistsTab employee={employee} /> : null}
        {tab === 'disciplinary' ? <DisciplinaryTab employee={employee} /> : null}
        {tab === 'appraisals' ? <AppraisalsTab employee={employee} /> : null}
      </div>
    </>
  )
}

/** HR-02, HR-05: the record, its edit, terminate, rehire and the linked user (AUTH-12). */
function Overview({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const close = () => setDialog(null)
  const active = employee.status === 'active'
  const methods = (employee.clock_methods ?? []).map((method) => enumLabel(t, 'clockMethods', method)).join(', ')
  return (
    <>
      <Card
        title={t('hr.profile.record')}
        actions={
          <>
            {can(PERM.employeeEdit) ? (
              <Button icon="edit" onClick={() => setDialog('edit')}>
                {t('hr.actions.edit')}
              </Button>
            ) : null}
            {can(PERM.employeeTerminate) ? (
              active ? (
                <Button variant="danger" onClick={() => setDialog('terminate')}>
                  {t('hr.profile.terminate')}
                </Button>
              ) : (
                <Button onClick={() => setDialog('rehire')}>{t('hr.profile.rehire')}</Button>
              )
            ) : null}
          </>
        }
      >
        <DetailGrid>
          <Detail label={t('hr.fields.company')}>{employee.company?.name ?? '—'}</Detail>
          <Detail label={t('hr.fields.branch')}>{employee.branch?.name ?? '—'}</Detail>
          <Detail label={t('hr.fields.location')}>{employee.location?.name ?? '—'}</Detail>
          <Detail label={t('hr.fields.department')}>{employee.department?.name ?? '—'}</Detail>
          <Detail label={t('hr.fields.position')}>{employee.position?.title ?? '—'}</Detail>
          <Detail label={t('hr.fields.lineManager')}>{employee.reports_to ? employeeLabel(employee.reports_to) : '—'}</Detail>
          <Detail label={t('hr.fields.gender')}>{enumLabel(t, 'genders', employee.gender) || '—'}</Detail>
          <Detail label={t('hr.fields.hireDate')}>
            <CalendarDate value={employee.hire_date} />
          </Detail>
          {employee.termination_date ? (
            <Detail label={t('hr.fields.terminationDate')}>
              <CalendarDate value={employee.termination_date} /> · {enumLabel(t, 'terminationReasons', employee.termination_reason)}
            </Detail>
          ) : null}
          <Detail label={t('hr.fields.workEmail')}>{employee.work_email ?? '—'}</Detail>
          <Detail label={t('hr.fields.workPhone')}>{employee.work_phone ?? '—'}</Detail>
          <Detail label={t('hr.fields.clockMethods')}>{methods || '—'}</Detail>
          <Detail label={t('hr.fields.biometricRef')}>{employee.biometric_ref ?? '—'}</Detail>
        </DetailGrid>
      </Card>
      <Card
        title={t('hr.profile.selfService')}
        subtitle={t('hr.profile.selfServiceHelp')}
        actions={
          can(PERM.employeeEdit) ? (
            employee.user ? (
              <Button variant="ghost" onClick={() => setDialog('unlink')}>
                {t('hr.profile.unlinkUser')}
              </Button>
            ) : (
              <Button icon="link" onClick={() => setDialog('link')}>
                {t('hr.profile.linkUser')}
              </Button>
            )
          ) : null
        }
      >
        <p className="text-body text-ink">{employee.user ? t('hr.profile.linkedTo', { name: employee.user.name }) : t('hr.profile.notLinked')}</p>
      </Card>
      {dialog === 'edit' ? <EditEmployee employee={employee} onClose={close} /> : null}
      {dialog === 'terminate' ? <Terminate employee={employee} onClose={close} /> : null}
      {dialog === 'rehire' ? <Rehire employee={employee} onClose={close} /> : null}
      {dialog === 'link' ? <LinkUser employee={employee} onClose={close} /> : null}
      {dialog === 'unlink' ? <UnlinkUser employee={employee} onClose={close} /> : null}
    </>
  )
}

function EditEmployee({ employee, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => employeeForm(employee))
  const save = useHrMutation((body) => api.patch(`hr/employees/${employee.id}`, body), { success: t('hr.profile.saved'), onDone: onClose })
  return (
    <FormDialog open size="lg" title={t('hr.profile.editTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} errors={save.errors(EMPLOYEE_FIELDS)} onSubmit={() => save.mutate(employeeBody(form))}>
      <EmployeeFields form={form} setForm={setForm} errors={save.errors(EMPLOYEE_FIELDS)} companyId={employee.company?.id} employeeId={employee.id} />
    </FormDialog>
  )
}

function Terminate({ employee, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ termination_date: today(), reason: '', note: '' })
  const save = useHrMutation((body) => api.post(`hr/employees/${employee.id}/terminate`, body), { success: t('hr.profile.terminated'), onDone: onClose })
  const errors = save.errors(['termination_date', 'reason', 'note'])
  return (
    <FormDialog
      open
      title={t('hr.profile.terminateTitle', { name: employee.name })}
      onClose={onClose}
      variant="danger"
      submitLabel={t('hr.profile.terminate')}
      pending={save.isPending}
      disabled={!form.termination_date || !form.reason}
      errors={errors}
      onSubmit={() => save.mutate({ ...form, note: form.note.trim() || null })}
    >
      <p>{t('hr.profile.terminateHelp')}</p>
      <TextField type="date" label={t('hr.fields.terminationDate')} value={form.termination_date} required error={errors.fields.termination_date} onChange={(event) => setForm({ ...form, termination_date: event.target.value })} />
      <Select label={t('hr.fields.terminationReason')} placeholder={t('hr.pickers.reason')} options={enumOptions(t, 'terminationReasons', TERMINATION_REASONS)} value={form.reason} required error={errors.fields.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
      <TextAreaField label={t('hr.fields.note')} value={form.note} maxLength={1000} error={errors.fields.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
    </FormDialog>
  )
}

function Rehire({ employee, onClose }) {
  const { t } = useTranslation()
  const [hireDate, setHireDate] = useState(today())
  const save = useHrMutation((body) => api.post(`hr/employees/${employee.id}/rehire`, body), { success: t('hr.profile.rehired'), onDone: onClose })
  const errors = save.errors(['hire_date'])
  return (
    <FormDialog open title={t('hr.profile.rehireTitle', { name: employee.name })} onClose={onClose} submitLabel={t('hr.profile.rehire')} pending={save.isPending} disabled={!hireDate} errors={errors} onSubmit={() => save.mutate({ hire_date: hireDate })}>
      <TextField type="date" label={t('hr.fields.hireDate')} value={hireDate} required error={errors.fields.hire_date} onChange={(event) => setHireDate(event.target.value)} />
    </FormDialog>
  )
}

/** AUTH-12: link an existing user of the tenant (decision 11: manual; the invitation offers the self-service role). */
function LinkUser({ employee, onClose }) {
  const { t } = useTranslation()
  const [userId, setUserId] = useState('')
  const users = useQuery({ queryKey: ['users', 'options', 'active'], queryFn: () => api.get('users?status=active&per_page=200'), retry: false })
  const save = useHrMutation((body) => api.patch(`hr/employees/${employee.id}`, body), { success: t('hr.profile.linked'), onDone: onClose })
  const errors = save.errors(['user_id'])
  return (
    <FormDialog open title={t('hr.profile.linkTitle')} onClose={onClose} submitLabel={t('hr.profile.linkUser')} pending={save.isPending} disabled={!userId} errors={errors} onSubmit={() => save.mutate({ user_id: userId })}>
      <p>{t('hr.profile.linkHelp')}</p>
      <Select
        label={t('hr.fields.user')}
        placeholder={t('hr.pickers.user')}
        options={(users.data?.data ?? []).map((user) => ({ value: user.id, label: [user.name, user.email ?? user.phone].filter(Boolean).join(' · ') }))}
        value={userId}
        required
        error={errors.fields.user_id}
        onChange={(event) => setUserId(event.target.value)}
      />
    </FormDialog>
  )
}

function UnlinkUser({ employee, onClose }) {
  const { t } = useTranslation()
  const save = useHrMutation(() => api.patch(`hr/employees/${employee.id}`, { user_id: null }), { success: t('hr.profile.unlinked'), onDone: onClose })
  return (
    <ConfirmDialog open title={t('hr.profile.unlinkTitle')} text={t('hr.profile.unlinkHelp', { name: employee.user?.name ?? '' })} confirmLabel={t('hr.profile.unlinkUser')} tone="danger" pending={save.isPending} error={save.error} onConfirm={() => save.mutate()} onClose={onClose} />
  )
}

/**
 * HR-02, HR-06, HR-09: personal contacts, address and emergency contacts;
 * date of birth and medical notes only when the API includes them.
 */
function Personal({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(false)
  const access = employee.access ?? {}
  const address = ADDRESS_FIELDS.map((key) => employee.address?.[key]).filter(Boolean).join(', ')
  return (
    <>
      <Card
        title={t('hr.profile.tabs.personal')}
        actions={
          can(PERM.employeeEdit) ? (
            <Button icon="edit" onClick={() => setEditing(true)}>
              {t('hr.actions.edit')}
            </Button>
          ) : null
        }
      >
        <DetailGrid>
          {'personal_email' in employee ? <Detail label={t('hr.fields.personalEmail')}>{employee.personal_email ?? '—'}</Detail> : null}
          {'personal_phone' in employee ? <Detail label={t('hr.fields.personalPhone')}>{employee.personal_phone ?? '—'}</Detail> : null}
          {'address' in employee ? <Detail label={t('hr.personal.address')}>{address || '—'}</Detail> : null}
          {'date_of_birth' in employee ? (
            <Detail label={t('hr.fields.dateOfBirth')}>
              <CalendarDate value={employee.date_of_birth} />
            </Detail>
          ) : null}
        </DetailGrid>
      </Card>
      {'emergency_contacts' in employee ? <EmergencyContacts contacts={employee.emergency_contacts} /> : null}
      {'medical_notes' in employee ? (
        <Card title={t('hr.fields.medicalNotes')}>
          <p className="text-body whitespace-pre-line text-ink">{employee.medical_notes || t('hr.personal.noMedicalNotes')}</p>
        </Card>
      ) : null}
      {editing ? <EditPersonal employee={employee} access={access} onClose={() => setEditing(false)} /> : null}
    </>
  )
}

export function EmergencyContacts({ contacts }) {
  const { t } = useTranslation()
  return (
    <Card title={t('hr.personal.emergencyContacts')}>
      {contacts.length === 0 ? (
        <Empty>{t('hr.personal.noEmergencyContacts')}</Empty>
      ) : (
        <DataTable
          caption={t('hr.personal.emergencyContacts')}
          rows={contacts.map((row, index) => ({ id: String(index), ...row }))}
          columns={[
            { key: 'name', label: t('hr.emergency.name'), render: (row) => row.name },
            { key: 'relationship', label: t('hr.emergency.relationship'), render: (row) => row.relationship ?? '' },
            { key: 'phone', label: t('hr.emergency.phone'), render: (row) => [row.phone, row.alt_phone].filter(Boolean).join(' · ') },
            { key: 'email', label: t('hr.emergency.email'), render: (row) => row.email ?? '' },
          ]}
        />
      )}
    </Card>
  )
}

function EditPersonal({ employee, access, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({ ...personalForm(employee), date_of_birth: employee.date_of_birth ?? '', medical_notes: employee.medical_notes ?? '' }))
  const save = useHrMutation((body) => api.patch(`hr/employees/${employee.id}`, body), { success: t('hr.profile.saved'), onDone: onClose })
  const errors = save.errors([...PERSONAL_FIELDS, 'date_of_birth', 'medical_notes'])
  // HR-09: sensitive keys are sent only by holders of their group's edit permission.
  const editsBirth = 'date_of_birth' in employee && access.identity?.edit
  const editsMedical = 'medical_notes' in employee && access.medical?.edit
  const submit = () =>
    save.mutate({
      ...personalBody(form),
      ...(editsBirth ? { date_of_birth: form.date_of_birth || null } : {}),
      ...(editsMedical ? { medical_notes: form.medical_notes.trim() || null } : {}),
    })
  return (
    <FormDialog open size="lg" title={t('hr.personal.editTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} errors={errors} onSubmit={submit}>
      <PersonalForm form={form} setForm={setForm} errors={errors} />
      {editsBirth ? <TextField type="date" label={t('hr.fields.dateOfBirth')} value={form.date_of_birth} error={errors.fields.date_of_birth} onChange={(event) => setForm({ ...form, date_of_birth: event.target.value })} /> : null}
      {editsMedical ? <TextAreaField label={t('hr.fields.medicalNotes')} value={form.medical_notes} maxLength={5000} error={errors.fields.medical_notes} onChange={(event) => setForm({ ...form, medical_notes: event.target.value })} /> : null}
    </FormDialog>
  )
}

/**
 * HR-03: identifiers of the company's country pack. Everyone sees them
 * masked; the full value comes only to holders of `hr.identity.view`.
 */
function Identity({ employee, types }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(false)
  const held = Object.fromEntries((employee.identifiers ?? []).map((row) => [row.type, row]))
  const rows = [...types.map((type) => ({ id: type.key, type: type.key, label: type.label, required: type.required, ...held[type.key] })), ...(employee.identifiers ?? []).filter((row) => !types.some((type) => type.key === row.type)).map((row) => ({ id: row.type, ...row }))]
  return (
    <Card
      title={t('hr.profile.tabs.identity')}
      subtitle={employee.access?.identity?.view ? t('hr.identity.fullHelp') : t('hr.identity.maskedHelp')}
      actions={
        employee.access?.identity?.edit ? (
          <Button icon="edit" onClick={() => setEditing(true)}>
            {t('hr.actions.edit')}
          </Button>
        ) : null
      }
    >
      {rows.length === 0 ? (
        <Empty>{t('hr.identity.none')}</Empty>
      ) : (
        <DataTable
          caption={t('hr.profile.tabs.identity')}
          rows={rows}
          columns={[
            { key: 'label', label: t('hr.identity.type'), render: (row) => row.label ?? row.type },
            { key: 'value', label: t('hr.identity.value'), render: (row) => <span className="font-mono tabular-nums">{row.value ?? row.masked ?? '—'}</span> },
            { key: 'required', label: t('hr.identity.required'), render: (row) => (row.required ? t('hr.yes') : '') },
          ]}
        />
      )}
      {editing ? <EditIdentifiers employee={employee} types={rows} onClose={() => setEditing(false)} /> : null}
    </Card>
  )
}

function EditIdentifiers({ employee, types, onClose }) {
  const { t } = useTranslation()
  const [values, setValues] = useState(() => Object.fromEntries(types.map((type) => [type.type, type.value ?? ''])))
  const save = useHrMutation((body) => api.put(`hr/employees/${employee.id}/identifiers`, body), { success: t('hr.identity.saved'), onDone: onClose })
  const errors = save.errors(types.map((_, index) => `identifiers.${index}.value`))
  return (
    <FormDialog
      open
      title={t('hr.identity.editTitle')}
      onClose={onClose}
      submitLabel={t('hr.actions.saveChanges')}
      pending={save.isPending}
      errors={errors}
      onSubmit={() => save.mutate({ identifiers: types.map((type) => ({ type: type.type, value: values[type.type].trim() || null })) })}
    >
      <p>{t('hr.identity.editHelp')}</p>
      {types.map((type, index) => (
        <TextField
          key={type.type}
          label={type.label ?? type.type}
          value={values[type.type]}
          maxLength={64}
          autoComplete="off"
          placeholder={type.masked ?? ''}
          error={errors.fields[`identifiers.${index}.value`]}
          onChange={(event) => setValues({ ...values, [type.type]: event.target.value })}
        />
      ))}
    </FormDialog>
  )
}

/**
 * HR-04: pay accounts. Holders of `hr.bank.view` read them in full (GET
 * pay-accounts, audited); others see the profile's masked list.
 */
function PayAccounts({ employee }) {
  const { t } = useTranslation()
  const access = employee.access ?? {}
  const [dialog, setDialog] = useState(null)
  const full = useQuery({ queryKey: [...hrKey, 'pay-accounts', employee.id], queryFn: () => api.get(`hr/employees/${employee.id}/pay-accounts`), enabled: Boolean(access.bank?.view) })
  const rows = access.bank?.view ? (full.data?.data ?? []) : (employee.pay_accounts ?? []).filter((row) => !row.archived_at)
  const archive = useHrMutation((account) => api.post(`hr/pay-accounts/${account.id}/archive`, {}), { success: t('hr.payAccounts.archived'), onDone: () => setDialog(null) })
  return (
    <Card
      title={t('hr.profile.tabs.payAccounts')}
      subtitle={access.bank?.view ? t('hr.payAccounts.fullHelp') : t('hr.payAccounts.maskedHelp')}
      actions={
        access.bank?.edit ? (
          <Button icon="plus" onClick={() => setDialog({ kind: 'add' })}>
            {t('hr.payAccounts.add')}
          </Button>
        ) : null
      }
    >
      {full.isError ? <Alert tone="danger" title={t('hr.payAccounts.loadError')} /> : null}
      {rows.length === 0 && !full.isLoading ? (
        <Empty>{t('hr.payAccounts.none')}</Empty>
      ) : (
        <DataTable
          caption={t('hr.profile.tabs.payAccounts')}
          loading={full.isLoading}
          rows={rows}
          columns={[
            { key: 'kind', label: t('hr.payAccounts.kind'), render: (row) => enumLabel(t, 'payAccountKinds', row.kind) },
            { key: 'provider', label: t('hr.payAccounts.provider'), render: (row) => row.provider },
            { key: 'account_name', label: t('hr.payAccounts.accountName'), render: (row) => row.account_name },
            { key: 'number', label: t('hr.payAccounts.accountNumber'), render: (row) => <span className="font-mono tabular-nums">{row.account_number ?? row.masked}</span> },
            { key: 'currency', label: t('hr.payAccounts.currency'), render: (row) => row.currency },
            { key: 'primary', label: t('hr.payAccounts.primary'), render: (row) => (row.is_primary ? t('hr.payAccounts.primaryYes') : '') },
            {
              key: 'actions',
              label: '',
              align: 'end',
              render: (row) =>
                access.bank?.edit && !row.archived_at ? (
                  <span className="flex justify-end gap-2">
                    <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', account: row })}>
                      {t('hr.actions.edit')}
                    </Button>
                    <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', account: row })}>
                      {t('hr.actions.archive')}
                    </Button>
                  </span>
                ) : null,
            },
          ]}
        />
      )}
      {dialog?.kind === 'add' || dialog?.kind === 'edit' ? <PayAccountDialog employee={employee} account={dialog.account} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' ? (
        <ConfirmDialog
          open
          title={t('hr.payAccounts.archiveTitle')}
          text={t('hr.payAccounts.archiveHelp', { account: `${dialog.account.provider} ${dialog.account.masked}` })}
          confirmLabel={t('hr.actions.archive')}
          tone="danger"
          pending={archive.isPending}
          error={archive.error}
          onConfirm={() => archive.mutate(dialog.account)}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </Card>
  )
}

function PayAccountDialog({ employee, account, onClose }) {
  const { t } = useTranslation()
  const currencies = useCurrencyCodes(null)
  const [form, setForm] = useState(() => ({
    kind: account?.kind ?? 'bank',
    provider: account?.provider ?? '',
    branch_code: account?.branch_code ?? '',
    account_name: account?.account_name ?? employee.name,
    account_number: '',
    currency: account?.currency ?? currencies[0] ?? '',
    is_primary: account?.is_primary ?? false,
  }))
  const save = useHrMutation((body) => (account ? api.patch(`hr/pay-accounts/${account.id}`, body) : api.post(`hr/employees/${employee.id}/pay-accounts`, body)), {
    success: t('hr.payAccounts.saved'),
    onDone: onClose,
  })
  const errors = save.errors(['kind', 'provider', 'branch_code', 'account_name', 'account_number', 'currency', 'is_primary'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const ready = form.provider.trim() && form.account_name.trim() && form.currency && (account || form.account_number.trim())
  const submit = () => {
    const { account_number: number, ...rest } = form
    save.mutate({ ...rest, branch_code: rest.branch_code.trim() || null, ...(number.trim() ? { account_number: number.trim() } : {}) })
  }
  return (
    <FormDialog open title={account ? t('hr.payAccounts.editTitle') : t('hr.payAccounts.addTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={submit}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('hr.payAccounts.kind')} options={enumOptions(t, 'payAccountKinds', PAY_ACCOUNT_KINDS)} value={form.kind} required error={errors.fields.kind} onChange={set('kind')} />
        <TextField label={t('hr.payAccounts.provider')} value={form.provider} required maxLength={100} error={errors.fields.provider} onChange={set('provider')} />
        <TextField label={t('hr.payAccounts.branchCode')} value={form.branch_code} maxLength={40} error={errors.fields.branch_code} onChange={set('branch_code')} />
        <TextField label={t('hr.payAccounts.accountName')} value={form.account_name} required maxLength={150} error={errors.fields.account_name} onChange={set('account_name')} />
        <TextField
          label={t('hr.payAccounts.accountNumber')}
          help={account ? t('hr.payAccounts.keepNumber', { masked: account.masked }) : undefined}
          value={form.account_number}
          required={!account}
          maxLength={40}
          autoComplete="off"
          error={errors.fields.account_number}
          onChange={set('account_number')}
        />
        <Select label={t('hr.payAccounts.currency')} options={currencies.map((code) => ({ value: code, label: code }))} value={form.currency} required error={errors.fields.currency} onChange={set('currency')} />
      </div>
      <Checkbox label={t('hr.payAccounts.makePrimary')} checked={form.is_primary} onChange={(event) => setForm({ ...form, is_primary: event.target.checked })} />
    </FormDialog>
  )
}
