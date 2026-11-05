import type { Tone } from '../components/ui'

/**
 * Plain-English names for stored vocabulary values, plus one colour language for the whole app:
 * green = done or healthy, amber = waiting on someone, rose = a problem, blue = informational,
 * slate = neutral or closed. Values not listed here fall back to a sentence-case rule.
 */
const WORDS: Record<string, string> = {
  // Roles
  super_admin: 'Super Admin',
  head_office_admin: 'Head Office Admin',
  finance_director: 'Finance Director',
  admissions_officer: 'Admissions Officer',
  class_teacher: 'Class Teacher',
  exam_controller: 'Exam Controller',
  accounts_officer: 'Accounts Officer',
  hr_manager: 'HR Manager',

  // Admissions pipeline
  new: 'New enquiry',
  screening: 'Being screened',
  interview: 'Interview due',
  test: 'Test due',
  offered: 'Offer issued',
  accepted: 'Offer accepted',
  awaiting_review: 'Needs review',
  awaiting_family: 'Offer with family',
  awaiting_enrolment: 'Ready to enrol',
  rejected: 'Not selected',
  waitlisted: 'Waitlisted',

  // Student and staff status
  probation: 'On probation',
  graduated: 'Graduated',
  transferred: 'Transferred',

  // Attendance and leave
  late: 'Late',
  no_show: 'Not marked',
  sick: 'Sick leave',
  casual: 'Casual leave',
  unpaid: 'Unpaid leave',

  // Examinations
  ongoing: 'In progress',
  awaiting_results: 'Waiting for marks',
  mid_term: 'Mid-term',
  half_yearly: 'Half-yearly',
  unit_test: 'Unit test',
  one_time: 'One-time',

  // Academic levels
  undergrad: 'Undergraduate',
  matric: 'Matric',
  intermediate: 'Intermediate',

  // Fees
  partial: 'Partly paid',
  overdue: 'Overdue',
  waived: 'Fee waived',
  cleared: 'Fully cleared',
  issued: 'Issued',

  // Accounts and payroll
  posted: 'Posted to ledger',
  reversed: 'Reversed',
  contra: 'Contra',
  journal: 'Journal entry',
  receipt: 'Receipt',
  payment: 'Payment',
  calculated: 'Calculated',

  // Communication
  all: 'Everyone',
  faculty: 'Teaching staff',
  staff: 'Staff only',
  students: 'Students only',
  queued: 'In queue',
  skipped: 'Not attempted',

  // Audit actions
  login: 'Signed in',
  logout: 'Signed out',
  exported: 'Report exported',
  created: 'Created',
  updated: 'Updated',
  deleted: 'Deleted',
  system: 'System',

  // Payment channels and ledger sources
  jazzcash: 'JazzCash',
  easypaisa: 'EasyPaisa',
  seed: 'Sample data',

  // Campus life — library, transport, hostel, front desk, certificates, calendar, discipline
  librarian: 'Librarian',
  hostel_warden: 'Hostel Warden',
  receptionist: 'Receptionist',
  borrowed: 'On loan',
  returned: 'Returned',
  lost: 'Lost or damaged',
  van: 'Van',
  coaster: 'Coaster',
  bus: 'Bus',
  pickup: 'Pickup',
  drop: 'Drop-off',
  both: 'Pickup and drop-off',
  discontinued: 'Discontinued',
  boys: 'Boys',
  girls: 'Girls',
  available: 'Available',
  maintenance: 'Under maintenance',
  vacated: 'Vacated',
  bonafide: 'Bonafide',
  transfer: 'Transfer',
  character: 'Character',
  marksheet: 'Marksheet',
  internship: 'Internship',
  closure: 'Closure',
  exam: 'Examination',
  ptm: 'PTM',
  vacation: 'Vacation',
  meeting: 'Meeting',
  minor: 'Minor',
  moderate: 'Moderate',
  major: 'Major',
  counselled: 'Counselled',
  verbal_warning: 'Verbal warning',
  parent_called: 'Parent called in',
  detention: 'Detention',
  suspension: 'Suspension',
  no_action: 'No action taken',
  none: 'No action taken',
  open: 'Still open',
  closed: 'Closed',
}

