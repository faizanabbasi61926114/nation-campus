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

/** Who the phone shell is for: family tabs, staff tabs, or nobody. */
export function mobileAudience(user: User | null): 'parent' | 'staff' | null {
  if (!user) return null
  return isHousehold(user) ? 'parent' : 'staff'
}

/**
 * A class teacher opens the app to run a school day, so their first screen is the roll.
 * Everyone else - principals, finance, HR, head office - opens it on the campus overview.
 */
export function isClassTeacher(user: User | null): boolean {
  return !!user?.roles?.includes('class_teacher')
}
