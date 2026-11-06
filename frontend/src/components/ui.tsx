import { useEffect, type HTMLAttributes, type ReactNode } from 'react'
import { label as plainWords, tone as statusTone } from '../lib/labels'
import {
  IconAlert, IconCheck, IconChevronLeft, IconChevronRight, IconClose, IconInfo, IconRefresh, IconSearch,
} from './icons'
import { DOTS, TONES, type Tone } from './tones'

export function Card({ children, className = '', interactive, ...rest }: HTMLAttributes<HTMLDivElement> & { interactive?: boolean }) {
  return (
    <div {...rest} className={`rounded-2xl border border-line bg-white shadow-card ${
      interactive ? 'transition duration-200 hover:-translate-y-px hover:border-brand-200 hover:shadow-lift ' : ''
    }${className}`}>
      {children}
    </div>
  )
}

export function PageHeader({ title, subtitle, actions, icon }: {
  title: string; subtitle?: string; actions?: ReactNode; icon?: ReactNode
}) {
  return (
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 items-center gap-3.5">
        {icon && <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 p-2.5 text-brand-600 ring-1 ring-brand-100">{icon}</span>}
        <div className="min-w-0">
          <h1 className="truncate text-2xl font-semibold text-slate-900 sm:text-[1.7rem]">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-ink-soft">{subtitle}</p>}
        </div>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function Btn({ children, onClick, variant = 'primary', type = 'button', disabled, className = '', size = 'md', icon, loading, form }: {
  children: ReactNode; onClick?: () => void; type?: 'button' | 'submit'
  variant?: 'primary' | 'ghost' | 'danger' | 'soft'; disabled?: boolean; className?: string
  size?: 'sm' | 'md'; icon?: ReactNode; loading?: boolean; form?: string
}) {
  const styles = {
    primary: 'bg-brand-700 text-white shadow-sm hover:bg-brand-800 active:scale-[.99] disabled:bg-slate-300',
    ghost: 'border border-line-strong bg-white text-slate-700 hover:border-brand-300 hover:bg-brand-50/60 hover:text-brand-800 active:scale-[.99]',
    soft: 'bg-brand-50 text-brand-700 ring-1 ring-brand-100 hover:bg-brand-100 active:scale-[.99]',
    danger: 'bg-rose-600 text-white shadow-sm hover:bg-rose-700 active:scale-[.99] disabled:bg-rose-300',
  }[variant]
  const pad = size === 'sm' ? 'gap-1.5 px-2.5 py-1.5 text-xs' : 'gap-2 px-3.5 py-2 text-sm'
  return (
    <button type={type} form={form} onClick={onClick} disabled={disabled || loading}
      className={`inline-flex items-center justify-center rounded-xl font-medium transition duration-150 disabled:cursor-not-allowed disabled:opacity-70 ${pad} ${styles} ${className}`}>
      {loading ? <Spinner /> : icon}
      {children}
    </button>
  )
}

export function Field({ label, children, error, hint }: { label: string; children: ReactNode; error?: string; hint?: string }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-[13px] font-semibold text-slate-700">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-ink-soft">{hint}</span>}
      {error && <span className="mt-1.5 flex items-center gap-1 text-xs font-medium text-rose-600"><IconAlert className="h-3.5 w-3.5" />{error}</span>}
    </label>
  )
}

export const inputCls = 'w-full rounded-xl border border-line-strong bg-white px-3 py-2.5 text-sm text-ink outline-none transition placeholder:text-slate-400 hover:border-brand-300 focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 disabled:bg-slate-50 disabled:text-slate-400'
export const selectCls = `${inputCls} cursor-pointer pr-8`

export function SearchInput({ value, onChange, placeholder = 'Search…', className = '' }: {
  value: string; onChange: (v: string) => void; placeholder?: string; className?: string
}) {
  return (
    <div className={`relative ${className}`}>
      <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
        className={`${inputCls} pl-9`} />
      {value && (
        <button type="button" onClick={() => onChange('')} aria-label="Clear search"
          className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
          <IconClose className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}

/** Row of filters or actions that sits above a table. */
export function Toolbar({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mb-4 flex flex-wrap items-center gap-2.5 ${className}`}>{children}</div>
}

export function Th({ children, align = 'left', className = '' }: { children?: ReactNode; align?: 'left' | 'right' | 'center'; className?: string }) {
  const where = align === 'right' ? 'text-right nums' : align === 'center' ? 'text-center' : 'text-left'
  return <th scope="col" className={`whitespace-nowrap border-b border-line px-4 py-3 text-[12px] font-semibold text-ink-soft ${where} ${className}`}>{children}</th>
}

export function Td({ children, className = '', colSpan }: { children?: ReactNode; className?: string; colSpan?: number }) {
  return <td colSpan={colSpan} className={`px-4 py-2.5 text-sm text-slate-700 ${className}`}>{children}</td>
}

export function Table({ headers, children, cols, loading, error, onRetry }: {
  headers: ReactNode; children: ReactNode; cols?: number; loading?: boolean; error?: string; onRetry?: () => void
}) {
  return (
    <Card className="overflow-hidden">
      <div className="scroll-slim overflow-x-auto">
        <table className="min-w-full divide-y divide-line">
          <thead className="bg-canvas/80">
            <tr>{headers}</tr>
          </thead>
          <tbody className="divide-y divide-line/70 bg-white [&>tr]:transition-colors [&>tr]:hover:bg-brand-50/40">
            {loading && (cols ?? 6) > 0 && Array.from({ length: 6 }).map((_, r) => (
              <tr key={r}>
                {Array.from({ length: cols ?? 6 }).map((__, c) => (
                  <td key={c} className="px-4 py-3.5"><span className="skeleton block h-3.5 rounded-full" /></td>
                ))}
              </tr>
            ))}
            {!loading && (error
              ? <tr><td colSpan={cols ?? 6} className="px-4 py-8"><ErrorNote text={error} onRetry={onRetry} /></td></tr>
              : children)}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

export function Badge({ children, tone = 'slate', dot }: { children: ReactNode; tone?: Tone; dot?: boolean }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${TONES[tone]}`}>
      {dot && <span className={`h-1.5 w-1.5 rounded-full ${DOTS[tone]}`} />}
      {children}
    </span>
  )
}

/** A status, type or action from a stored value: plain wording plus the app-wide colour meaning. */
export function Status({ value, tone: forced, dot }: { value?: string | null; tone?: Tone; dot?: boolean }) {
  return <Badge tone={forced ?? statusTone(value)} dot={dot}>{plainWords(value)}</Badge>
}

export function StatCard({ label, value, hint, icon, tone = 'green' }: {
  label: string; value: ReactNode; hint?: string; icon?: ReactNode; tone?: Tone
}) {
  return (
    <Card interactive className="relative overflow-hidden p-5">
      <span className={`absolute inset-x-0 top-0 h-[3px] ${DOTS[tone]}`} />
      <div className="flex items-start justify-between gap-3">
        <p className="text-[12px] font-semibold text-ink-soft">{label}</p>
        {icon && <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ring-1 ring-inset ${TONES[tone]}`}>{icon}</span>}
      </div>
      <p className="nums mt-2.5 text-[26px] font-semibold leading-tight text-slate-900">{value}</p>
      {hint && <p className="mt-1.5 text-xs text-ink-soft">{hint}</p>}
    </Card>
  )
}

/** Works as a page-level state and inside a wide <td colSpan>. */
export function Empty({ text, icon, hint, className = '' }: { text: string; icon?: ReactNode; hint?: string; className?: string }) {
  return (
    <div className={`flex flex-col items-center justify-center gap-2 px-6 py-10 text-center ${className}`}>
      <span className="grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-slate-400">{icon ?? <IconInfo />}</span>
      <p className="text-sm font-medium text-slate-600">{text}</p>
      {hint && <p className="max-w-sm text-xs text-ink-soft">{hint}</p>}
    </div>
  )
}

export function Spinner({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={`${className} animate-spin`} aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2.6" fill="none" opacity=".22" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2.6" fill="none" strokeLinecap="round" />
    </svg>
  )
}

export function Skeleton({ className = 'h-4 w-full' }: { className?: string }) {
  return <span className={`skeleton block rounded-lg ${className}`} />
}

export function Loading({ label = 'Loading…', className = '' }: { label?: string; className?: string }) {
  return (
    <div className={`flex items-center justify-center gap-2.5 py-10 text-sm font-medium text-ink-soft ${className}`}>
      <Spinner className="h-4 w-4 text-brand-600" />{label}
    </div>
  )
}

export function ErrorNote({ text, onRetry, className = '' }: { text: string; onRetry?: () => void; className?: string }) {
  return (
    <div className={`flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 ${className}`}>
      <IconAlert className="h-4 w-4 shrink-0" />
      <span className="min-w-0 flex-1">{text}</span>
      {onRetry && <Btn size="sm" variant="ghost" icon={<IconRefresh className="h-3.5 w-3.5" />} onClick={onRetry}>Try again</Btn>}
    </div>
  )
}

export function Notice({ children, tone = 'blue', title, className = '' }: { children: ReactNode; tone?: Tone; title?: string; className?: string }) {
  return (
    <div className={`flex items-start gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset ${TONES[tone]} ${className}`}>
      <IconInfo className="mt-0.5 h-4 w-4 shrink-0" />
      <div className="min-w-0">
        {title && <p className="font-semibold">{title}</p>}
        <div className={title ? 'mt-0.5 opacity-90' : ''}>{children}</div>
      </div>
    </div>
  )
}

// A confirm dialog can open on top of an editor dialog, so Escape and the body
// scroll lock must be shared across all mounted Modals rather than owned by one.
const modalStack: number[] = []
let modalSeq = 0

export function Modal({ title, subtitle, children, footer, onClose, size = 'md' }: {
  title: string; subtitle?: string; children: ReactNode; footer?: ReactNode
  onClose: () => void; size?: 'sm' | 'md' | 'lg' | 'xl'
}) {
  useEffect(() => {
    const id = ++modalSeq
    modalStack.push(id)
    document.body.style.overflow = 'hidden'
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape' && modalStack[modalStack.length - 1] === id) onClose() }
    window.addEventListener('keydown', key)
    return () => {
      window.removeEventListener('keydown', key)
      const at = modalStack.indexOf(id)
      if (at !== -1) modalStack.splice(at, 1)
      if (!modalStack.length) document.body.style.overflow = ''
    }
  }, [onClose])

  const w = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl', xl: 'max-w-5xl' }[size]
  return (
    <div className="scroll-slim fixed inset-0 z-50 animate-fade-in overflow-y-auto bg-brand-950/40 p-4 backdrop-blur-[3px]"
      onClick={onClose} role="dialog" aria-modal="true" aria-label={title}>
      <div className={`mx-auto my-[6vh] w-full ${w} animate-pop-in overflow-hidden rounded-2xl bg-white shadow-pop`}
        onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold text-slate-900">{title}</h2>
            {subtitle && <p className="mt-0.5 text-xs text-ink-soft">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} aria-label="Close dialog"
            className="-mr-1 -mt-1 grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <IconClose className="h-4 w-4" />
          </button>
        </div>
        <div className="px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-canvas/70 px-5 py-3.5">{footer}</div>}
      </div>
    </div>
  )
}

export function Tabs({ tabs, active, onChange, className = '' }: {
  tabs: Array<{ key: string; label: string; count?: number | string }>
  active: string; onChange: (key: string) => void; className?: string
}) {
  return (
    <div className={`scroll-slim -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 ${className || 'mb-5'}`} role="tablist">
      {tabs.map((t) => {
        const on = t.key === active
        return (
          <button key={t.key} type="button" role="tab" aria-selected={on} onClick={() => onChange(t.key)}
            className={`inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-[13px] font-medium transition duration-150 ${
              on ? 'bg-brand-700 text-white shadow-sm' : 'border border-line-strong bg-white text-slate-600 hover:border-brand-300 hover:text-brand-700'
            }`}>
            {t.label}
            {t.count !== undefined && (
              <span className={`nums rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${on ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500'}`}>{t.count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}

export function Pagination({ page, lastPage, total, perPage, onPage, busy }: {
  page: number; lastPage: number; total: number; perPage?: number
  onPage: (page: number) => void; busy?: boolean
}) {
  const from = total === 0 ? 0 : (page - 1) * (perPage ?? 25) + 1
  const to = Math.min(total, (page - 1) * (perPage ?? 25) + (perPage ?? 25))
  return (
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p className="nums text-xs text-ink-soft">
        Showing <span className="font-semibold text-slate-700">{from.toLocaleString()}–{to.toLocaleString()}</span> of{' '}
        <span className="font-semibold text-slate-700">{total.toLocaleString()}</span>
      </p>
      <div className="flex items-center gap-2">
        <Btn size="sm" variant="ghost" disabled={page <= 1 || busy} onClick={() => onPage(page - 1)} icon={<IconChevronLeft className="h-3.5 w-3.5" />}>Prev</Btn>
        <span className="nums rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{Math.max(page, 1)} / {Math.max(lastPage, 1)}</span>
        <Btn size="sm" variant="ghost" disabled={page >= lastPage || busy} onClick={() => onPage(page + 1)}>Next<IconChevronRight className="h-3.5 w-3.5" /></Btn>
      </div>
    </div>
  )
}

export function Avatar({ name, className = '' }: { name: string; className?: string }) {
  const initials = name.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
  return (
    <span className={`grid shrink-0 place-items-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-800 ring-1 ring-brand-200 ${className || 'h-8 w-8'}`}>
      {initials || '?'}
    </span>
  )
}

export function DataList({ rows }: { rows: Array<[string, ReactNode]> }) {
  return (
    <dl className="divide-y divide-line/70">
      {rows.map(([label, value]) => (
        <div key={label} className="flex items-baseline justify-between gap-4 py-2">
          <dt className="text-[13px] text-ink-soft">{label}</dt>
          <dd className="nums text-right text-[13px] font-semibold text-slate-800">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

export function StatusDot({ tone, label }: { tone: Tone; label: string }) {
  return (
    <span className="inline-flex items-center gap-2 text-[13px] font-medium text-slate-700">
      <span className={`h-2 w-2 rounded-full ${DOTS[tone]}`} />{label}
    </span>
  )
}

export function CheckPill({ on, children, onClick }: { on: boolean; children: ReactNode; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={on}
      className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${
        on ? 'bg-brand-700 text-white shadow-sm' : 'border border-line-strong bg-white text-slate-500 hover:border-brand-300 hover:text-brand-700'
      }`}>
      {on && <IconCheck className="h-3.5 w-3.5" />}{children}
    </button>
  )
}
