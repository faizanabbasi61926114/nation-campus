import type { ReactElement } from 'react'
import Accounts from '../pages/Accounts'
import Academics from '../pages/Academics'
import Admissions from '../pages/Admissions'
import Announcements from '../pages/Announcements'
import Attendance from '../pages/Attendance'
import Audit from '../pages/Audit'
import Campuses from '../pages/Campuses'
import Calendar from '../pages/Calendar'
import Certificates from '../pages/Certificates'
import Dashboard from '../pages/Dashboard'
import Discipline from '../pages/Discipline'
import Entries from '../pages/Entries'
import Exams from '../pages/Exams'
import Fees from '../pages/Fees'
import FrontDesk from '../pages/FrontDesk'
import Hostel from '../pages/Hostel'
import Library from '../pages/Library'
import Messages from '../pages/Messages'
import Payroll from '../pages/Payroll'
import Reports from '../pages/Reports'
import Roles from '../pages/Roles'
import Settings from '../pages/Settings'
import Staff from '../pages/Staff'
import Students from '../pages/Students'
import Timetable from '../pages/Timetable'
import Transport from '../pages/Transport'
import Users from '../pages/Users'

export interface ModulePage {
  /** Website path; it doubles as the catalogue key in lib/nav. */
  to: string
  /** Pages that own their sub-paths (thread ids, report filters). */
  splat?: boolean
  el: ReactElement
}

/**
 * One list, mounted twice: at each website path and again under /app/office. The phone app and the
 * desktop workspace therefore cannot drift apart, and both hit the same API and permissions.
 */
export const MODULE_PAGES: ModulePage[] = [
  { to: '/', el: <Dashboard /> },
  { to: '/reports', splat: true, el: <Reports /> },
  { to: '/admissions', el: <Admissions /> },
  { to: '/students', el: <Students /> },
  { to: '/programs', el: <Academics /> },
  { to: '/timetable', el: <Timetable /> },
  { to: '/attendance', el: <Attendance /> },
  { to: '/exams', el: <Exams /> },
  { to: '/frontdesk', el: <FrontDesk /> },
  { to: '/gate', el: <Entries /> },
  { to: '/calendar', el: <Calendar /> },
  { to: '/library', el: <Library /> },
  { to: '/transport', el: <Transport /> },
  { to: '/hostel', el: <Hostel /> },
  { to: '/certificates', el: <Certificates /> },
  { to: '/discipline', el: <Discipline /> },
  { to: '/fees', el: <Fees /> },
  { to: '/accounts', el: <Accounts /> },
  { to: '/payroll', el: <Payroll /> },
  { to: '/staff', el: <Staff /> },
  { to: '/hr/staff-attendance', el: <Attendance initial="staff" /> },
  { to: '/hr/leave', el: <Staff initial="leave" /> },
  { to: '/announcements', splat: true, el: <Announcements /> },
  { to: '/messages', splat: true, el: <Messages /> },
  { to: '/campuses', el: <Campuses /> },
  { to: '/users', el: <Users /> },
  { to: '/roles', el: <Roles /> },
  { to: '/audit', el: <Audit /> },
  { to: '/settings', el: <Settings /> },
]

/** Route pattern under the website layout; the dashboard is mounted as the index route. */
export const websitePathOf = (m: ModulePage): string => (m.to === '/' ? '' : `${m.to}${m.splat ? '/*' : ''}`)

/** Same page under the phone shell, e.g. office/fees or office/hr/leave. */
export const phonePathOf = (m: ModulePage): string => (m.to === '/' ? 'office/dashboard' : `office${websitePathOf(m)}`)
