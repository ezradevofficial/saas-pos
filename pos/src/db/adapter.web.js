import LokiJSAdapter from '@nozbe/watermelondb/adapters/lokijs';
import { migrations } from './migrations';
import { schema } from './schema';

/** Expo web preview: LokiJS kept in IndexedDB (memory when IndexedDB is unavailable). */
export function createAdapter({ dbName = 'app' } = {}) {
  return new LokiJSAdapter({ dbName, schema, migrations, useWebWorker: false, useIncrementalIndexedDB: true });
}
