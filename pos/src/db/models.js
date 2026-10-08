import { Model } from '@nozbe/watermelondb';
import { schema } from './schema';

/**
 * One WatermelonDB model per table, without decorators: every column is
 * readable as a property (`item.code`, `outbox.status`) and the synced
 * payload as `record.data` (parsed once per raw value). Writes go through
 * src/sync/store.js, never through these classes.
 */
export class LocalRecord extends Model {
  get data() {
    const raw = this._raw.data;
    if (this._dataSource !== raw) {
      this._dataSource = raw;
      try {
        this._dataValue = raw ? JSON.parse(raw) : null;
      } catch {
        this._dataValue = null;
      }
    }
    return this._dataValue;
  }
}

function modelFor(table) {
  const Record = class extends LocalRecord {
    static table = table.name;
  };
  for (const column of Object.keys(table.columns)) {
    if (column === 'data' || column === 'id') continue;
    Object.defineProperty(Record.prototype, column, {
      get() {
        return this._raw[column];
      },
    });
  }
  Object.defineProperty(Record, 'name', { value: `${table.name}Record` });
  return Record;
}

export const modelClasses = Object.values(schema.tables).map(modelFor);
