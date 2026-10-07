/** An email address, or a phone number in international format without spaces. */
export function contactPayload(contact) {
  const value = contact.trim()
  if (value.includes('@')) return { email: value }
  return { phone: value.replace(/[\s().-]/g, '') }
}
