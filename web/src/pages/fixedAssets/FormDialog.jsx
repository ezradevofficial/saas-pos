import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DecimalInput, Dialog, MoneyInput, Select, TextField } from '@/components/ds'

/**
 * A dialog with a few fields that sends one request (`method` `path`) and
 * hands the answer to `onDone`. `fields`: [{name, label, type: text | date
 * | number | select | money | decimal, options?, currency?, required?,
 * help?, hidden?(values)}]; `initial` their starting values; `toBody`
 * shapes the body (empty values are left out by default).
 */
export function FormDialog({ open, title, help, confirm, tone = 'primary', method = 'post', path, fields, initial = {}, toBody, onClose, onDone }) {
  const { t } = useTranslation()
  const [values, setValues] = useState(initial)
  const send = useMutation({
    mutationFn: () => {
      const body = toBody ? toBody(values) : Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined))
      return api[method](path, body)
    },
    onSuccess: (response) => {
      setValues(initial)
      onDone?.(response)
    },
  })
  const errors = formErrors(send.error, fields.map((field) => field.name))
  const close = () => {
    send.reset()
    setValues(initial)
    onClose()
  }
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const missing = fields.some((field) => field.required && !(field.hidden?.(values)) && (values[field.name] ?? '') === '')

  return (
    <Dialog
      open={open}
      title={title}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('fixedAssets.actions.keep')}
          </Button>
          <Button variant={tone} loading={send.isPending} disabled={missing} onClick={() => send.mutate()}>
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {fields
          .filter((field) => !field.hidden?.(values))
          .map((field) => {
            const common = { label: field.label, help: field.help, required: field.required, error: errors.fields[field.name] }
            const value = values[field.name] ?? ''
            if (field.type === 'select') {
              return <Select key={field.name} {...common} options={field.options} placeholder={field.placeholder} value={value} onChange={(event) => set(field.name, event.target.value)} />
            }
            if (field.type === 'money') {
              return <MoneyInput key={field.name} {...common} currency={field.currency?.(values) ?? field.currencyCode} value={value} onChange={(next) => set(field.name, next ?? '')} />
            }
            if (field.type === 'decimal') {
              return <DecimalInput key={field.name} {...common} value={value} maxDecimals={field.maxDecimals ?? 6} onChange={(next) => set(field.name, next ?? '')} />
            }
            return <TextField key={field.name} {...common} type={field.type ?? 'text'} value={value} maxLength={field.type === 'text' || !field.type ? 500 : undefined} onChange={(event) => set(field.name, event.target.value)} />
          })}
      </div>
    </Dialog>
  )
}
