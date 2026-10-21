import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Select } from '@/components/ds'
import { useDebounced } from '@/lib/useDebounced'

/**
 * NFR-08: find the person a request or consent is about. Typing in the
 * picker searches this business's people of that type (the API searches
 * under the tenant's own data only).
 */
export function SubjectPicker({ type, value, onChange, error }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const term = useDebounced(search, 250)
  const query = useQuery({
    queryKey: ['privacy', 'subjects', type, term],
    queryFn: () => api.get(`privacy/subjects?type=${encodeURIComponent(type)}&search=${encodeURIComponent(term)}`),
    enabled: Boolean(type),
  })
  const options = (query.data?.data ?? []).map((subject) => ({ value: subject.id, label: subject.name || subject.id }))

  return (
    <Select
      label={t('privacy.subject.pick')}
      help={t('privacy.subject.searchHelp')}
      placeholder={t('privacy.subject.pickPlaceholder')}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      onSearchChange={setSearch}
      options={options}
      error={error}
    />
  )
}
