import { useEffect, useState } from 'react';
import { useServices } from '../services/services';

/**
 * BR-02, LAY-05: logos and category images on the till. The server names
 * them by id (a brand asset or an item image); the till fetches each once
 * with its device token and keeps it in `media_cache` as a data URI, so it
 * shows offline (NFR-04). An id never changes content (a new upload is a
 * new id), so a cached copy is never fetched again.
 */
const memory = new Map();

/** The cache key and server path of an image reference ({ source, id }), or null. */
export function mediaRef(ref) {
  if (!ref?.id || !/^[0-9a-f-]{36}$/i.test(ref.id)) return null;
  if (ref.source === 'brand_asset') return { key: `brand:${ref.id}`, path: `sync/brand-assets/${ref.id}` };
  if (ref.source === 'item_image') return { key: `item:${ref.id}`, path: `sync/media/${ref.id}` };
  return null;
}

/** The image as a data URI: from memory, the local cache, else the server (online). Null when unavailable. */
export async function loadMedia({ api, store }, ref) {
  const where = mediaRef(ref);
  if (!where) return null;
  if (memory.has(where.key)) return memory.get(where.key);
  let uri = await store.cachedMedia(where.key);
  if (!uri) {
    try {
      uri = await api.image(where.path);
    } catch {
      uri = null;
    }
    if (uri) await store.saveMedia(where.key, uri);
  }
  if (uri) memory.set(where.key, uri);
  return uri ?? null;
}

/** Tests start from an empty memory cache. */
export function clearMediaMemory() {
  memory.clear();
}

/** A hook over loadMedia: null until (and unless) the image is available. */
export function useMedia(ref) {
  const services = useServices();
  const key = mediaRef(ref)?.key ?? null;
  const [uri, setUri] = useState(() => (key ? (memory.get(key) ?? null) : null));
  useEffect(() => {
    let active = true;
    if (!key) {
      setUri(null);
      return undefined;
    }
    loadMedia(services, ref).then((loaded) => active && setUri(loaded));
    return () => {
      active = false;
    };
    // The reference is identified by its key.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, services]);
  return uri;
}
