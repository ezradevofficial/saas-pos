const TEMPLATE = 'template:'

/** Whether a stage's role ref names this role: its id, or `template:<key>` for a system role (WF-08). */
export const names = (ref, role) => ref === role.id || (role.is_system && role.template_key && ref === `${TEMPLATE}${role.template_key}`)
