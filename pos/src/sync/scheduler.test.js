import { createSyncScheduler } from './scheduler';

// NFR-04: sync on start, on reconnect and on a schedule while online.

function fakeEngine() {
  const calls = [];
  let status = { pending: 0 };
  return {
    calls,
    setPending: (pending) => (status = { ...status, pending }),
    network: null,
    load: async () => status,
    sync: async (options) => {
      calls.push(options);
      return {};
    },
    getStatus: () => status,
    listeners: new Set(),
    subscribe(listener) {
      this.listeners.add(listener);
      return () => this.listeners.delete(listener);
    },
    queue(pending) {
      status = { ...status, pending };
      this.listeners.forEach((listener) => listener(status));
    },
    setNetwork(network) {
      this.network = network;
    },
    store: { nextAttemptAt: async () => 0 },
  };
}

function fakeNetInfo() {
  let listener = null;
  return {
    addEventListener: (fn) => {
      listener = fn;
      return () => (listener = null);
    },
    emit: (state) => listener?.(state),
  };
}

describe('sync scheduler', () => {
  it('syncs fully on start, then the catalogue and snapshots on their intervals', async () => {
    let time = 0;
    const engine = fakeEngine();
    const scheduler = createSyncScheduler({ engine, now: () => time, timers: { setInterval: () => 1, clearInterval: () => {} } });

    await scheduler.start();
    expect(engine.calls).toEqual([{ pull: 'all', force: true }]);

    time = 60 * 1000;
    await scheduler.tick();
    expect(engine.calls).toHaveLength(1);

    time = 5 * 60 * 1000;
    await scheduler.tick();
    expect(engine.calls.at(-1)).toEqual({ pull: 'incremental' });

    time = 15 * 60 * 1000;
    await scheduler.tick();
    expect(engine.calls.at(-1)).toEqual({ pull: 'all' });
  });

  it('uploads between pulls when records wait', async () => {
    let time = 0;
    const engine = fakeEngine();
    const scheduler = createSyncScheduler({ engine, now: () => time, timers: { setInterval: () => 1, clearInterval: () => {} } });
    await scheduler.start();

    engine.setPending(2);
    time = 30 * 1000;
    await scheduler.tick();
    expect(engine.calls.at(-1)).toEqual({ pull: false });
  });

  it('syncs on reconnect and not while offline', async () => {
    let time = 0;
    const engine = fakeEngine();
    const netInfo = fakeNetInfo();
    const scheduler = createSyncScheduler({ engine, netInfo, now: () => time, timers: { setInterval: () => 1, clearInterval: () => {} } });
    await scheduler.start();

    netInfo.emit({ isConnected: false });
    expect(engine.network).toBe('offline');
    time = 60 * 60 * 1000;
    await scheduler.tick();
    expect(engine.calls).toHaveLength(1);

    netInfo.emit({ isConnected: true, isInternetReachable: true });
    expect(engine.network).toBe('online');
    expect(engine.calls.at(-1)).toEqual({ pull: 'all' });
    scheduler.stop();
  });

  it('never lets a failing run or status load reject', async () => {
    const engine = fakeEngine();
    engine.load = async () => {
      throw new Error('db');
    };
    engine.sync = async () => {
      throw new Error('boom');
    };
    const scheduler = createSyncScheduler({ engine, now: () => 0, timers: { setInterval: () => 1, clearInterval: () => {} } });

    await expect(scheduler.start()).resolves.toMatchObject({ error: expect.any(Error) });
    engine.setPending(1);
    await expect(scheduler.tick()).resolves.toMatchObject({ error: expect.any(Error) });
  });

  it('uploads soon after a record is queued while online, not at the next tick (NFR-04, POS-10)', async () => {
    const engine = fakeEngine();
    const netInfo = fakeNetInfo();
    const pending = [];
    const timers = { setInterval: () => 1, clearInterval: () => {}, setTimeout: (fn) => (pending.push(fn), pending.length), clearTimeout: () => {} };
    const scheduler = createSyncScheduler({ engine, netInfo, timers });
    await scheduler.start();
    netInfo.emit({ isConnected: true, isInternetReachable: true });
    engine.calls.length = 0;

    engine.queue(1);
    engine.queue(2);
    expect(pending).toHaveLength(1);
    await pending.shift()();
    expect(engine.calls).toEqual([{ pull: false }]);

    engine.queue(1);
    expect(pending).toHaveLength(0);

    netInfo.emit({ isConnected: false });
    engine.queue(2);
    expect(pending).toHaveLength(0);
    scheduler.stop();
  });
});
