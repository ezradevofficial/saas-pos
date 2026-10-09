import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ds'
import { cn } from '@/lib/utils'
import { CustomFieldControl } from './CustomFieldControl'

const WIDE = ['long_text', 'money', 'multi_select', 'file']

/**
 * CF-02: a record's custom fields ("More details") in its form, one
 * control per type, in the schema's order. `fields` are the fields the
 * user sees (useCustomFieldSchema), `custom` the form's values
 * (useCustomValues), `errors` the API's `custom.<key>` messages by key.
 * Nothing renders while the entity has no fields for the user.
 */
export function CustomFieldsSection({ entity, fields, custom, errors = {}, readOnly = false, showErrors = false }) {
  const { t } = useTranslation()
  if (!fields?.length) return null
  return (
    <Card title={t('customFields.section.title')} subtitle={t('customFields.section.subtitle')}>
      <div className="grid gap-4 sm:grid-cols-2">
        {fields.map((field) => (
          <CustomFieldControl
            key={field.key}
            field={field}
            entity={entity}
            value={custom.values[field.key]}
            onChange={(next) => custom.set(field.key, next)}
            disabled={readOnly}
            error={errors[field.key]}
            showErrors={showErrors}
            className={cn(WIDE.includes(field.type) && 'sm:col-span-2')}
          />
        ))}
      </div>
    </Card>
  )
}
