import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { SearchInput } from '../components/ui'
import { groupsWithAccess, phonePathFor, type NavItem } from '../lib/nav'
import { scopeTypes } from '../lib/schoolTypes'
import { useAuth } from '../store/auth'
import { Screen } from './MobileShell'

/** Every module the staff account may open, in the same order as the website sidebar. */
export default function MobileMore() {
  const { user, campusId } = useAuth()
  const nav = useNavigate()
  const [q, setQ] = useState('')
  const groups = useMemo(() => groupsWithAccess(user, scopeTypes(user, campusId)), [user, campusId])
  const all = useMemo(() => groups.flatMap((g) => g.items.map((i) => ({ ...i, group: g.title }))), [groups])
  const shown = useMemo(() => {
    const term = q.trim().toLowerCase()
    if (!term) return groups
    return groups
      .map((g) => ({ ...g, items: g.items.filter((i) => `${i.label} ${g.title}`.toLowerCase().includes(term)) }))
      .filter((g) => g.items.length)
  }, [groups, q])
  const open = (item: NavItem) => nav(phonePathFor(item))

  return (
    <Screen heading="All modules" sub={`${all.length} areas available to ${user?.name?.split(' ')[0] ?? 'you'}`}>
      <SearchInput value={q} onChange={setQ} placeholder="Search fee, hostel, payroll…" />
      {!shown.length && (
        <p className="rounded-2xl border border-line bg-white/72 px-4 py-6 text-center text-[13px] text-ink-soft">
          Nothing matches “{q}”.
        </p>
      )}
      {shown.map((g) => (
        <section key={g.title}>
          <p className="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.14em] text-ink-soft">{g.title}</p>
          <div className="grid grid-cols-2 gap-2.5">
            {g.items.map((i) => (
              <button key={i.to} type="button" onClick={() => open(i)}
                className="flex min-w-0 items-center gap-2.5 rounded-2xl border border-white/60 bg-white/72 p-3 text-left shadow-[0_1px_2px_rgba(16,24,40,0.05)] ring-1 ring-black/[0.03] backdrop-blur-xl transition duration-150 active:scale-[0.98] active:bg-brand-50 hover:border-brand-200">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700">
                  <i.icon className="h-[18px] w-[18px]" />
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-[13px] font-semibold text-slate-800">{i.label}</span>
                  <span className="block truncate text-[10.5px] text-ink-soft">{g.title}</span>
                </span>
              </button>
            ))}
          </div>
        </section>
      ))}
      <p className="pt-1 text-center text-[11px] text-slate-400">Same records and permissions as the website.</p>
    </Screen>
  )
}
