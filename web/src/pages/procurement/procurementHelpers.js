// Helpers the Procurement screens share that are not components: form
// errors, the editable document line and its API body, a document action
// and file downloads.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { saveFile } from '@/lib/files'
import { procurementKey } from './procurementData'

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

let nextKey = 0
/** A blank document line (a key keeps React's rows apart). */
export const blankLine = (extra = {}) => ({
  key: `line-${(nextKey += 1)}`,
  item: null,
  description: '',
  uom_id: '',
  qty: '',
  price_minor: '',
  tax_code_id: '',
  cost_centre_id: '',
  project_id: '',
  requisition_line_id: null,
  ...extra,
})

/** A line of the API (requisition, RFQ, tender or PO detail) as an editable line; `price` names its money field. */
export const lineFromRecord = (line, price) =>
  blankLine({
    item: line.item ?? null,
    description: line.description ?? '',
    uom_id: line.uom?.id ?? '',
    qty: line.qty ?? '',
    price_minor: price && line[price] ? line[price].amount_minor : '',
    tax_code_id: line.tax_code?.id ?? '',
    cost_centre_id: line.cost_centre?.id ?? '',
    project_id: line.project?.id ?? '',
  })

/** A line from an approved requisition's line, keeping its link (PRC-02 → RFQ, tender, PO). */
export const lineFromRequisition = (line) => ({ ...lineFromRecord(line, 'estimated_unit_price'), requisition_line_id: line.id })

/**
 * A line as the API takes it: `{item_id?, description?, uom_id?, qty,
 * <priceKey>?, tax_code_id?, cost_centre_id?, project_id?,
 * requisition_line_id?}`, money as minor-unit digit strings (ADR 003).
 */
export function lineBody(line, { priceKey = null, tax = false, dimensions = false, requisitionLine = false } = {}) {
  return {
    ...(line.item ? { item_id: line.item.id } : {}),
    ...(line.description.trim() ? { description: line.description.trim() } : {}),
    ...(line.uom_id ? { uom_id: line.uom_id } : {}),
    qty: line.qty,
    ...(priceKey && line.price_minor !== '' && line.price_minor !== null ? { [priceKey]: line.price_minor } : {}),
    ...(tax && line.tax_code_id ? { tax_code_id: line.tax_code_id } : {}),
    ...(dimensions && line.cost_centre_id ? { cost_centre_id: line.cost_centre_id } : {}),
    ...(dimensions && line.project_id ? { project_id: line.project_id } : {}),
    ...(requisitionLine && line.requisition_line_id ? { requisition_line_id: line.requisition_line_id } : {}),
  }
}

/** Whether a line names what it is (an item or a description) and its quantity, and its price when one is required. */
export const lineReady = (line, priceRequired = false) => Boolean((line.item || line.description.trim()) && line.qty && (!priceRequired || (line.price_minor !== '' && line.price_minor !== null)))

/** Field names of every line, so `formErrors` puts the API's messages under their fields. */
export const lineFieldNames = (count) =>
  Array.from({ length: count }, (_, index) =>
    ['item_id', 'description', 'uom_id', 'qty', 'unit_price_minor', 'estimated_unit_price_minor', 'tax_code_id', 'cost_centre_id', 'project_id', 'requisition_line_id'].map((name) => `lines.${index}.${name}`),
  ).flat()

/**
 * A document action (`POST path` with an optional body): the answer
 * replaces the cached detail at `key`, a toast says it was done, and the
 * module's lists refresh.
 */
export function useDocumentAction(key, { done, onDone } = {}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ path, body }) => api.post(path, body ?? {}),
    onSuccess: async (response, variables) => {
      if (response?.data && !Array.isArray(response.data) && key) queryClient.setQueryData(key, response)
      const message = variables.done ?? done
      if (message) toast.success(t(message))
      onDone?.(response, variables)
      await queryClient.invalidateQueries({ queryKey: procurementKey })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
}

/** Downloads a file of the API with the user's token (a PDF, a bid's attachment). */
export function useDownload() {
  return useMutation({
    mutationFn: async ({ path, name }) => {
      const { blob, filename } = await api.download(path)
      saveFile(blob, filename ?? name)
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
}
