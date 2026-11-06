import { useEffect, useState, type ComponentType, type ReactNode, type SVGProps } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { needsSchoolChoice, useAuth } from '../store/auth'
import { hasPerm, type CampusBrief } from '../lib/types'
import { label } from '../lib/labels'
import { moduleAvailable, schoolTypeName, scopeTypes } from '../lib/schoolTypes'
import { isHousehold } from '../mobile/roles'
import { Avatar } from './ui'
import { Toaster } from './Toast'
import { ConfirmHost } from './Confirm'
import {
  IconAcademics, IconAccounts, IconAdmissions, IconAnnouncements, IconAttendance, IconAudit, IconCampuses,
  IconCertificate, IconChevronDown, IconDashboard, IconDiscipline, IconExams, IconFees, IconFrontDesk, IconHostel,
  IconLeave, IconLibrary, IconLogout, IconMessages, IconPayroll, IconReport, IconRoles, IconSettings, IconStaff,
  IconStudents, IconTimetable, IconTransport, IconUsers,
} from './icons'

type IconType = ComponentType<SVGProps<SVGSVGElement>>
interface NavItem { to: string; label: string; perm: string; icon: IconType; mod?: string }
interface NavGroup { title: string; items: NavItem[] }

/**
 * `mod` ties an entry to an institution type: a Montessori campus has no boarding house, so the
 * hostel should not be a dead link there. See lib/schoolTypes.
 */
const NAV: NavGroup[] = [
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

const FLAT = NAV.flatMap((g) => g.items.map((i) => ({ ...i, group: g.title })))

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { user, campusId } = useAuth()
  const types = scopeTypes(user, campusId)
  return (
    <nav aria-label="Main navigation" className="scroll-slim flex min-h-0 flex-1 flex-col gap-7 overflow-y-auto px-3 py-5">
      {NAV.map((g) => {
        const items = g.items.filter((i) => hasPerm(user, i.perm) && moduleAvailable(i.mod ?? '', types))
        if (!items.length) return null
        return (
          <div key={g.title}>
            <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">{g.title}</p>
            {items.map((i) => (
              <NavLink key={i.to} to={i.to} end={i.to === '/'} onClick={onNavigate}
                className={({ isActive }) => `group relative mb-0.5 flex items-center gap-3 rounded-xl px-3 py-2 text-[13px] font-medium transition duration-150 ${
                  isActive
                    ? 'bg-white/[0.13] text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.07)]'
                    : 'text-white/60 hover:bg-white/[0.06] hover:text-white'
                }`}>
                {({ isActive }) => (
                  <>
                    <span className={`absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-gold-300 transition-opacity ${isActive ? 'opacity-100' : 'opacity-0'}`} />
                    <i.icon className={`h-[18px] w-[18px] shrink-0 transition ${isActive ? 'text-gold-300' : 'text-white/40 group-hover:text-white/70'}`} />
                    <span className="truncate">{i.label}</span>
                  </>
                )}
              </NavLink>
            ))}
          </div>
        )
      })}
    </nav>
  )
}

export function CampusSwitcher() {
  const { user, campusId, switchCampus } = useAuth()
  const campus = campusId === null ? '' : String(campusId)
  if (!user?.is_head_office) {
    const c = user?.campuses?.[0]
    return c ? (
      <span className="inline-flex items-center gap-2 rounded-xl border border-line-strong bg-white px-3 py-2 text-[13px] font-medium text-slate-700 shadow-card">
        <IconCampuses className="h-4 w-4 text-brand-500" />{c.name}
        {c.school_type && <span className="rounded-md bg-canvas px-1.5 py-0.5 text-[11px] font-semibold text-ink-soft">{schoolTypeName(c.school_type)}</span>}
      </span>
    ) : null
  }
  const selected = user.campuses.find((c) => c.id === campusId)
  return (
    <span className="flex items-center gap-2">
      <label className="relative inline-flex max-w-[min(68vw,21rem)] items-center">
        <IconCampuses className="pointer-events-none absolute left-3 h-4 w-4 text-brand-500" />
        <span className="sr-only">Select campus</span>
        <select aria-label="Select campus" value={campus} onChange={(e) => { void switchCampus(e.target.value ? Number(e.target.value) : null) }}
          className="w-full cursor-pointer appearance-none truncate rounded-xl border border-line-strong bg-white py-2 pl-9 pr-8 text-[13px] font-medium text-slate-700 shadow-card outline-none transition hover:border-brand-300 focus:border-brand-500">
          <option value="">All campuses (Head Office)</option>
          {user.campuses.map((c: CampusBrief) => <option key={c.id} value={c.id}>{c.name} ({c.code}) — {schoolTypeName(c.school_type)}</option>)}
        </select>
        <IconChevronDown className="pointer-events-none absolute right-2.5 h-3.5 w-3.5 text-slate-400" />
      </label>
      {selected && <span className="hidden rounded-xl border border-line-strong bg-canvas px-2.5 py-2 text-[11px] font-semibold text-ink-soft lg:block">{schoolTypeName(selected.school_type)}</span>}
    </span>
  )
}

