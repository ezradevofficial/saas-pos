/**
 * A small HTTP client for the device API (/api/v1). It never throws for an
 * HTTP status: callers get { status, body } and decide. It throws
 * NetworkError when no answer came (offline, DNS, timeout), so the engine
 * can tell "the server said no" from "the server was not reached".
 */
import { toBase64 } from '../lib/bytes';

export class NetworkError extends Error {
  constructor(cause) {
    super('network_error');
    this.name = 'NetworkError';
    this.cause = cause;
  }
}

/** entities[]=a&cursors[a]=x&limit=5 (Laravel's array syntax). */
export function toQuery(params = {}) {
  const parts = [];
  const add = (key, value) => parts.push(`${encodeURIComponent(key).replace(/%5B/g, '[').replace(/%5D/g, ']')}=${encodeURIComponent(value)}`);
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null) continue;
    if (Array.isArray(value)) value.forEach((item) => add(`${key}[]`, item));
    else if (typeof value === 'object') Object.entries(value).forEach(([sub, item]) => item != null && add(`${key}[${sub}]`, item));
    else add(key, value);
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

export function createApiClient({ baseUrl, getToken, getLocale = () => 'en', fetchImpl = globalThis.fetch, timeoutMs = 30000 }) {
  const root = `${String(baseUrl).replace(/\/+$/, '')}/api/v1/`;

  async function request(method, path, { query, body, auth = true } = {}) {
    const headers = { Accept: 'application/json', 'Accept-Language': getLocale() };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (auth) {
      const token = await getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
    let response;
    try {
      response = await fetchImpl(`${root}${path.replace(/^\/+/, '')}${toQuery(query)}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: controller?.signal,
      });
    } catch (error) {
      throw new NetworkError(error);
    } finally {
      if (timer) clearTimeout(timer);
    }
    let parsed = null;
    try {
      const text = await response.text();
      parsed = text ? JSON.parse(text) : null;
    } catch {
      parsed = null;
    }
    let retryAfter = null;
    try {
      retryAfter = response.headers?.get?.('retry-after') ?? null;
    } catch {
      retryAfter = null;
    }
    return { status: response.status, body: parsed, retryAfter };
  }

  /**
   * BR-02, LAY-05: an image the device may read (a logo, an item image) as a
   * data URI, or null when the server says no. Throws NetworkError offline.
   */
  async function image(path) {
    const headers = { Accept: 'image/*' };
    const token = await getToken();
    if (token) headers.Authorization = `Bearer ${token}`;
    let response;
    try {
      response = await fetchImpl(`${root}${path.replace(/^\/+/, '').replace(/^api\/v1\//, '')}`, { method: 'GET', headers });
    } catch (error) {
      throw new NetworkError(error);
    }
    if (response.status !== 200) return null;
    const type = String(response.headers?.get?.('content-type') ?? '').split(';')[0].trim();
    if (!/^image\/(png|jpeg|webp|gif)$/.test(type)) return null;
    const bytes = new Uint8Array(await response.arrayBuffer());
    return `data:${type};base64,${toBase64(bytes)}`;
  }

  return {
    request,
    image,
    get: (path, options) => request('GET', path, options),
    post: (path, body, options) => request('POST', path, { ...options, body }),
  };
}
