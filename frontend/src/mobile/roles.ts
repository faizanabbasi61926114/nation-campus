import { hasPerm, type User } from '../lib/types'

/**
 * A household account has no campus workspace to open: its whole product is the
 * phone screens. Staff keep the desktop, and reach /app through the header link.
 */
export function isHousehold(user: User | null): boolean {
  if (!user) return false
  return hasPerm(user, 'guardian.view') && !hasPerm(user, 'students.view') && !hasPerm(user, 'academics.view')
}

export function homePathFor(user: User | null): string {
  return isHousehold(user) ? '/app' : '/'
}

/** Who the phone shell is for: family tabs, teacher tabs, or nobody. */
export function mobileAudience(user: User | null): 'parent' | 'teacher' | null {
  if (!user) return null
  if (isHousehold(user)) return 'parent'
  return hasPerm(user, 'academics.view') ? 'teacher' : null
}
