import { wireOf } from './records';

// AUTH-07, VAN-27: the sign-in proof rides along on every record that names a person, so the server can check who made it.
describe('van sales wire records', () => {
  const proof = { session_id: 's1', signature: 'abc' };

  it('carries the proof on runs, visits, collections and counts', () => {
    const base = {
      id: 'a',
      run_id: 'r',
      user_id: 'u',
      actor_proof: proof,
      started_at: 't',
      customer_id: 'c',
      status: 'visited',
      updated_at: 't',
      currency: 'KES',
      amount_minor: 100,
      payment_method_id: 'm',
      receipt_seq: 1,
      receipt_number: 'R1',
      collected_at: 't',
      submitted_at: 't',
      lines: [],
      tenders: [],
    };
    for (const kind of ['run', 'visit', 'collection', 'count']) {
      expect(wireOf({ ...base, kind }).actor_proof).toEqual(proof);
    }
  });

  it('leaves the proof out when the person has none', () => {
    expect(wireOf({ kind: 'run', id: 'a', user_id: 'u', started_at: 't' })).not.toHaveProperty('actor_proof');
  });
});
