import { useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'
import { needsSchoolChoice, useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { scopeTypes } from '../lib/schoolTypes'
import { FLAT, groupsWithAccess } from '../lib/nav'
import { isHousehold } from '../mobile/roles'
import { Avatar } from './ui'
import CampusSwitcher from './CampusSwitcher'
import { Toaster } from './Toast'
import { ConfirmHost } from './Confirm'
import { IconDashboard, IconLogout } from './icons'

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { user, campusId } = useAuth()
  const groups = groupsWithAccess(user, scopeTypes(user, campusId))
  return (
    <nav aria-label="Main navigation" className="scroll-slim flex min-h-0 flex-1 flex-col gap-7 overflow-y-auto px-3 py-5">
      {groups.map((g) => (
        <div key={g.title}>
          <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">{g.title}</p>
          {g.items.map((i) => (
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
      ))}
    </nav>
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
            <Link to="/app" onClick={() => setMobile(false)}
              className="mx-3 mb-2 flex items-center gap-3 rounded-xl bg-white/[0.06] px-3 py-2.5 text-[13px] font-semibold text-white ring-1 ring-inset ring-white/8 transition hover:bg-white/10">
              <IconDashboard className="h-[18px] w-[18px] text-gold-300" />Phone app
            </Link>
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
              <Link to="/app" className="hidden items-center gap-1.5 rounded-xl border border-line-strong bg-white px-3 py-2 text-[13px] font-medium text-slate-700 shadow-card transition hover:border-brand-300 hover:bg-brand-50/60 hover:text-brand-800 sm:inline-flex">
                <IconDashboard className="h-4 w-4 text-brand-500" />Phone app
              </Link>
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
