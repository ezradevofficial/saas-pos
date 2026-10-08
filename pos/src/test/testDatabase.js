import LokiJSAdapter from '@nozbe/watermelondb/adapters/lokijs';
import { createDatabase } from '../db';
import { migrations } from '../db/migrations';
import { schema } from '../db/schema';

let counter = 0;

/** A fresh in-memory database (LokiJS, no autosave timer) for one test. */
export function testDatabase() {
  counter += 1;
  const adapter = new LokiJSAdapter({
    dbName: `test-${counter}`,
    schema,
    migrations,
    useWebWorker: false,
    useIncrementalIndexedDB: false,
    extraLokiOptions: { autosave: false },
  });
  return createDatabase({ adapter });
}
