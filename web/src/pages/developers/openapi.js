// API-04: reading the public API's OpenAPI document (GET developers/openapi.json)
// for the portal's reference: operations grouped by resource, schemas
// flattened into field rows, texts in the reader's language (x-i18n).

export const METHODS = ['get', 'post', 'put', 'patch', 'delete']

/** The schema a `$ref` points at (components only), or the schema itself. */
export function resolve(spec, schema) {
  let current = schema
  for (let hops = 0; current?.$ref && hops < 10; hops += 1) {
    const path = current.$ref.replace(/^#\//, '').split('/')
    current = path.reduce((node, key) => node?.[key], spec)
  }
  return current ?? {}
}

/** "string", "integer | null", "ItemResource[]" ... (code notation, not translated) */
export function typeLabel(schema) {
  if (!schema) return ''
  if (schema.$ref) return schema.$ref.split('/').at(-1)
  if (schema.anyOf || schema.oneOf) return (schema.anyOf ?? schema.oneOf).map((one) => typeLabel(one)).join(' | ')
  const types = Array.isArray(schema.type) ? schema.type : [schema.type ?? (schema.properties ? 'object' : '')]
  return types
    .filter(Boolean)
    .map((type) => (type === 'array' ? `${typeLabel(schema.items) || 'any'}[]` : schema.format ? `${type} (${schema.format})` : type))
    .join(' | ')
}

/**
 * The fields of an object schema as rows `{ name, type, required, description, values }`,
 * nested objects as `parent.child`, at most `depth` levels down.
 */
export function schemaRows(spec, schema, depth = 2, prefix = '') {
  const resolved = resolve(spec, schema)
  const target = resolved.type === 'array' || (Array.isArray(resolved.type) && resolved.type.includes('array')) ? resolve(spec, resolved.items) : resolved
  const required = new Set(target.required ?? [])
  const rows = []

  for (const [name, property] of Object.entries(target.properties ?? {})) {
    const field = resolve(spec, property)
    rows.push({
      name: `${prefix}${name}`,
      type: typeLabel(property),
      required: required.has(name),
      description: field.description ?? '',
      values: field.enum ?? [],
    })
    if (depth > 0 && (field.properties || field.items?.$ref || field.items?.properties)) {
      rows.push(...schemaRows(spec, field, depth - 1, `${prefix}${name}.`))
    }
  }

  return rows
}

/** An operation's text in `locale` (French from `x-i18n.fr`), English otherwise. */
export function localized(operation, field, locale) {
  if (locale !== 'en') {
    const translated = operation?.['x-i18n']?.[locale]?.[field]
    if (translated) return translated
  }
  return operation?.[field] ?? ''
}

/** Operations grouped by their tag (the resource, e.g. `core.item`), tags sorted, operations by path then method. */
export function operationsByTag(spec) {
  const groups = new Map()

  for (const [path, item] of Object.entries(spec?.paths ?? {})) {
    for (const method of METHODS) {
      const operation = item[method]
      if (!operation) continue
      const tag = operation.tags?.[0] ?? 'other'
      if (!groups.has(tag)) groups.set(tag, [])
      groups.get(tag).push({ method, path, operation })
    }
  }

  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([tag, operations]) => ({
      tag,
      operations: operations.sort((a, b) => a.path.localeCompare(b.path) || METHODS.indexOf(a.method) - METHODS.indexOf(b.method)),
    }))
}

/** The JSON schema of a request body or a response, when there is one. */
export function contentSchema(spec, holder) {
  const resolved = resolve(spec, holder)
  const content = resolved?.content ?? {}
  return (content['application/json'] ?? Object.values(content)[0])?.schema ?? null
}

/** A copy-paste request for an operation, with path parameters left as placeholders. */
export function curlFor(baseUrl, method, path, prefix = 'sk_test_') {
  const lines = [`curl -X ${method.toUpperCase()} "${baseUrl}/api/v1${path}"`, `  -H "Authorization: Bearer ${prefix}<id>_<secret>"`, '  -H "Accept: application/json"']
  if (method !== 'get') lines.push('  -H "Content-Type: application/json"', '  -H "Idempotency-Key: <unique-id>"', "  -d '{}'")
  return lines.join(' \\\n')
}

/** Operations marked deprecated, for the changelog. */
export function deprecatedOperations(spec) {
  return operationsByTag(spec)
    .flatMap((group) => group.operations)
    .filter(({ operation }) => operation.deprecated)
}
