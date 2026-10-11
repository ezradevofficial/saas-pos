import { createSyncStore } from '../sync/store';
import { testDatabase } from '../test/testDatabase';
import { clearMediaMemory, FAILURE_TTL_MS, loadMedia, MAX_MEDIA_BYTES, referencedMedia, syncMedia } from './media';

// BR-02, LAY-05, NFR-04: the till's media cache: one request per image, failures
// remembered for 5 minutes, no image above 512 KB, every referenced image fetched
// after a pull and the rest evicted.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const LIGHT = id(1);
const DARK = id(2);
const CATEGORY_IMAGE = id(3);
const OLD = id(4);
const uri = (n) => `data:image/png;base64,${'A'.repeat(4 * n)}`;

function fakeStore({ settings = null, layout = null, cached = {} } = {}) {
  const media = new Map(Object.entries(cached));
  return {
    media,
    settings: async () => settings,
    posLayout: async () => (layout ? { layout } : null),
    cachedMedia: async (key) => media.get(key) ?? null,
    saveMedia: jest.fn(async (key, value) => {
      media.set(key, value);
    }),
    mediaKeys: async () => [...media.keys()],
    deleteMedia: jest.fn(async (keys) => keys.forEach((key) => media.delete(key))),
  };
}

beforeEach(() => clearMediaMemory());

describe('loadMedia', () => {
  it('shares one request per image among callers', async () => {
    let release;
    const api = { image: jest.fn(() => new Promise((resolve) => (release = resolve))) };
    const store = fakeStore();
    const ref = { source: 'brand_asset', id: LIGHT };
    const both = Promise.all([loadMedia({ api, store }, ref), loadMedia({ api, store }, ref)]);
    await new Promise((resolve) => setTimeout(resolve, 0));
    release(uri(1));
    expect(await both).toEqual([uri(1), uri(1)]);
    expect(api.image).toHaveBeenCalledTimes(1);
  });

  it('remembers a failure for 5 minutes, unless asked to retry', async () => {
    let clock = 1_000_000;
    const now = () => clock;
    const api = { image: jest.fn(async () => null) };
    const store = fakeStore();
    const ref = { source: 'item_image', id: CATEGORY_IMAGE };

    expect(await loadMedia({ api, store }, ref, { now })).toBeNull();
    expect(await loadMedia({ api, store }, ref, { now })).toBeNull();
    expect(api.image).toHaveBeenCalledTimes(1);

    await loadMedia({ api, store }, ref, { now, retry: true });
    expect(api.image).toHaveBeenCalledTimes(2);

    clock += FAILURE_TTL_MS;
    await loadMedia({ api, store }, ref, { now });
    expect(api.image).toHaveBeenCalledTimes(3);
  });

  it('never keeps an image above 512 KB', async () => {
    const big = `data:image/png;base64,${'A'.repeat(Math.ceil(((MAX_MEDIA_BYTES + 3) * 4) / 3))}`;
    const store = fakeStore();
    expect(await loadMedia({ api: { image: async () => big }, store }, { source: 'brand_asset', id: LIGHT })).toBeNull();
    expect(store.saveMedia).not.toHaveBeenCalled();
  });
});

describe('syncMedia', () => {
  const settings = { theme: { payload: { logo_light: LIGHT, logo_dark: DARK } } };
  const layout = { categories: [{ id: id(9), image: { source: 'item_image', id: CATEGORY_IMAGE } }, { id: id(10), image: null }] };

  it('names both logos and the category images', () => {
    expect(referencedMedia(settings, layout).map((ref) => ref.id)).toEqual([LIGHT, DARK, CATEGORY_IMAGE]);
  });

  it('fetches what the theme and layout name, drops the rest, and tries a failure again on the next pull', async () => {
    const images = { [`sync/brand-assets/${LIGHT}`]: uri(1), [`sync/media/${CATEGORY_IMAGE}`]: uri(3) };
    const api = { image: jest.fn(async (path) => images[path] ?? null) };
    const store = fakeStore({ settings, layout, cached: { [`brand:${OLD}`]: uri(4) } });

    await syncMedia({ api, store });
    expect([...store.media.keys()].sort()).toEqual([`brand:${LIGHT}`, `item:${CATEGORY_IMAGE}`]);
    expect(store.deleteMedia).toHaveBeenCalledWith([`brand:${OLD}`]);

    // The dark logo failed; the next pull asks again (not the cached ones).
    images[`sync/brand-assets/${DARK}`] = uri(2);
    api.image.mockClear();
    await syncMedia({ api, store });
    expect(api.image.mock.calls.map(([path]) => path)).toEqual([`sync/brand-assets/${DARK}`]);
    expect(store.media.get(`brand:${DARK}`)).toBe(uri(2));
  });

  it('evicts from the till database', async () => {
    const store = createSyncStore(testDatabase());
    await store.saveMedia(`brand:${LIGHT}`, uri(1));
    await store.saveMedia(`brand:${OLD}`, uri(4));
    await store.deleteMedia([`brand:${OLD}`]);
    expect(await store.mediaKeys()).toEqual([`brand:${LIGHT}`]);
  });
});
