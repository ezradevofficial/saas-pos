import { useTranslation } from 'react-i18next'
import { Checkbox } from '@/components/ds'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

// The usual lifecycle first; any other action follows in the order first seen.
const ORDER = ['view', 'create', 'edit', 'archive']
const rank = (action) => (ORDER.includes(action) ? ORDER.indexOf(action) : ORDER.length)

/** The action columns of a module: every action its resources have, view, create, edit and archive first. */
function actionColumns(resources) {
  const columns = new Map()
  for (const resource of Object.values(resources)) {
    for (const action of resource.actions) if (!columns.has(action.action)) columns.set(action.action, action.label)
  }
  return [...columns.entries()].map(([action, label]) => ({ action, label })).sort((a, b) => rank(a.action) - rank(b.action))
}

/**
 * RBAC-01, RBAC-02: the permission catalogue (GET permissions) as one table
 * per module: resources as rows, actions as columns, a checkbox where the
 * resource has that action. Scrolls inside its container on phones.
 */
export function PermissionMatrix({ catalogue, selected, onToggle, disabled }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-6">
      {Object.entries(catalogue).map(([module, { label, resources }]) => {
        const columns = actionColumns(resources)
        return (
          <section key={module} aria-labelledby={`module-${module}`} className="flex flex-col gap-2">
            <h3 id={`module-${module}`} className="text-h3 text-ink">
              {label}
            </h3>
            <div className="rounded-md border border-border">
              <Table className="text-body">
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead scope="col" className="h-auto px-4 py-2 text-caption font-normal text-ink-muted">
                      {t('roles.matrix.resource')}
                    </TableHead>
                    {columns.map((column) => (
                      <TableHead key={column.action} scope="col" className="h-auto px-3 py-2 text-center text-caption font-normal text-ink-muted">
                        {column.label}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {Object.entries(resources).map(([resource, entry]) => (
                    <TableRow key={resource} className="hover:bg-surface-100">
                      <TableHead scope="row" className="h-auto px-4 py-2 font-normal text-ink">
                        {entry.label}
                      </TableHead>
                      {columns.map((column) => {
                        const permission = entry.actions.find((action) => action.action === column.action)
                        return (
                          <TableCell key={column.action} className="px-3 py-2">
                            {permission ? (
                              <Checkbox
                                className="justify-center gap-0"
                                label={<span className="sr-only">{t('roles.matrix.cell', { resource: entry.label, action: permission.label })}</span>}
                                checked={selected.has(permission.name)}
                                onChange={() => onToggle(permission.name)}
                                disabled={disabled}
                              />
                            ) : null}
                          </TableCell>
                        )
                      })}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </section>
        )
      })}
    </div>
  )
}