const KEEP_CAPS = /^(cnic|pkr|eobi|sms|hr|email|id|tax|url|pin|it|pr|stem|ai|pts|mcq)$/

/**
 * Permission ids are stable and few enough to phrase by hand, so the access matrix can read as
 * things an admin can do rather than as code. New ids fall through to the rule below.
 */
const PERMISSIONS: Record<string, string> = {
  '*': 'Everything — full system access',
  'dashboard.view': 'View dashboards',
  'campuses.view': 'View campuses',
  'campuses.manage': 'Add and edit campuses',
  'users.view': 'View user accounts',
  'users.manage': 'Add and edit user accounts',
  'roles.manage': 'Manage roles and access',
  'settings.view': 'View settings',
  'settings.manage': 'Edit organization settings',
  'settings.manage_campus': 'Edit campus settings',
  'audit.view': 'View the audit trail',
  'admissions.view': 'View admissions',
  'admissions.manage': 'Manage admissions',
  'students.view': 'View students',
  'students.manage': 'Add and edit students',
  'academics.view': 'View academics setup',
  'academics.manage': 'Manage classes, sections and subjects',
  'attendance.view': 'View attendance',
  'attendance.mark': 'Mark attendance',
  'attendance.manage': 'Correct attendance records',
  'exams.view': 'View examinations',
  'exams.schedule': 'Schedule examinations',
  'exams.marks_entry': 'Enter marks',
  'exams.results_publish': 'Publish results',
  'fees.view': 'View fees',
  'fees.manage': 'Add and edit fee structures',
  'fees.payments_record': 'Record fee payments',
  'fees.waivers_approve': 'Approve fee waivers',
  'accounts.view': 'View accounts',
  'accounts.manage': 'Post and reverse vouchers',
  'payroll.view': 'View payroll',
  'payroll.process': 'Run payroll and issue payslips',
  'hr.view': 'View HR records',
  'hr.manage': 'Manage staff records',
  'hr.leave_approve': 'Approve leave',
  'staff.view': 'View staff',
  'staff.manage': 'Add and edit staff',
  'communication.view': 'View announcements and messages',
  'communication.manage': 'Send announcements and messages',
  'reports.view': 'View reports',
  'reports.export': 'Export reports',
  'library.view': 'View the library',
  'library.manage': 'Add books, issue and return copies',
  'transport.view': 'View transport',
  'transport.manage': 'Manage vehicles, routes and student seats',
  'hostel.view': 'View the hostel',
  'hostel.manage': 'Manage blocks, rooms and bed allotments',
  'frontdesk.view': 'See who is on site',
  'frontdesk.manage': 'Log visitors and issue gate passes',
  'documents.view': 'View certificate requests',
  'documents.manage': 'Approve and issue certificates',
  'calendar.view': 'View the school calendar',
  'calendar.manage': 'Add holidays and calendar events',
  'discipline.view': 'View discipline cases',
  'discipline.manage': 'Record, act on and close discipline cases',
}

const PERMISSION_GROUPS: Record<string, string> = {
  hr: 'HR', staff: 'Staff', students: 'Students', system: 'Full access', audit: 'Audit trail',
  documents: 'Certificates',
}

/** Module names used as headings in the permission picker, where "Staff only" would read oddly. */
export function permissionGroup(group: string): string {
  return PERMISSION_GROUPS[group] ?? label(group)
}

const VERBS: Record<string, string> = {
  view: 'View', manage: 'Manage', mark: 'Mark', export: 'Export', process: 'Process',
  schedule: 'Schedule', approve: 'Approve', create: 'Add', update: 'Edit', delete: 'Remove',
}

