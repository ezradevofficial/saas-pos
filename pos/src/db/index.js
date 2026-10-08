import { Database } from '@nozbe/watermelondb';
import { createAdapter } from './adapter';
import { modelClasses } from './models';

export { schema, SCHEMA_VERSION } from './schema';

/** The till's database over the platform's adapter (or one given, for tests). */
export function createDatabase({ adapter, dbName } = {}) {
  return new Database({ adapter: adapter ?? createAdapter({ dbName }), modelClasses });
}
