import { fireEvent, screen, within } from '@testing-library/react'
import { api } from '@/api/client'
import { setLocale } from '@/i18n'
import { mockRoutes, renderApp, resetSession } from '@/test/renderApp'
import { operationsByTag, schemaRows, typeLabel } from './openapi'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const SPEC = {
  openapi: '3.1.0',
  info: { title: 'Public API', version: '1.0.0', description: 'REST API for your data.', 'x-i18n': { fr: { description: 'API REST pour vos données.' } } },
  servers: [{ url: '/api/v1' }],
  paths: {
    '/items': {
      get: {
        operationId: 'core.item.index', tags: ['core.item'], summary: 'List items', 'x-scope': 'core.item.read',
        'x-i18n': { fr: { summary: 'Lister les articles' } },
        parameters: [{ name: 'per_page', in: 'query', schema: { type: 'integer' } }, { name: 'type', in: 'query', schema: { type: 'string', enum: ['stock', 'service'] } }],
        responses: { 200: { description: 'Paginated set', content: { 'application/json': { schema: { type: 'object', properties: { data: { type: 'array', items: { $ref: '#/components/schemas/ItemResource' } } } } } } }, 429: { $ref: '#/components/responses/RateLimited' } },
      },
      post: {
        operationId: 'core.item.store', tags: ['core.item'], summary: 'Create an item', 'x-scope': 'core.item.write', deprecated: true, 'x-sunset': '2027-04-01',
        'x-i18n': { fr: { summary: 'Créer un article' } },
        requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/StoreItemRequest' } } } },
        responses: { 201: { description: 'Created', content: { 'application/json': { schema: { type: 'object', properties: { data: { $ref: '#/components/schemas/ItemResource' } } } } } } },
      },
    },
    '/parties/{party}': {
      get: { operationId: 'core.party.show', tags: ['core.party'], summary: 'Get a contact', 'x-scope': 'core.party.read', parameters: [{ name: 'party', in: 'path', required: true, schema: { type: 'string' } }], responses: { 200: { description: 'OK' } } },
    },
  },
  components: {
    schemas: {
      ItemResource: { type: 'object', properties: { id: { type: 'string', format: 'uuid' }, code: { type: 'string' }, name: { type: ['string', 'null'] } }, required: ['id', 'code'] },
      StoreItemRequest: { type: 'object', properties: { code: { type: 'string' }, name: { type: 'string' }, type: { type: 'string', enum: ['stock', 'service'] } }, required: ['code', 'name'] },
    },
    responses: { RateLimited: { description: 'Too many requests' } },
  },
}

describe('Developer portal (API-04)', () => {
  beforeEach(async () => {
    resetSession()
    await setLocale('en')
    mockRoutes(api, [['developers/openapi.json', SPEC]])
  })

  afterEach(async () => {
    await setLocale('en')
  })

  it('is public: the home, its guides and a way to a sandbox, without signing in', async () => {
    renderApp('/developers')

    expect(await screen.findByRole('heading', { name: 'Build on the public API' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Create a developer sandbox' })).toHaveAttribute('href', '/welcome?sandbox=1')
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Developer portal' })
    for (const guide of ['Authentication', 'Scopes', 'Pagination and filters', 'Errors', 'Idempotency', 'Rate limits', 'Versions and deprecation', 'Sandbox', 'Webhooks', 'Courier and attendance connectors']) {
      expect(within(nav).getByRole('link', { name: guide })).toBeInTheDocument()
    }
  })

  it('shows a guide with its example, in French too', async () => {
    renderApp('/developers/guides/idempotency')

    expect(await screen.findByRole('heading', { name: 'Idempotency' })).toBeInTheDocument()
    expect(screen.getByText(/Idempotent-Replayed: true/, { selector: 'code' })).toBeInTheDocument()

    await setLocale('fr')
    expect(await screen.findByRole('heading', { name: 'Idempotence' })).toBeInTheDocument()
  })

  it('lists error codes as anchors the API links to', async () => {
    renderApp('/developers/errors')

    expect(await screen.findByText('api_scope_missing')).toBeInTheDocument()
    expect(document.getElementById('rate_limited')).not.toBeNull()
    expect(screen.getByText('The key lacks the scope named in scope.')).toBeInTheDocument()
  })

  it('renders the reference from the OpenAPI document, by resource, with fields and a request to copy', async () => {
    renderApp('/developers/reference')

    expect(await screen.findByRole('heading', { name: 'List items' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'core.item' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'core.party' })).toBeInTheDocument()
    expect(screen.getByText('core.item.write')).toBeInTheDocument()
    expect(screen.getAllByText('/api/v1/items')).toHaveLength(2)
    expect(screen.getByText('per_page (query)')).toBeInTheDocument()
    expect(screen.getAllByText('data.code').length).toBe(2)
    expect(screen.getAllByText('Deprecated').length).toBeGreaterThan(0)
    expect(screen.getAllByText(/curl -X POST/).length).toBe(1)

    fireEvent.change(screen.getByLabelText('Find an endpoint'), { target: { value: 'contact' } })
    expect(screen.queryByRole('heading', { name: 'List items' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Get a contact' })).toBeInTheDocument()
  })

  it('shows the reference in French from x-i18n', async () => {
    await setLocale('fr')
    renderApp('/developers/reference')

    expect(await screen.findByRole('heading', { name: 'Lister les articles' })).toBeInTheDocument()
    expect(screen.getByText('API REST pour vos données.')).toBeInTheDocument()
    // No French text: the English one.
    expect(screen.getByRole('heading', { name: 'Get a contact' })).toBeInTheDocument()
  })

  it('lists deprecated operations with their sunset in the changelog', async () => {
    renderApp('/developers/changelog')

    expect(await screen.findByText('POST /items')).toBeInTheDocument()
    expect(screen.getByText('Removed on 1 Apr 2027')).toBeInTheDocument()
  })
})

describe('OpenAPI reading (API-04)', () => {
  it('groups operations by resource and flattens schemas into fields', () => {
    expect(operationsByTag(SPEC).map((group) => group.tag)).toEqual(['core.item', 'core.party'])
    expect(operationsByTag(SPEC)[0].operations.map((op) => op.method)).toEqual(['get', 'post'])
    expect(schemaRows(SPEC, SPEC.components.schemas.StoreItemRequest).map((row) => [row.name, row.required])).toEqual([['code', true], ['name', true], ['type', false]])
    expect(typeLabel({ type: ['string', 'null'] })).toBe('string | null')
    expect(typeLabel({ type: 'array', items: { $ref: '#/components/schemas/ItemResource' } })).toBe('ItemResource[]')
  })
})