/** A permission id phrased as an action, e.g. `fees.payments_record` becomes "Record fee payments". */
export function permission(id: string): string {
  if (PERMISSIONS[id]) return PERMISSIONS[id]
  const parts = id.split(/[._]/).filter(Boolean)
  const verb = parts.map((p) => VERBS[p]).find(Boolean)
  if (!verb) return label(id)
  const rest = parts.filter((p) => !VERBS[p]).map((p) => label(p)).join(' ')
  return rest ? `${verb} ${rest.toLowerCase()}` : verb
}

export function label(value?: string | null): string {
  if (value === null || value === undefined || String(value).trim() === '') return '—'
  const raw = String(value).trim()
  const key = raw.replace(/([a-z])([A-Z])/g, '$1_$2').replace(/[-\s]+/g, '_').toLowerCase()
  if (WORDS[key]) return WORDS[key]
  if (KEEP_CAPS.test(key)) return key.toUpperCase()
  // Anything stored as authored text — a person's name, a board, "Parent / Guardian Portal" — already
  // carries the casing its owner chose. Only code-like tokens such as `head_office` get re-cased.
  if (!raw.includes('_') && (!/[a-z]/.test(raw) || (/[A-Z]/.test(raw) && /[a-z]/.test(raw)))) return raw
  return raw.split(/[_\s]+/).filter(Boolean)
    .map((w) => (KEEP_CAPS.test(w.toLowerCase()) ? w.toUpperCase() : w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()))
    .join(' ')
}

const TONE: Record<string, Tone> = {
  active: 'green', paid: 'green', approved: 'green', present: 'green', enrolled: 'green', accepted: 'green',
  published: 'green', completed: 'green', cleared: 'green', graduated: 'green', sent: 'green', created: 'green',
  income: 'green', receipt: 'green', read: 'green',
  pending: 'amber', screening: 'amber', interview: 'amber', offered: 'amber', probation: 'amber', partial: 'amber',
  waitlisted: 'amber', ongoing: 'amber', late: 'amber', draft: 'amber', calculated: 'amber', transferred: 'amber',
  queued: 'amber', skipped: 'amber', holiday: 'amber', sick: 'amber', casual: 'amber', leave: 'amber',
  unpaid: 'amber', due: 'amber', high: 'amber', medium: 'amber', journal: 'slate',
  overdue: 'rose', absent: 'rose', suspended: 'rose', terminated: 'rose', rejected: 'rose', failed: 'rose',
  withdrawn: 'rose', payment: 'rose', expense: 'rose', deleted: 'rose', urgent: 'rose', no_show: 'rose',
  scheduled: 'blue', test: 'blue', issued: 'blue', waived: 'blue', asset: 'blue', updated: 'blue', login: 'blue',
  new: 'blue', email: 'blue', sms: 'blue', mid_term: 'blue', monthly: 'blue', quarterly: 'blue', annual: 'blue',
  half_yearly: 'blue', unit_test: 'blue', one_time: 'blue', contra: 'blue',
  cancelled: 'slate', reversed: 'slate', archived: 'slate', resigned: 'slate', inactive: 'slate',
  logout: 'slate', equity: 'slate', liability: 'amber', system: 'slate', exported: 'slate',
  // Campus life
  borrowed: 'blue', returned: 'green', lost: 'rose', available: 'green', maintenance: 'amber',
  vacated: 'slate', discontinued: 'slate', open: 'amber', closed: 'slate', vacation: 'amber',
  closure: 'rose', exam: 'blue', meeting: 'blue', ptm: 'blue', event: 'blue',
  minor: 'slate', moderate: 'amber', major: 'rose', counselled: 'blue', verbal_warning: 'amber',
  parent_called: 'amber', detention: 'amber', suspension: 'rose', none: 'slate',
}

export function tone(value?: string | null, fallback: Tone = 'slate'): Tone {
  if (value === null || value === undefined) return fallback
  return TONE[String(value).toLowerCase()] ?? fallback
}