export default function Layout() {
  const { user, loading, fetchMe } = useAuth()
  const [mobile, setMobile] = useState(false)
  const loc = useLocation()
  const current = FLAT.find((item) => item.to === loc.pathname)
  useEffect(() => { void fetchMe() }, []) // eslint-disable-line

  if (loading) return (
    <div className="grid min-h-screen place-items-center gap-3 text-slate-400">
      <span className="grid h-11 w-11 animate-pulse place-items-center rounded-xl bg-brand-900 text-lg font-bold text-gold-300">N</span>
      <p className="text-sm font-medium">Preparing your workspace…</p>
    </div>
  )
  if (!user) return <Navigate to="/login" replace />
  if (needsSchoolChoice(user)) return <Navigate to="/select-school" replace />
  // A family account is served by the phone app, not by a campus workspace.
  if (isHousehold(user)) return <Navigate to="/app" replace />

  const role = user.roles.map((r) => label(r)).join(', ')

  return (
    <div className="min-h-screen md:flex">
      <Toaster />
      <ConfirmHost />
      <aside className="hidden w-[17rem] shrink-0 bg-gradient-to-b from-brand-900 via-brand-900 to-brand-950 text-white md:block">
        <div className="sticky top-0 flex h-screen flex-col">
          <Link to="/" className="flex items-center gap-3 border-b border-white/8 px-5 py-5 transition hover:bg-white/[0.04]">
            <span className="grid h-9 w-9 place-items-center rounded-lg bg-gold-300 text-lg font-bold text-brand-900 shadow-[0_0_0_4px_rgba(241,198,109,0.16)]">N</span>
            <span className="text-[15px] font-semibold tracking-tight">Nation Campus
              <span className="mt-0.5 block text-[10px] font-medium uppercase tracking-[0.12em] text-white/45">School management</span>
            </span>
          </Link>
          <Sidebar />
          <div className="border-t border-white/8 p-3">
            <div className="flex items-center gap-3 rounded-xl bg-white/[0.06] p-3 ring-1 ring-inset ring-white/8">
              <Avatar name={user.name} className="h-9 w-9 !bg-gold-300 !text-brand-900 !ring-gold-200/40" />
              <div className="min-w-0">
                <p className="truncate text-[13px] font-semibold text-white">{user.name}</p>
                <p className="truncate text-[11px] text-white/50">{role}</p>
              </div>
            </div>
          </div>
        </div>
      </aside>
      {mobile && (
        <div className="fixed inset-0 z-50 bg-brand-950/50 backdrop-blur-sm md:hidden" onClick={() => setMobile(false)}>
          <aside className="flex h-full w-[min(19rem,88vw)] animate-slide-in flex-col bg-gradient-to-b from-brand-900 to-brand-950 shadow-pop" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-white/8 px-4 py-4">
              <span className="flex items-center gap-2.5 text-[15px] font-semibold text-white">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-gold-300 text-brand-900">N</span>Nation Campus
              </span>
              <button aria-label="Close navigation" onClick={() => setMobile(false)} className="grid h-9 w-9 place-items-center rounded-lg text-white/60 transition hover:bg-white/10 hover:text-white">✕</button>
            </div>
            <Sidebar onNavigate={() => setMobile(false)} />
            <div className="border-t border-white/8 p-4 text-sm text-white/70">{user.name}</div>
          </aside>
        </div>
      )}
      <div className="flex min-h-screen min-w-0 flex-1 flex-col">
        <header className="no-print sticky top-0 z-30 border-b border-line bg-white/85 px-4 py-3 backdrop-blur-md md:px-8">
          <div className="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <button aria-label="Open navigation" onClick={() => setMobile(true)} className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-line-strong bg-white text-slate-600 shadow-card transition hover:bg-slate-50 md:hidden">
                <span className="text-lg leading-none">☰</span>
              </button>
              <div className="min-w-0">
                {current && <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-ink-soft/70">{current.group}</p>}
                <p className="truncate text-[15px] font-semibold text-slate-900">{current?.label ?? 'Nation Campus'}</p>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2.5">
              {hasPerm(user, 'academics.view') && (
                <Link to="/app" className="hidden items-center gap-1.5 rounded-xl border border-line-strong bg-white px-3 py-2 text-[13px] font-medium text-slate-700 shadow-card transition hover:border-brand-300 hover:bg-brand-50/60 hover:text-brand-800 sm:inline-flex">
                  <IconDashboard className="h-4 w-4 text-brand-500" />Phone app
                </Link>
              )}
              <CampusSwitcher />
              <span className="hidden h-6 w-px bg-line-strong sm:block" />
              <span className="hidden text-right sm:block">
                <span className="block max-w-40 truncate text-[13px] font-semibold text-slate-800">{user.name}</span>
                <span className="block text-[11px] text-ink-soft">{role}</span>
              </span>
              <Avatar name={user.name} className="h-9 w-9 sm:hidden" />
              <button onClick={() => void useAuth.getState().logout()} title="Sign out"
                className="grid h-9 w-9 place-items-center rounded-xl border border-line-strong bg-white text-slate-500 shadow-card transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600">
                <IconLogout className="h-4 w-4" />
              </button>
            </div>
          </div>
        </header>
        <main className="mx-auto w-full max-w-[1440px] flex-1 animate-fade-up p-5 sm:p-6 md:p-8" key={loc.pathname}>
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export function RequirePerm({ perm, children }: { perm: string; children: ReactNode }) {
  const { user } = useAuth()
  if (!hasPerm(user, perm)) return <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">You do not have access to this page.</div>
  return <>{children}</>
}
