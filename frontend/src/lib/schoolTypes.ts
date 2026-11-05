import type { User } from './types'

/**
 * A campus is one kind of institution, and that decides which campus-life modules it actually runs.
 * The backend stores the value; this is the display order, the plain-English name and the gating.
 */
export const SCHOOL_TYPES = ['montessori', 'junior', 'secondary', 'higher_secondary', 'college', 'university'] as const
export type SchoolType = (typeof SCHOOL_TYPES)[number]

const TYPE_NAMES: Record<string, string> = {
  montessori: 'Montessori',
  junior: 'Junior School',
  secondary: 'Secondary (Matric)',
  higher_secondary: 'Higher Secondary',
  college: 'College',
  university: 'University',
}

export function schoolTypeName(type?: string | null): string {
  if (!type) return '—'
  return TYPE_NAMES[type] ?? type
}

/**
 * Modules a school of each type runs. Anything not listed is available everywhere.
 * Boarding only from secondary upwards; young campuses have no leave certificates or conduct cases.
 */
const RUNS: Record<string, SchoolType[]> = {
  hostel: ['secondary', 'higher_secondary', 'college', 'university'],
  certificates: ['junior', 'secondary', 'higher_secondary', 'college', 'university'],
  discipline: ['junior', 'secondary', 'higher_secondary', 'college', 'university'],
}

/**
 * `types` is the school type of the selected campus, or every accessible campus when the user is
 * looking at the whole organization. A module shows if at least one of those schools runs it.
 */
export function moduleAvailable(mod: string, types: (string | undefined | null)[]): boolean {
  const allowed = RUNS[mod]
  if (!allowed) return true
  const known = types.filter((t): t is string => !!t)
  if (!known.length) return true
  return known.some((t) => allowed.includes(t as SchoolType))
}

/** The school types in play: the selected campus, or every campus when viewing the whole organization. */
export function scopeTypes(user: User | null, campusId: number | null): (string | undefined)[] {
  const list = user?.campuses ?? []
  if (!list.length) return []
  if (campusId === null) return list.map((c) => c.school_type)
  return [list.find((c) => c.id === campusId)?.school_type]
}
