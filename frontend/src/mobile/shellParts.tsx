import { type ComponentType, type ReactNode, type SVGProps } from 'react'
import { useLocation } from 'react-router-dom'
import { Skeleton } from '../components/ui'
import { IconRefresh } from '../components/icons'
import { REFRESH_EVENT } from './refresh'

export function RefreshButton() {
  const loc = useLocation()
  return (
    <button type="button" aria-label="Refresh" title="Refresh"
      onClick={() => window.dispatchEvent(new CustomEvent(REFRESH_EVENT, { detail: loc.pathname }))}
      className="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-line-strong bg-white/80 text-slate-500 transition active:scale-95 hover:border-brand-300 hover:text-brand-700">
      <IconRefresh className="h-4 w-4" />
    </button>
  )
}

export function IconOnly({ label, onClick, children }: { label: string; onClick?: () => void; children: ReactNode }) {
  return (
    <button type="button" aria-label={label} title={label} onClick={onClick}
      className="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-line-strong bg-white/80 text-slate-600 transition active:scale-95 hover:border-brand-300 hover:text-brand-700">
      {children}
    </button>
  )
}

export function Metric({ label, value, sub, tone = 'slate' }: {
  label: string; value: ReactNode; sub?: string
  tone?: 'slate' | 'green' | 'amber' | 'rose' | 'blue'
}) {
  const value_tone = { slate: 'text-slate-900', green: 'text-brand-700', amber: 'text-amber-700', rose: 'text-rose-600', blue: 'text-sky-700' }[tone]
  return (
    <div className="min-w-0 rounded-xl bg-canvas/80 px-3 py-2.5 ring-1 ring-inset ring-line">
      <p className="truncate text-[10.5px] font-semibold uppercase tracking-[0.08em] text-ink-soft">{label}</p>
      <p className={`nums mt-1 truncate text-[17px] font-semibold leading-tight ${value_tone}`}>{value}</p>
      {sub && <p className="mt-0.5 truncate text-[11px] text-ink-soft">{sub}</p>}
    </div>
  )
}

/** Thin progress bar — attendance rate, fee paid share, roll-call progress. */
export function Bar({ percent, tone = 'brand' }: { percent: number; tone?: 'brand' | 'amber' | 'rose' | 'gold' }) {
  const bg = { brand: 'bg-brand-600', amber: 'bg-amber-500', rose: 'bg-rose-500', gold: 'bg-gold-400' }[tone]
  return (
    <div className="h-1.5 w-full overflow-hidden rounded-full bg-line">
      <div className={`h-full rounded-full ${bg} transition-[width] duration-500`} style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} />
    </div>
  )
}

export function Row({ label, value, icon: Icon }: { label: ReactNode; value?: ReactNode; icon?: ComponentType<SVGProps<SVGSVGElement>> }) {
  return (
    <div className="flex items-baseline justify-between gap-3 py-1.5 text-sm">
      <span className="flex min-w-0 items-center gap-2 text-ink-soft">
        {Icon && <Icon className="h-4 w-4 shrink-0 text-brand-500" />}
        <span className="truncate">{label}</span>
      </span>
      {value !== undefined && <span className="nums shrink-0 font-semibold text-slate-800">{value}</span>}
    </div>
  )
}

/** Horizontal picker used inside screens (month, child, exam). */
export function Segmented({ options, value, onChange }: {
  options: Array<{ key: string; label: string; note?: string }>
  value: string; onChange: (key: string) => void
}) {
  return (
    <div className="scroll-slim -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" role="tablist">
      {options.map((o) => {
        const on = o.key === value
        return (
          <button key={o.key} type="button" role="tab" aria-selected={on} onClick={() => onChange(o.key)}
            className={`shrink-0 rounded-xl px-3 py-2 text-[12.5px] font-semibold transition duration-150 ${
              on ? 'bg-brand-700 text-white shadow-sm' : 'border border-line-strong bg-white/80 text-ink-soft hover:border-brand-300 hover:text-brand-700'
            }`}>
            {o.label}
            {o.note && <span className={`ml-1.5 text-[10px] font-medium ${on ? 'text-white/70' : 'text-slate-400'}`}>{o.note}</span>}
          </button>
        )
      })}
    </div>
  )
}

/**
 * The action bar a screen docks above the tab bar. It has to be fixed, not sticky:
 * the tab bar floats over the page bottom, and a sticky bar would sit underneath it,
 * unreachable on a phone.
 */
export function DockBar({ direction = 'row', children }: { direction?: 'row' | 'column'; children: ReactNode }) {
  return (
    <div className="fixed inset-x-0 bottom-[calc(4.6rem+max(0.5rem,env(safe-area-inset-bottom)))] z-50 mx-auto w-full max-w-[38rem] px-3">
      <div className={`animate-fade-up flex gap-3 rounded-2xl border border-white/60 bg-white/85 p-3 shadow-pop ring-1 ring-black/[0.03] backdrop-blur-2xl ${
        direction === 'row' ? 'items-center' : 'flex-col'}`}>
        {children}
      </div>
    </div>
  )
}

export function ListSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <div className="space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="rounded-2xl border border-line bg-white p-4">
          <Skeleton className="h-4 w-2/5" />
          <Skeleton className="mt-3 h-3 w-4/5" />
          <Skeleton className="mt-2 h-3 w-3/5" />
        </div>
      ))}
    </div>
  )
}
