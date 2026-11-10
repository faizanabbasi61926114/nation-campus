import { useAuth } from '../store/auth'
import { schoolTypeName } from '../lib/schoolTypes'
import type { CampusBrief } from '../lib/types'
import { IconCampuses, IconChevronDown } from './icons'

/**
 * Head office picks the campus every figure on screen is scoped to; a campus user is shown the
 * one school they belong to. Used by the website header and the phone header.
 */
export default function CampusSwitcher() {
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
