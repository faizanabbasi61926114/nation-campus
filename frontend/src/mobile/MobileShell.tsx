import { useEffect, type ComponentType, type ReactNode, type SVGProps } from 'react'
import { NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useApi } from '../lib/hooks'
import { hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { useAuth } from '../store/auth'
import { Avatar, ErrorNote } from '../components/ui'
import { Toaster } from '../components/Toast'
import { IconOnly, RefreshButton, tabIsActive } from './shellParts'
import { mobileAudience } from './roles'
import {
  IconAnnouncements, IconAttendance, IconChevronLeft, IconDashboard, IconExams,
  IconMessages, IconSettings, IconStudents,
} from '../components/icons'

type IconType = ComponentType<SVGProps<SVGSVGElement>>
interface Tab { to: string; label: string; icon: IconType; perm?: string; badge?: 'messages' | 'announcements' }

const PARENT_TABS: Tab[] = [
  { to: '/app', label: 'Children', icon: IconStudents },
  { to: '/app/notices', label: 'Notices', icon: IconAnnouncements, perm: 'communication.view', badge: 'announcements' },
  { to: '/app/messages', label: 'Messages', icon: IconMessages, perm: 'communication.view', badge: 'messages' },
  { to: '/app/me', label: 'Account', icon: IconSettings },
]

const TEACHER_TABS: Tab[] = [
  { to: '/app', label: 'Today', icon: IconDashboard },
  { to: '/app/attendance', label: 'Roll call', icon: IconAttendance, perm: 'attendance.mark' },
  { to: '/app/marks', label: 'Marks', icon: IconExams, perm: 'exams.marks_entry' },
  { to: '/app/messages', label: 'Inbox', icon: IconMessages, perm: 'communication.view', badge: 'messages' },
  { to: '/app/me', label: 'Account', icon: IconSettings },
]

const TITLES: Record<string, string> = {
  '/app': 'Today',
  '/app/attendance': 'Roll call',
  '/app/marks': 'Marks entry',
  '/app/notices': 'Notices',
  '/app/messages': 'Messages',
  '/app/me': 'Account',
}

export default function MobileShell() {
  const { user, loading, fetchMe } = useAuth()
  const audience = mobileAudience(user)
  const loc = useLocation()
  const nav = useNavigate()
  const { data: counts } = useApi<{ data: { messages: number; announcements: number } }>(
    audience && hasPerm(user, 'communication.view') ? '/messages/unread-count' : null, [loc.pathname])

  useEffect(() => { void fetchMe() }, []) // eslint-disable-line

  if (loading) {
    return (
      <div className="grid min-h-screen place-items-center gap-3 text-slate-400">
        <span className="grid h-11 w-11 animate-pulse place-items-center rounded-xl bg-brand-900 text-lg font-bold text-gold-300">N</span>
        <p className="text-sm font-medium">Opening the app…</p>
      </div>
    )
  }
  if (!user) return <Navigate to="/login" replace />
  if (!audience) return <Navigate to="/" replace />

  const tabs = (audience === 'parent' ? PARENT_TABS : TEACHER_TABS)
    .filter((t) => !t.perm || hasPerm(user, t.perm))
  const childRoute = /^\/app\/child\/\d+/.test(loc.pathname)
  const title = childRoute ? 'Your child' : TITLES[loc.pathname] ?? 'Nation Campus'
  const unread = (path: string, key: 'messages' | 'announcements') =>
    loc.pathname === path ? 0 : Number(counts?.data?.[key] ?? 0)

  return (
    <div className="min-h-screen bg-canvas">
      <Toaster />
      <div className="mx-auto flex min-h-screen w-full max-w-[38rem] flex-col">
        <header className="sticky top-0 z-30 border-b border-line/80 bg-white/72 pt-[env(safe-area-inset-top)] backdrop-blur-xl">
          <div className="flex items-center gap-2.5 px-4 py-3">
            {childRoute || !TITLES[loc.pathname] ? (
              <IconOnly label="Go back" onClick={() => nav(-1)}><IconChevronLeft className="h-5 w-5" /></IconOnly>
            ) : (
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-900 text-base font-bold text-gold-300">N</span>
            )}
            <div className="min-w-0 flex-1">
              <p className="truncate text-[15px] font-semibold leading-tight text-slate-900">{title}</p>
              <p className="truncate text-[11px] text-ink-soft">{audience === 'parent' ? 'Family portal' : user.roles.map(label).join(', ') || 'Staff'}</p>
            </div>
            <RefreshButton />
            <Avatar name={user.name} className="h-9 w-9" />
          </div>
        </header>

        <main className="flex-1 px-4 pb-32 pt-4">
          <Outlet />
        </main>

        <nav aria-label="Sections" className="fixed inset-x-0 bottom-0 z-40 mx-auto w-full max-w-[38rem] px-3 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
          <div className="flex items-stretch gap-1 rounded-2xl border border-white/60 bg-white/72 p-1 shadow-pop ring-1 ring-black/[0.03] backdrop-blur-2xl">
            {tabs.map((t) => {
              const n = t.badge ? unread(t.to, t.badge) : 0
              return (
                <NavLink key={t.to} to={t.to} end={t.to === '/app'}
                  className={({ isActive }) => `relative flex flex-1 flex-col items-center gap-1 rounded-xl px-1 py-2 text-[10.5px] font-semibold transition duration-150 ${
                    tabIsActive(isActive, loc.pathname, t.to) ? 'bg-brand-700 text-white shadow-sm' : 'text-ink-soft hover:bg-white/70'
                  }`}>
                  <t.icon className="h-[19px] w-[19px]" />
                  <span className="truncate leading-none">{t.label}</span>
                  {n > 0 && (
                    <span className="absolute right-2 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white ring-2 ring-white/80">{n > 9 ? '9+' : n}</span>
                  )}
                </NavLink>
              )
            })}
          </div>
        </nav>
      </div>
    </div>
  )
}

/** Shared page body for the phone screens: a heading line plus the content. */
export function Screen({ heading, sub, actions, error, onRetry, children }: {
  heading?: string; sub?: string; actions?: ReactNode; error?: string
  onRetry?: () => void; children: ReactNode
}) {
  return (
    <div className="animate-fade-up space-y-4">
      {(heading || actions) && (
        <div className="flex flex-wrap items-end justify-between gap-2">
          <div className="min-w-0">
            {heading && <h2 className="truncate text-[17px] font-semibold text-slate-900">{heading}</h2>}
            {sub && <p className="mt-0.5 text-xs text-ink-soft">{sub}</p>}
          </div>
          {actions}
        </div>
      )}
      {error && <ErrorNote text={error} onRetry={onRetry} />}
      {children}
    </div>
  )
}
