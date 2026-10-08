import SQLiteAdapter from '@nozbe/watermelondb/adapters/sqlite';
import { migrations } from './migrations';
import { schema } from './schema';

/** Android and iOS: SQLite through JSI (needs a development build, not Expo Go). */
export function createAdapter({ dbName = 'app' } = {}) {
  return new SQLiteAdapter({ dbName, schema, migrations, jsi: true });
}
