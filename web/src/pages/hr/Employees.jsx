import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, ListView, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { Back, CalendarDate, EmployeePicker, HrStatus } from './HrParts'
import { branchesOf, CLOCK_METHODS, EMPLOYEE_STATUSES, enumOptions, GENDERS, hrKey, locationsOf, PERM, statusLabel, useDepartments, useHrCompany, usePlaces, usePositions } from './hrData'
import { EMPLOYEE_FIELDS, employeeBody, employeeForm, useHrMutation } from './hrForms'

/**
 * HR-02, HR-35: employees at the places the user sees, with search
 * (number, name, work email), status and branch filters, sort and export
 * (list `hr-employees`); Hire employee for those who may.
 */
export default function Employees() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('hr.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'name', label: t('hr.columns.name'), sortKey: 'last_name', hideable: false, render: (row) => row.name },
    { key: 'company', label: t('hr.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
    { key: 'branch', label: t('hr.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'location', label: t('hr.columns.location'), defaultHidden: true, render: (row) => row.location?.name ?? '' },
    { key: 'department', label: t('hr.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'position', label: t('hr.columns.position'), render: (row) => row.position?.title ?? '' },
    { key: 'status', label: t('hr.columns.status'), sortKey: 'status', render: (row) => <HrStatus status={row.status} /> },
    { key: 'hire_date', label: t('hr.columns.hireDate'), sortKey: 'hire_date', render: (row) => <CalendarDate value={row.hire_date} /> },
    { key: 'work_email', label: t('hr.columns.workEmail'), defaultHidden: true, render: (row) => row.work_email ?? '' },
    { key: 'work_phone', label: t('hr.columns.workPhone'), defaultHidden: true, render: (row) => row.work_phone ?? '' },
  ]

  const list = useServerList({
    id: 'hr-employees',
    endpoint: 'hr/employees',
    queryKey: [...hrKey, 'employees'],
    filters: { status: 'active', branch: '' },
    defaultSort: 'number',
    columns,
  })
  const branches = places.branches.filter((branch) => !branch.archived_at)

  return (
    <>
      <PageHeader
        title={t('hr.employees.title')}
        description={t('hr.employees.description')}
        actions={
          can(PERM.employeeCreate) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/hr/employees/new')}>
              {t('hr.employees.hire')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('hr.employees.title')}
        searchLabel={t('hr.employees.search')}
        filterFields={[
          { name: 'status', label: t('hr.filters.status'), options: [{ value: 'all', label: t('hr.filters.allStatuses') }, ...EMPLOYEE_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
          ...(branches.length > 1 ? [{ name: 'branch', label: t('hr.filters.branch'), options: [{ value: '', label: t('hr.filters.allBranches') }, ...branches.map((branch) => ({ value: branch.id, label: branch.name }))] }] : []),
        ]}
        emptyText={list.term ? t('hr.employees.emptyFiltered') : t('hr.employees.empty')}
        onRowClick={(row) => navigate(`/hr/employees/${row.id}`)}
      />
    </>
  )
}

/**
 * The employee fields HR-02 shares between hiring and editing: place
 * (branch, location), department, position, line manager, work contacts
 * and clock-in methods, all of the employee's company.
 */
export function EmployeeFields({ form, setForm, errors, companyId, employeeId }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const departments = useDepartments(companyId)
  const positions = usePositions(companyId)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const branches = branchesOf(places, companyId)
  const locations = form.branch_id ? locationsOf(places, form.branch_id) : []
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-3">
        <TextField label={t('hr.fields.firstName')} value={form.first_name} required maxLength={100} error={errors.fields.first_name} onChange={set('first_name')} />
        <TextField label={t('hr.fields.middleName')} value={form.middle_name} maxLength={100} error={errors.fields.middle_name} onChange={set('middle_name')} />
        <TextField label={t('hr.fields.lastName')} value={form.last_name} required maxLength={100} error={errors.fields.last_name} onChange={set('last_name')} />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={t('hr.fields.gender')} placeholder={t('hr.pickers.gender')} options={enumOptions(t, 'genders', GENDERS)} value={form.gender} error={errors.fields.gender} onChange={set('gender')} />
        <TextField type="date" label={t('hr.fields.hireDate')} value={form.hire_date} required error={errors.fields.hire_date} onChange={set('hire_date')} />
        <Select
          label={t('hr.fields.branch')}
          placeholder={t('hr.pickers.branch')}
          options={[{ value: '', label: t('hr.pickers.noBranch') }, ...branches.map((branch) => ({ value: branch.id, label: branch.name }))]}
          value={form.branch_id}
          disabled={!companyId}
          error={errors.fields.branch_id}
          onChange={(event) => setForm({ ...form, branch_id: event.target.value, location_id: '' })}
        />
        <Select
          label={t('hr.fields.location')}
          placeholder={t('hr.pickers.location')}
          options={[{ value: '', label: t('hr.pickers.noLocation') }, ...locations.map((location) => ({ value: location.id, label: location.name }))]}
          value={form.location_id}
          disabled={!form.branch_id}
          error={errors.fields.location_id}
          onChange={set('location_id')}
        />
        <Select
          label={t('hr.fields.department')}
          placeholder={t('hr.pickers.department')}
          options={[{ value: '', label: t('hr.pickers.noDepartment') }, ...departments.map((row) => ({ value: row.id, label: `${row.name} · ${row.code}` }))]}
          value={form.department_id}
          disabled={!companyId}
          error={errors.fields.department_id}
          onChange={set('department_id')}
        />
        <Select
          label={t('hr.fields.position')}
          placeholder={t('hr.pickers.position')}
          options={[{ value: '', label: t('hr.pickers.noPosition') }, ...positions.map((row) => ({ value: row.id, label: `${row.title} · ${row.code}` }))]}
          value={form.position_id}
          disabled={!companyId}
          error={errors.fields.position_id}
          onChange={set('position_id')}
        />
        <EmployeePicker
          label={t('hr.fields.lineManager')}
          company={companyId}
          value={form.reports_to}
          exclude={employeeId ? [employeeId] : []}
          disabled={!companyId}
          error={errors.fields.reports_to_id}
          onChange={(employee) => setForm({ ...form, reports_to: employee })}
        />
        <MultiSelect
          label={t('hr.fields.clockMethods')}
          help={t('hr.employees.clockMethodsHelp')}
          options={enumOptions(t, 'clockMethods', CLOCK_METHODS)}
          value={form.clock_methods}
          error={errors.fields.clock_methods}
          onChange={(values) => setForm({ ...form, clock_methods: values })}
        />
        <TextField type="email" label={t('hr.fields.workEmail')} value={form.work_email} error={errors.fields.work_email} onChange={set('work_email')} />
        <TextField label={t('hr.fields.workPhone')} value={form.work_phone} error={errors.fields.work_phone} onChange={set('work_phone')} />
        <TextField label={t('hr.fields.biometricRef')} help={t('hr.employees.biometricHelp')} value={form.biometric_ref} maxLength={64} error={errors.fields.biometric_ref} onChange={set('biometric_ref')} />
      </div>
    </>
  )
}

/** HR-02, HR-05: hire an employee into a company (the usage guard applies, HR-32). */
export function EmployeeForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const places = usePlaces()
  const chosen = useHrCompany()
  const [companyId, setCompanyId] = useState(null)
  const company = companyId ?? chosen?.id ?? ''
  const [form, setForm] = useState(() => employeeForm())
  const [submitted, setSubmitted] = useState(false)
  const save = useHrMutation((body) => api.post('hr/employees', body), {
    success: (response) => t('hr.employees.hired', { number: response?.data?.number ?? '' }),
    onDone: (response) => navigate(`/hr/employees/${response.data.id}`),
  })
  const errors = save.errors(EMPLOYEE_FIELDS)
  const ready = company && form.first_name.trim() && form.last_name.trim() && form.hire_date
  const companies = places.companies.filter((row) => !row.archived_at)

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate({ company_id: company, ...employeeBody(form) })
  }

  return (
    <>
      <Back to="/hr/employees">{t('hr.employees.back')}</Back>
      <PageHeader title={t('hr.employees.hireTitle')} description={t('hr.employees.hireHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {submitted && !ready ? <Alert tone="danger" title={t('hr.validation.required')} /> : null}
        <Card>
          <div className="flex flex-col gap-4">
            <Select
              label={t('hr.fields.company')}
              placeholder={t('hr.pickers.company')}
              options={companies.map((row) => ({ value: row.id, label: row.name }))}
              value={company}
              required
              error={errors.fields.company_id}
              onChange={(event) => {
                setCompanyId(event.target.value)
                setForm({ ...form, branch_id: '', location_id: '', department_id: '', position_id: '', reports_to: null })
              }}
            />
            <EmployeeFields form={form} setForm={setForm} errors={errors} companyId={company} />
          </div>
        </Card>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/hr/employees')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('hr.employees.hireSubmit')}
          </Button>
        </div>
      </form>
    </>
  )
}
