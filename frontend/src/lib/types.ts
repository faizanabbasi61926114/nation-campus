export interface CampusBrief {
  id: number
  name: string
  code: string
  city?: string
  school_type?: string
}

export interface User {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
  organization_id: number
  campus_id: number | null
  campuses: CampusBrief[]
  is_head_office: boolean
}

/** Laravel's length-aware paginator serialises flat: rows under `data`, page counters alongside. */
export interface Paginated<T> {
  data: T[]
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export function hasPerm(user: User | null, perm: string): boolean {
  if (!user) return false
  if (user.permissions.includes('*')) return true
  return user.permissions.includes(perm)
}

export const fmtPKR = (n: number | string | null | undefined) =>
  n === null || n === undefined
    ? '—'
    : new Intl.NumberFormat('en-PK', { style: 'currency', currency: 'PKR', maximumFractionDigits: 0 }).format(Number(n))

export const fmtDate = (d?: string | null) => (d ? new Date(d).toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

export const fmtDateTime = (d?: string | null) => (d
  ? new Date(d).toLocaleString('en-PK', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  : '—')
