import { uuidv7 } from '../lib/random';
import { appointmentViews, planWalkIn, queueViews } from './day';

/**
 * SAL-12, SAL-13, SAL-29: what the salon staff do at the till, offline first. Every action saves its record on the
 * device before it returns (salonStore.js); the sync engine uploads it later (salonSync.js). Actions take the
 * person (`user`) and the sign-in proof as arguments, so they run in Jest without React. The server decides again:
 * a clash comes back as a rejected record (`slot_taken`), a proof it cannot verify as `actor_unverified`.
 */
export class SalonError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'SalonError';
    this.code = code;
    Object.assign(this, extra);
  }
}

/** The person may do this at the till (the server checks their right again on upload). */
export const SALON = {
  serve: 'salon.appointment.serve',
  create: 'salon.appointment.create',
  cancel: 'salon.appointment.cancel',
  queue: 'salon.queue.manage',
};

export const canSalon = (user, permission) => Boolean(user?.owner || user?.permissions?.includes(permission));

export function createSalon({ records, engine = null, now = () => Date.now() }) {
  const nowMs = () => (engine?.serverNow ? engine.serverNow() : now());
  const iso = () => new Date(nowMs()).toISOString();

  // The engine counts pending records in `pending`; tell it after every write so the scheduler uploads soon.
  if (engine?.refreshCounts) records.subscribe(() => engine.refreshCounts().catch(() => {}));

  const service = {
    records,

    /** The company's salon rules, or null: Salon is active on this till only when the row was pulled (SAL-01). */
    settings: () => records.settings(),

    /** Everything a screen shows, computed from the pulled data and the till's own records. */
    async load() {
      const [settings, staffRows, services, schedule, queue, packages, appointmentsMade, events, queueMade, marks, rejected] = await Promise.all([
        records.settings(),
        records.staffRows(),
        records.services(),
        records.schedule(),
        records.queue(),
        records.packages(),
        records.list('appointment'),
        records.list('event'),
        records.list('queue'),
        records.list('mark'),
        records.rejected(),
      ]);
      const all = [...appointmentsMade, ...events, ...queueMade, ...marks];
      return {
        settings,
        staff: staffRows.filter((row) => row.kind === 'staff'),
        resources: staffRows.filter((row) => row.kind === 'resource'),
        services,
        packages,
        appointments: appointmentViews({ schedule, records: all }),
        queue: queueViews({ queue, records: queueMade }),
        rejected,
      };
    },

    /** SAL-13: check-in, start, finish, no-show or cancel, as a step record. A step already taken is not made twice. */
    async step(appointmentId, type, { user, actorProof = null, reason = null }) {
      const { appointments } = await service.load();
      const view = appointments.find((candidate) => candidate.id === appointmentId);
      if (!view) throw new SalonError('appointment_unknown');
      const target = { check_in: 'checked_in', start: 'in_service', finish: 'completed', no_show: 'no_show', cancel: 'cancelled' }[type];
      if (!target) throw new SalonError('step_unknown');
      if (['cancelled', 'no_show', 'paid'].includes(view.status)) throw new SalonError('appointment_closed');
      if (view.status === target) return null;
      if (['no_show', 'cancel'].includes(type) && !['booked', 'checked_in'].includes(view.status)) throw new SalonError('appointment_closed');
      const event = {
        id: uuidv7(nowMs()),
        kind: 'event',
        appointment_id: appointmentId,
        type,
        user_id: user.id,
        actor_proof: actorProof ?? undefined,
        at: iso(),
        reason: reason || undefined,
        local: {},
      };
      return records.add(event);
    },

    /**
     * SAL-12: books a walk-in on the till from the services pulled, starting now. `wants` are
     * `{ service_id, staff_id?, resource_id? }` in order; a person who is not free is refused here (the screen) and
     * again by the server under its locks (a rejected `slot_taken`).
     */
    async bookWalkIn({ wants, client = null, guestName = '', guestPhone = '', user, actorProof = null, startMs = null }) {
      if (!wants.length) throw new SalonError('services_required');
      if (!client && !String(guestName).trim()) throw new SalonError('client_required');
      const data = await service.load();
      const plan = planWalkIn({ wants: wants.map((want) => ({ ...want, id: uuidv7(nowMs()) })), startMs: startMs ?? nowMs(), staff: data.staff, services: data.services, views: data.appointments });
      if (plan.error) throw new SalonError(plan.error);
      const appointment = {
        id: uuidv7(nowMs()),
        kind: 'appointment',
        user_id: user.id,
        actor_proof: actorProof ?? undefined,
        client_id: client?.id,
        guest_name: client ? undefined : String(guestName).trim(),
        guest_phone: client ? undefined : guestPhone || undefined,
        source: 'walk_in',
        starts_at: plan.lines[0].starts_at,
        lines: plan.lines,
        local: { lines: plan.lines, client_name: client?.name ?? String(guestName).trim(), currency: data.settings?.currency ?? null },
      };
      return records.add(appointment);
    },

    /** SAL-12: a walk-in joins the queue. */
    async joinQueue({ client = null, guestName = '', guestPhone = '', serviceIds, staffId = null, user, actorProof = null }) {
      if (!client && !String(guestName).trim()) throw new SalonError('client_required');
      if (!serviceIds?.length) throw new SalonError('services_required');
      const entry = {
        id: uuidv7(nowMs()),
        kind: 'queue',
        action: 'join',
        user_id: user.id,
        actor_proof: actorProof ?? undefined,
        client_id: client?.id,
        guest_name: client ? undefined : String(guestName).trim(),
        guest_phone: client ? undefined : guestPhone || undefined,
        service_ids: serviceIds,
        staff_id: staffId || undefined,
        at: iso(),
        local: { client_name: client?.name ?? String(guestName).trim() },
      };
      return records.add(entry);
    },

    /** SAL-12: seats a waiting person with a free staff member, starting now. The server makes the appointment on upload. */
    async seatQueue({ entry, staffId, resourceId = null, user, actorProof = null }) {
      const data = await service.load();
      if (!data.queue.some((candidate) => candidate.id === entry.id)) throw new SalonError('queue_entry_closed');
      const plan = planWalkIn({
        wants: entry.service_ids.map((id) => ({ service_id: id, staff_id: staffId, resource_id: resourceId, id: uuidv7(nowMs()) })),
        startMs: nowMs(),
        staff: data.staff,
        services: data.services,
        views: data.appointments,
      });
      if (plan.error) throw new SalonError(plan.error);
      return records.add({
        id: uuidv7(nowMs()),
        kind: 'queue',
        action: 'seat',
        user_id: user.id,
        actor_proof: actorProof ?? undefined,
        entry_id: entry.id,
        staff_id: staffId,
        resource_id: resourceId || undefined,
        appointment_id: uuidv7(nowMs()),
        client_id: entry.client_id ?? undefined,
        at: plan.lines[0].starts_at,
        local: { lines: plan.lines, client_name: entry.guest_name, currency: data.settings?.currency ?? null },
      });
    },

    leaveQueue({ entry, user, actorProof = null }) {
      return records.add({ id: uuidv7(nowMs()), kind: 'queue', action: 'leave', user_id: user.id, actor_proof: actorProof ?? undefined, entry_id: entry.id, at: iso(), local: {} });
    },

    /** A sale completed on this till for an appointment marks it paid (or its fee paid) here until the server's row says so. */
    async recordSale(sale) {
      const link = sale?.extensions?.salon;
      if (!link?.appointment_id) return null;
      return records.add({ id: uuidv7(nowMs()), kind: 'mark', appointment_id: link.appointment_id, fact: link.fee ? 'fee_paid' : 'paid', sale_id: sale.id, local: {} });
    },

    /** Records the server refused, parked with their codes (the screen says so). */
    rejected: () => records.rejected(),

    /** Puts a parked record back in the queue (after the cause was fixed). */
    retry: (id) => records.update(id, (current) => ({ ...current, local: { ...current.local, error: null, result: null } })),
  };

  return service;
}
