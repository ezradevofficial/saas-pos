import { applyResult, BODY_KEYS, isFinal, wireOf } from './records';

/**
 * SAL-29, NFR-04: uploading what the salon till made, through `POST salon/device/sync`.
 *
 * - push(call): the pending records go in batches of at most 100 in all (appointments first, then the steps of
 *   the day, then queue changes, each in the order made, so a step is never behind its appointment). The server
 *   answers one result per record, the same each time, so a resend after a lost answer is safe.
 * - A result is folded in with the record's revision as it was sent. `stored` is done; `rejected` is parked on
 *   the record with its code (`slot_taken`, `actor_unverified`...). A request that fails, a 5xx or 429,
 *   `module_inactive` or an answer without results leaves everything pending to try later.
 * - Nothing is deleted from the device.
 *
 * `call(method, path, { body })` is the sync engine's request function.
 */
export const BATCH = 100;
export const PATH = 'salon/device/sync';

const emptyBody = () => ({ appointments: [], events: [], queue: [] });

export function createSalonSync({ records, log = () => {} }) {
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
          summary.retried += batch.length;
          summary.stopped = response.body?.code ?? `http_${response.status}`;
          log('salon: upload not accepted', response.status);
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
