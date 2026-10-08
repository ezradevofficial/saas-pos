import { migrations } from './migrations';
import { schema, SCHEMA_VERSION } from './schema';
import { registeredEntities } from '../sync/entities';

// NFR-04: an installed till upgrades its database in place; it never loses unsent records to a reinstall.
describe('database migrations', () => {
  it('reach SCHEMA_VERSION one step at a time', () => {
    expect(schema.version).toBe(SCHEMA_VERSION);
    expect(migrations.minVersion).toBe(1);
    expect(migrations.maxVersion).toBe(SCHEMA_VERSION);
    const versions = migrations.sortedMigrations.map((migration) => migration.toVersion);
    expect(versions).toEqual(Array.from({ length: SCHEMA_VERSION - 1 }, (_, i) => i + 2));
  });

  it('add only columns and tables the current schema has, with the same types', () => {
    for (const migration of migrations.sortedMigrations) {
      for (const step of migration.steps) {
        const table = schema.tables[step.table ?? step.schema?.name];
        expect(table).toBeDefined();
        for (const column of step.columns ?? Object.values(step.schema?.columns ?? {})) {
          expect(table.columns[column.name]).toMatchObject({ type: column.type });
        }
      }
    }
  });

  it('has a table for every registered sync entity, with seen_at', () => {
    for (const entity of registeredEntities()) {
      expect(schema.tables[entity.table]?.columns.seen_at).toBeDefined();
    }
  });
});
