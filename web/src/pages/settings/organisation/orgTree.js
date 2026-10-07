// TEN-02..TEN-05: companies → branches → locations, built from the three
// flat lists the API returns for the user's scope (RBAC-04). A branch-scoped
// user does not see its company, and a location-scoped user does not see
// its branch: those parents come from the names embedded in the children
// (`branch.company`, `location.branch`) and are marked `visible: false`, so
// the page labels them but offers no actions on them.

/** StoreLocationRequest::TYPES */
export const LOCATION_TYPES = ['outlet', 'warehouse', 'store', 'office']

export const isArchived = (record) => Boolean(record?.archived_at)

/**
 * @returns {{ companies: Array, orphanBranches: Array }} companies with
 *   `branches[].locations[]`; orphanBranches are branches whose company is unknown.
 */
export function buildTree(companies = [], branches = [], locations = []) {
  const companyNodes = new Map(companies.map((company) => [company.id, { ...company, visible: true, branches: [] }]))
  const branchNodes = new Map()

  for (const branch of branches) {
    if (!companyNodes.has(branch.company_id)) {
      companyNodes.set(branch.company_id, {
        id: branch.company_id,
        name: branch.company?.name ?? '',
        archived_at: null,
        visible: false,
        branches: [],
      })
    }
    const node = { ...branch, visible: true, locations: [] }
    branchNodes.set(branch.id, node)
    companyNodes.get(branch.company_id).branches.push(node)
  }

  const orphanBranches = []
  for (const location of locations) {
    if (!branchNodes.has(location.branch_id)) {
      const node = { id: location.branch_id, name: location.branch?.name ?? '', archived_at: null, visible: false, locations: [] }
      branchNodes.set(location.branch_id, node)
      orphanBranches.push(node)
    }
    branchNodes.get(location.branch_id).locations.push(location)
  }

  return { companies: [...companyNodes.values()], orphanBranches }
}

/** The tree without archived records (records are archived bottom-up, TEN-06). */
export function withoutArchived(tree) {
  const branch = (node) => ({ ...node, locations: node.locations.filter((location) => !isArchived(location)) })
  return {
    companies: tree.companies
      .filter((company) => !isArchived(company))
      .map((company) => ({ ...company, branches: company.branches.filter((node) => !isArchived(node)).map(branch) })),
    orphanBranches: tree.orphanBranches.filter((node) => !isArchived(node)).map(branch),
  }
}

/** True when any record in the lists is archived (the "Show archived" switch is offered only then). */
export function hasArchived(...lists) {
  return lists.some((list) => list.some(isArchived))
}

/** The organisation lists every page shares; any change refreshes all three (and the company switcher). */
export function invalidateOrganisation(queryClient) {
  return queryClient.invalidateQueries({ predicate: (query) => ['companies', 'branches', 'locations'].includes(query.queryKey[0]) })
}
