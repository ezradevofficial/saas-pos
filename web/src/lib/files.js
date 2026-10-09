import { api } from '@/api/client'

/** Hands a blob to the browser as a download named `filename`. */
export function saveFile(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  // Revoked after the click has handed the file to the browser.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

/**
 * The API path of an absolute API URL the server handed out (a preview's
 * `pdf_url`), so it is fetched through the client with the bearer token.
 */
export function apiPathOf(url) {
  const text = String(url ?? '')
  const at = text.indexOf('/api/v1/')
  return at === -1 ? text : text.slice(at + '/api/v1/'.length)
}

/** Downloads an API file with the bearer token and saves it (`fallback` names it when the API does not). */
export async function downloadFile(path, fallback) {
  const { blob, filename } = await api.download(path)
  saveFile(blob, filename ?? fallback)
}
