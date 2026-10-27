import { applyResult, BODY_KEYS, isFinal, wireOf } from './records';

/**
 * VAN-27, NFR-04: uploading what the rep's device made, through
 * `POST van-sales/device/sync`.
 *
 * - push(call): the pending records go in batches of at most 100 in all
 *   (runs first, then visits, collections, counts, each in the order made, so
 *   a run is never behind its visits). The server answers one result per
 *   record, the same each time, so a resend after a lost answer is safe.
 * - A result is folded in with the record's revision as it was sent: a visit
 *   changed since stays pending (and goes again). `stored` is done;
 *   `rejected` is parked on the record with its code (it can never succeed
 *   as sent). A request that fails, a 5xx or 429, `module_inactive` or an
 *   answer without results leaves everything pending to try later.
 * - Nothing is deleted from the device.
 *
 * `call(method, path, { body })` is the sync engine's request function (it
 * records the network state and stops on lost access).
 */
export const BATCH = 100;
export const PATH = 'van-sales/device/sync';

const emptyBody = () => ({ runs: [], visits: [], collections: [], counts: [] });

export function createVsSync({ records, log = () => {} }) {
  return {
    pendingCount: () => records.pendingCount(),

    async push(call) {
      const summary = { sent: 0, stored: 0, rejected: 0, retried: 0, stopped: null };
      const pending = await records.pending();
      for (let i = 0; i < pending.length; i += BATCH) {
        const batch = pending.slice(i, i + BATCH);
        const body = emptyBody();
        for (const record of batch) body[BODY_KEYS[record.kind]].push(wireOf(record));
        const response = await call('POST', PATH, { body });
        summary.sent += batch.length;
        const results = response.body?.results ?? null;
        if (!((response.status === 200 || response.status === 422) && results && typeof results === 'object')) {
          // 403 module_inactive, 5xx, 429, anything unexpected: nothing proves the records wrong; try later.
          summary.retried += batch.length;
          summary.stopped = response.body?.code ?? `http_${response.status}`;
          log('van sales: upload not accepted', response.status);
          break;
        }
        for (const record of batch) {
          const answers = Array.isArray(results[BODY_KEYS[record.kind]]) ? results[BODY_KEYS[record.kind]] : [];
          const result = answers.find((answer) => String(answer?.id) === record.id);
          if (!isFinal(result)) {
            summary.retried += 1;
            continue;
          }
          const sentRev = record.local.rev;
          await records.update(record.id, (current) => applyResult(current, result), {
            // Done only when the record is as it was when sent; a visit edited meanwhile goes again.
            settle: (next) => (next.local.rev !== sentRev ? 'pending' : result.status === 'stored' ? 'stored' : 'rejected'),
          });
          if (result.status === 'stored') summary.stored += 1;
          else summary.rejected += 1;
        }
      }
      return summary;
    },
  };
}
