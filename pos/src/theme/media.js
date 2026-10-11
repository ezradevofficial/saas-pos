import { useEffect, useState } from 'react';
import { useServices } from '../services/services';

/**
 * BR-02, LAY-05, NFR-04: logos and category images on the till. The server
 * names them by id (a brand asset or an item image). After each pull the
 * till fetches every image the theme and the layout name (both logos, the
 * category images) into its `media_cache` table as data URIs, and drops
 * those they no longer name (syncMedia). Screens read only that cache
 * (useMedia), so a receipt prints its logo offline. An id never changes
 * content (a new upload is a new id), so a cached copy is never fetched
 * again.
 *
 * loadMedia shares one request per image among callers, remembers a failed
 * image for 5 minutes (each pull still tries again), and never keeps an
 * image above 512 KB.
 */
export const MAX_MEDIA_BYTES = 512 * 1024;
export const FAILURE_TTL_MS = 5 * 60 * 1000;

const memory = new Map();
const inflight = new Map();
const failures = new Map();
const listeners = new Set();

/** The cache key and server path of an image reference ({ source, id }), or null. */
export function mediaRef(ref) {
  if (!ref?.id || !/^[0-9a-f-]{36}$/i.test(ref.id)) return null;
  if (ref.source === 'brand_asset') return { key: `brand:${ref.id}`, path: `sync/brand-assets/${ref.id}` };
  if (ref.source === 'item_image') return { key: `item:${ref.id}`, path: `sync/media/${ref.id}` };
  return null;
}

/** The decoded size of a base64 data URI, in bytes. */
export function dataUriBytes(uri) {
  const comma = uri.indexOf(',');
  const body = comma < 0 ? uri : uri.slice(comma + 1);
  const padding = body.endsWith('==') ? 2 : body.endsWith('=') ? 1 : 0;
  return Math.floor((body.length * 3) / 4) - padding;
}

/**
 * The image as a data URI: from memory, the local cache, else the server.
 * Null when unavailable, too large, or failed less than 5 minutes ago
 * (`retry` asks again regardless: each pull does).
 */
export async function loadMedia({ api, store }, ref, { retry = false, now = Date.now } = {}) {
  const where = mediaRef(ref);
  if (!where) return null;
  if (memory.has(where.key)) return memory.get(where.key);
  if (inflight.has(where.key)) return inflight.get(where.key);

  const request = (async () => {
    let uri = await store.cachedMedia(where.key);
    if (!uri) {
      const failedAt = failures.get(where.key);
      if (!retry && failedAt !== undefined && now() - failedAt < FAILURE_TTL_MS) return null;
      try {
        uri = await api.image(where.path);
      } catch {
        uri = null;
      }
      if (uri && dataUriBytes(uri) > MAX_MEDIA_BYTES) uri = null;
      if (!uri) {
        failures.set(where.key, now());
        return null;
      }
      failures.delete(where.key);
      await store.saveMedia(where.key, uri);
    }
    memory.set(where.key, uri);
    return uri;
  })();

  inflight.set(where.key, request);
  try {
    return await request;
  } finally {
    inflight.delete(where.key);
  }
}

/** The image references the synced theme and POS layout name: both logos and every category image. */
export function referencedMedia(settings, layout) {
  const payload = settings?.theme?.payload ?? {};
  const refs = [payload.logo_light, payload.logo_dark].filter((id) => typeof id === 'string' && id).map((id) => ({ source: 'brand_asset', id }));
  for (const category of Array.isArray(layout?.categories) ? layout.categories : []) {
    if (category?.image) refs.push(category.image);
  }
  return refs.filter((ref) => mediaRef(ref) !== null);
}

/**
 * After a pull: fetch every image the theme and layout name into the cache
 * (a failure is tried again on the next pull), then drop the cached images
 * they no longer name, so the cache never grows past what the till shows.
 */
export async function syncMedia({ api, store }) {
  const [settings, layoutRow] = await Promise.all([store.settings(), store.posLayout()]);
  const refs = referencedMedia(settings, layoutRow?.layout);
  await Promise.all(refs.map((ref) => loadMedia({ api, store }, ref, { retry: true })));

  const wanted = new Set(refs.map((ref) => mediaRef(ref).key));
  const stale = (await store.mediaKeys()).filter((key) => !wanted.has(key));
  if (stale.length) await store.deleteMedia(stale);
  for (const key of [...memory.keys()]) if (!wanted.has(key)) memory.delete(key);
  listeners.forEach((listener) => listener());
}

/** Tests start from empty memory, request and failure caches. */
export function clearMediaMemory() {
  memory.clear();
  inflight.clear();
  failures.clear();
}

/** The cached image, never the network: memory, else the local cache. */
async function cachedOnly(store, key) {
  if (memory.has(key)) return memory.get(key);
  const uri = await store.cachedMedia(key);
  if (uri) memory.set(key, uri);
  return uri ?? null;
}

/** The cached image for a reference: null until (and unless) a pull has fetched it. */
export function useMedia(ref) {
  const { store } = useServices();
  const key = mediaRef(ref)?.key ?? null;
  const [state, setState] = useState(() => ({ key, uri: key ? (memory.get(key) ?? null) : null }));
  useEffect(() => {
    let active = true;
    if (!key) return undefined;
    const read = () => cachedOnly(store, key).then((uri) => active && setState({ key, uri }));
    read();
    listeners.add(read);
    return () => {
      active = false;
      listeners.delete(read);
    };
  }, [key, store]);
  return key && state.key === key ? state.uri : null;
}
