import type { ComponentType, SVGProps } from 'react'
import { hasPerm, type User } from './types'
import { moduleAvailable } from './schoolTypes'
import {
  IconAcademics, IconAccounts, IconAdmissions, IconAnnouncements, IconAttendance, IconAudit, IconCampuses,
  IconCertificate, IconDashboard, IconDiscipline, IconExams, IconFees, IconFrontDesk, IconGate, IconHostel,
  IconLeave, IconLibrary, IconMessages, IconPayroll, IconReport, IconRoles, IconSettings, IconStaff,
  IconStudents, IconTimetable, IconTransport, IconUsers,
} from '../components/icons'

export type IconType = ComponentType<SVGProps<SVGSVGElement>>
/**
 * `mod` gates an entry by institution type; `app` points the phone at a native screen instead of
 * the website page mounted under /app/office. A screen listed in `app` must be reachable from the
 * tab bar or the launcher, otherwise the module would be invisible on a phone.
 */
export interface NavItem { to: string; label: string; perm: string; icon: IconType; mod?: string; app?: string }
export interface NavGroup { title: string; items: NavItem[] }

/**
 * The single module catalogue shared by the desktop sidebar and the phone launcher.
 * `mod` ties an entry to an institution type: a Montessori campus has no boarding house, so the
 * hostel should not be a dead link there. See lib/schoolTypes.
 */
export const NAV: NavGroup[] = [
  { title: 'Overview', items: [
    { to: '/', label: 'Dashboard', perm: 'dashboard.view', icon: IconDashboard },
    { to: '/reports', label: 'Reports', perm: 'reports.view', icon: IconReport },
  ]},
  { title: 'Admissions & Students', items: [
    { to: '/admissions', label: 'Admissions', perm: 'admissions.view', icon: IconAdmissions },
    { to: '/students', label: 'Students', perm: 'students.view', icon: IconStudents },
  ]},
  { title: 'Academics', items: [
    { to: '/programs', label: 'Programs & Classes', perm: 'academics.view', icon: IconAcademics },
    { to: '/timetable', label: 'Timetable', perm: 'academics.view', icon: IconTimetable },
    { to: '/attendance', label: 'Attendance', perm: 'attendance.view', icon: IconAttendance },
    { to: '/exams', label: 'Examinations', perm: 'exams.view', icon: IconExams },
  ]},
  { title: 'Campus Life', items: [
    { to: '/gate', label: 'Gate entry', perm: 'frontdesk.view', icon: IconGate, mod: 'frontdesk', app: '/app/gate' },
    { to: '/frontdesk', label: 'Front Desk', perm: 'frontdesk.view', icon: IconFrontDesk, mod: 'frontdesk' },
    { to: '/calendar', label: 'School Calendar', perm: 'calendar.view', icon: IconTimetable, mod: 'calendar' },
    { to: '/library', label: 'Library', perm: 'library.view', icon: IconLibrary, mod: 'library' },
    { to: '/transport', label: 'Transport', perm: 'transport.view', icon: IconTransport, mod: 'transport' },
    { to: '/hostel', label: 'Hostel', perm: 'hostel.view', icon: IconHostel, mod: 'hostel' },
    { to: '/certificates', label: 'Certificates', perm: 'documents.view', icon: IconCertificate, mod: 'certificates' },
    { to: '/discipline', label: 'Discipline', perm: 'discipline.view', icon: IconDiscipline, mod: 'discipline' },
  ]},
  { title: 'Finance', items: [
    { to: '/fees', label: 'Fee Management', perm: 'fees.view', icon: IconFees },
    { to: '/accounts', label: 'Accounts', perm: 'accounts.view', icon: IconAccounts },
    { to: '/payroll', label: 'Payroll', perm: 'payroll.view', icon: IconPayroll },
  ]},
  { title: 'Staff & HR', items: [
    { to: '/staff', label: 'Staff Directory', perm: 'staff.view', icon: IconStaff },
    { to: '/hr/staff-attendance', label: 'Staff Register', perm: 'attendance.manage', icon: IconAttendance, app: '/app/staff-attendance' },
    { to: '/hr/leave', label: 'Leave', perm: 'hr.view', icon: IconLeave },
  ]},
  { title: 'Communication', items: [
    { to: '/announcements', label: 'Announcements', perm: 'communication.view', icon: IconAnnouncements },
    { to: '/messages', label: 'Messages', perm: 'communication.view', icon: IconMessages },
  ]},
  { title: 'Administration', items: [
    { to: '/campuses', label: 'Campuses', perm: 'campuses.view', icon: IconCampuses },
    { to: '/users', label: 'Users', perm: 'users.view', icon: IconUsers },
    { to: '/roles', label: 'Roles & Permissions', perm: 'users.view', icon: IconRoles },
    { to: '/audit', label: 'Audit Log', perm: 'audit.view', icon: IconAudit },
    { to: '/settings', label: 'Settings', perm: 'settings.view', icon: IconSettings },
  ]},
]

export const FLAT = NAV.flatMap((g) => g.items.map((i) => ({ ...i, group: g.title })))

/** Desktop path → the same page mounted inside the phone shell. */
export const officePath = (to: string): string => `/app/office${to === '/' ? '/dashboard' : to}`

/** Where a catalogue entry opens on a phone: its own screen when it has one, the office mount otherwise. */
export const phonePathFor = (item: NavItem): string => item.app ?? officePath(item.to)

/** Entries with a screen of their own, so the shell can title them and offer a way back. */
export const NATIVE_SCREENS: NavItem[] = FLAT.filter((i) => i.app)

/** Every module the user may open, grouped, with empty groups dropped. */
export function groupsWithAccess(user: User | null, types: (string | undefined)[]): NavGroup[] {
  return NAV
    .map((g) => ({ ...g, items: g.items.filter((i) => hasPerm(user, i.perm) && moduleAvailable(i.mod ?? '', types)) }))
    .filter((g) => g.items.length)
}
