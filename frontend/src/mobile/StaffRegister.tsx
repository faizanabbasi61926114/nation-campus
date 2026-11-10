import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../store/toasts'
import { Screen } from './MobileShell'
import { Bar, DockBar, ListSkeleton, Segmented } from './shellParts'
import { STATUS_CHOICES } from './statuses'
import { useReloadOnRefresh } from './refresh'
import { Btn, Card, Empty, Notice } from '../components/ui'
import dayjs from 'dayjs'

interface StaffRow {
  id: number; name: string; employee_no: string | null; designation: string | null
  department: string | null; status: string | null; marked: boolean
}

/**
 * The staff register on a phone: the principal or HR marks who turned up. The same five statuses
 * as the class roll, the same /attendance/mark endpoint, only subject_type=staff.
 */
export default function StaffRegister() {
  const { user, campusId } = useAuth()
  const canMark = hasPerm(user, 'attendance.manage') || hasPerm(user, 'attendance.mark')
  const [date, setDate] = useState(dayjs().format('YYYY-MM-DD'))
  const [view, setView] = useState<'register' | 'month'>('register')
  const month = dayjs().format('YYYY-MM')

  const roster = useApi<{ data: StaffRow[] }>(
    campusId && view === 'register' ? `/attendance/roster?date=${date}&subject_type=staff` : null,
    [date, view, campusId])
  useReloadOnRefresh(roster.reload)
  const monthly = useApi<{ data: Array<{ subject_id: number; name: string; present: number; total: number; percentage: number; warning: boolean }> }>(
    campusId && view === 'month' ? `/attendance/monthly?month=${month}&subject_type=staff` : null,
    [month, view, campusId])
  useReloadOnRefresh(monthly.reload)

  const rows = roster.data?.data ?? []
  const marks = monthly.data?.data ?? []

  // A fresh date starts blank; compared during render so React never commits the old sheet first.
  const sheet = `${date}|${rows.map((r) => r.status ?? '').join()}`
  const [picks, setPicks] = useState<Record<number, string>>({})
  const [picksSheet, setPicksSheet] = useState(sheet)
  if (picksSheet !== sheet) {
    setPicksSheet(sheet)
    setPicks({})
  }

  const shown = (r: StaffRow) => picks[r.id] ?? r.status ?? ''
  const markedCount = rows.filter((r) => shown(r)).length
  const dirty = Object.keys(picks).length

  function setOne(id: number, status: string) {
    setPicks((p) => {
      const next = { ...p }
      if (next[id] === status) delete next[id]
      else next[id] = status
      return next
    })
  }

  async function save() {
    const records = rows
      .map((r) => ({ subject_id: r.id, status: picks[r.id] ?? r.status ?? '' }))
      .filter((r) => r.status)
    if (!records.length) return toast.error('Mark at least one person first.')
    try {
      const { data } = await api.post('/attendance/mark', { date, subject_type: 'staff', period_no: 0, records })
      toast.success(data.message ?? 'Staff register saved.')
      setPicks({})
      roster.reload()
    } catch (e) {
      toast.error(apiError(e))
    }
  }

  return (
    <Screen heading="Staff register" sub={campusId ? `${markedCount} of ${rows.length} marked` : undefined}>
      {!campusId && <Notice tone="amber" title="Select a campus">The staff register is kept per campus. Choose one in the header.</Notice>}

      <Segmented options={[{ key: 'register', label: 'Daily register' }, { key: 'month', label: 'This month' }]}
        value={view} onChange={(v) => setView(v as 'register' | 'month')} />

      {view === 'register' && (
        <>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-2 rounded-xl border border-line-strong bg-white px-3 py-2 text-[12.5px] font-semibold text-slate-700 shadow-card">
              <span className="text-ink-soft">Date</span>
              <input type="date" value={date} max={dayjs().format('YYYY-MM-DD')} className="nums bg-transparent outline-none"
                onChange={(e) => setDate(e.target.value)} />
            </label>
            <Btn size="sm" variant="ghost" onClick={() => setPicks(Object.fromEntries(rows.map((r) => [r.id, 'present'])))}>All present</Btn>
          </div>

          {!canMark && <Notice tone="blue" title="Read-only">Only HR or the principal can save the staff register.</Notice>}

          {roster.loading && <ListSkeleton rows={5} />}
          {roster.error && <Card className="p-3 text-[12.5px] text-rose-600">{roster.error}</Card>}

          {rows.length > 0 && (
            <Card className="divide-y divide-line/70">
              {rows.map((r) => {
                const on = shown(r)
                return (
                  <div key={r.id} className="px-3.5 py-3">
                    <div className="flex items-baseline gap-2">
                      <p className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-slate-900">{r.name}</p>
                      {on
                        ? <span className="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-ink-soft">{label(on)}</span>
                        : <span className="shrink-0 text-[10.5px] font-semibold uppercase tracking-wide text-slate-300">unmarked</span>}
                    </div>
                    <p className="nums mt-0.5 truncate text-[11px] text-ink-soft">
                      {[r.employee_no, r.designation ?? r.department].filter(Boolean).join(' · ') || 'No designation set'}
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      {STATUS_CHOICES.map((c) => (
                        <button key={c.key} type="button" disabled={!canMark} aria-pressed={on === c.key} data-on={on === c.key}
                          onClick={() => setOne(r.id, c.key)}
                          className={`rounded-lg px-2.5 py-1.5 text-[11.5px] font-semibold ring-1 ring-inset ring-line-strong bg-white text-slate-500 transition active:scale-95 disabled:opacity-40 ${c.tone}`}>
                          {c.label}
                        </button>
                      ))}
                    </div>
                  </div>
                )
              })}
            </Card>
          )}

          {!roster.loading && campusId && !rows.length && !roster.error && (
            <Card><Empty text="No staff records in this campus." hint="Add people under Staff & HR first — the register follows the HR list." /></Card>
          )}

          {canMark && dirty > 0 && (
            <DockBar>
              <p className="min-w-0 flex-1 text-[12px] font-semibold text-slate-700">{dirty} change{dirty === 1 ? '' : 's'} · {markedCount}/{rows.length} marked</p>
              <Btn size="sm" onClick={() => void save()}>Save register</Btn>
              <Btn size="sm" variant="ghost" onClick={() => setPicks({})}>Discard</Btn>
            </DockBar>
          )}
        </>
      )}

      {view === 'month' && (
        monthly.loading
          ? <ListSkeleton rows={4} />
          : marks.length
            ? (
              <Card className="divide-y divide-line/70">
                {marks.map((m) => (
                  <div key={m.subject_id} className="px-4 py-3">
                    <div className="flex items-baseline gap-2">
                      <p className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-slate-900">{m.name}</p>
                      <p className={`nums shrink-0 text-[12.5px] font-semibold ${m.warning ? 'text-rose-600' : 'text-slate-700'}`}>{m.percentage}%</p>
                    </div>
                    <p className="nums mt-0.5 text-[11px] text-ink-soft">{m.present} present of {m.total} marked days</p>
                    <div className="mt-2"><Bar percent={m.percentage} tone={m.warning ? 'rose' : 'brand'} /></div>
                  </div>
                ))}
              </Card>
            )
            : <Card><Empty text="Nothing marked for this month yet." /></Card>
      )}
    </Screen>
  )
}
