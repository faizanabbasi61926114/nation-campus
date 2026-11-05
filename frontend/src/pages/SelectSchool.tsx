import { Navigate, useNavigate } from 'react-router-dom'
import { needsSchoolChoice, useAuth } from '../store/auth'
import { schoolTypeName } from '../lib/schoolTypes'
import { Btn, Loading } from '../components/ui'
import { IconArrowRight, IconCampuses, IconLogout } from '../components/icons'

/**
 * Multi-campus staff sign in to the group, not to one building. Choosing the school here sets the
 * data scope for every page and keeps the sidebar to the modules that school actually runs.
 */
export default function SelectSchool() {
  const { user, loading, switchCampus, logout } = useAuth()
  const nav = useNavigate()
  if (loading) return <Loading className="min-h-screen" />
  if (!user) return <Navigate to="/login" replace />
  if (!needsSchoolChoice(user)) return <Navigate to="/" replace />

  const choose = async (id: number | null) => {
    await switchCampus(id)
    nav('/', { replace: true })
  }

  return (
    <main className="min-h-screen bg-canvas px-4 py-10 sm:px-8 sm:py-16">
      <div className="mx-auto w-full max-w-4xl animate-fade-up">
        <header className="flex items-center justify-between gap-3">
          <span className="flex items-center gap-3">
            <span className="grid h-10 w-10 place-items-center rounded-lg bg-brand-900 text-lg font-bold text-gold-300">N</span>
            <span className="text-[15px] font-semibold tracking-tight text-brand-900">Nation Campus
              <span className="mt-0.5 block text-[11px] font-medium text-ink-soft">{user.name}</span>
            </span>
          </span>
          <Btn variant="ghost" icon={<IconLogout className="h-4 w-4" />} onClick={() => void logout()}>Sign out</Btn>
        </header>

        <div className="mt-12">
          <p className="text-sm font-semibold text-brand-600">Welcome back</p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Which school are you working in?</h1>
          <p className="mt-3 max-w-xl text-sm leading-6 text-ink-soft">
            Pick a school to see its students, staff and records. You can change it any time from the top bar.
          </p>
        </div>

        <div className="mt-8 grid gap-3 sm:grid-cols-2">
          {user.campuses.map((c) => (
            <button key={c.id} onClick={() => void choose(c.id)} type="button"
              className="group flex items-center gap-4 rounded-2xl border border-line bg-white p-4 text-left shadow-card transition duration-150 hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-canvas text-brand-600 ring-1 ring-inset ring-line">
                <IconCampuses className="h-5 w-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[15px] font-semibold text-slate-900">{c.name}</span>
                <span className="mt-0.5 block truncate text-xs text-ink-soft">
                  <span className="nums">{c.code}</span>{c.city ? ` · ${c.city}` : ''}
                </span>
                <span className="mt-2 inline-flex rounded-md bg-canvas px-2 py-0.5 text-[11px] font-semibold text-ink-soft">{schoolTypeName(c.school_type)}</span>
              </span>
              <IconArrowRight className="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600" />
            </button>
          ))}
        </div>

        {user.is_head_office && (
          <button onClick={() => void choose(null)} type="button"
            className="mt-3 flex w-full items-center gap-4 rounded-2xl border border-dashed border-line-strong bg-white/60 p-4 text-left transition hover:border-gold-300 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gold-300/25 text-brand-700 ring-1 ring-inset ring-gold-300/50">
              <IconCampuses className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[15px] font-semibold text-slate-900">All campuses — Head Office view</span>
              <span className="mt-0.5 block text-xs text-ink-soft">Group-wide dashboards, campuses, users, roles and settings</span>
            </span>
            <IconArrowRight className="h-4 w-4 shrink-0 text-slate-300" />
          </button>
        )}

        {!user.is_head_office && (
          <p className="mt-6 text-xs text-ink-soft">Your account is limited to the schools listed above.</p>
        )}
      </div>
    </main>
  )
}
