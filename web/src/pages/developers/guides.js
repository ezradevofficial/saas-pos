// API-04: the developer portal's guides. Words come from the locale files
// (`developers.guides.<id>.*`, en and fr); code samples are code, the same
// in every language. `paragraphs` names the keys shown, in order.

export const GUIDES = [
  {
    id: 'authentication',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `curl "https://api.example.com/api/v1/items?per_page=25" \\
  -H "Authorization: Bearer sk_live_<id>_<secret>" \\
  -H "Accept: application/json" \\
  -H "Accept-Language: fr"`,
  },
  {
    id: 'scopes',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `core.item.read     GET /items, GET /items/{item}
core.item.write    POST /items, PATCH /items/{item}, POST /items/{item}/archive
core.party.read    GET /parties
pos.sale.read      GET /pos/sales`,
  },
  {
    id: 'pagination',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `GET /api/v1/parties?role=customer&search=wan&sort=-created_at&page=2&per_page=50

{
  "data": [ { "id": "0193…", "name": "Wanjiku Stores" } ],
  "links": { "first": "…?page=1", "last": "…?page=4", "prev": "…?page=1", "next": "…?page=3" },
  "meta": { "current_page": 2, "last_page": 4, "per_page": 50, "total": 173 }
}`,
  },
  {
    id: 'errors',
    paragraphs: ['p1', 'p2'],
    code: `HTTP/1.1 403 Forbidden
Content-Type: application/problem+json

{
  "type": "/developers/errors#api_scope_missing",
  "title": "…",
  "status": 403,
  "detail": "…",
  "message": "…",
  "code": "api_scope_missing",
  "scope": "core.item.write"
}`,
    errors: [
      'unauthenticated',
      'forbidden',
      'not_found',
      'validation_failed',
      'field_readonly',
      'module_inactive',
      'api_key_route_refused',
      'api_scope_missing',
      'rate_limited',
      'idempotency_key_invalid',
      'idempotency_mismatch',
      'idempotency_in_progress',
      'subscription_overdue',
      'server_error',
    ],
  },
  {
    id: 'idempotency',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `curl -X POST "https://api.example.com/api/v1/parties" \\
  -H "Authorization: Bearer sk_live_<id>_<secret>" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: crm-sync-2026-10-16-0042" \\
  -d '{"kind":"person","name":"Wanjiku","roles":["customer"]}'

# The same request again answers the first response:
HTTP/1.1 201 Created
Idempotent-Replayed: true`,
  },
  {
    id: 'rateLimits',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `RateLimit-Limit: 120
RateLimit-Remaining: 117
RateLimit-Reset: 42

HTTP/1.1 429 Too Many Requests
Retry-After: 42`,
  },
  {
    id: 'versioning',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `Deprecation: @1790812800
Sunset: Thu, 01 Apr 2027 00:00:00 GMT
Link: <https://app.example.com/developers/changelog#items-v1>; rel="deprecation"`,
  },
  {
    id: 'sandbox',
    paragraphs: ['p1', 'p2', 'p3'],
    code: `curl "https://api.example.com/api/v1/items" \\
  -H "Authorization: Bearer sk_test_<id>_<secret>"`,
  },
  { id: 'webhooks', paragraphs: ['p1', 'p2'] },
]

export const guideById = (id) => GUIDES.find((guide) => guide.id === id) ?? null

/** Changelog entries, newest first: a date and the keys of their text. */
export const CHANGELOG = [{ id: 'v1-launch', date: '2026-10-16', items: ['keys', 'reference', 'sandbox'] }]
