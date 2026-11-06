import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import dayjs from 'dayjs'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { Screen } from './MobileShell'
import { Bar, DockBar, ListSkeleton, Segmented } from './shellParts'
import { useReloadOnRefresh } from './refresh'
import { Btn, Card, Empty } from '../components/ui'
import { toast } from '../store/toasts'

interface RosterRow { id: number; roll_no: string | null; first_name: string; last_name: string; status: string | null }
interface MyClass {
  sections: Array<{ id: number; name: string; class: string | null; students: number }>
  periods: Array<{ slot_id: number; period_no: number; section_id: number; subject: string | null; start_time: string }>
}

const CHOICES: Array<{ key: string; label: string; tone: string }> = [
  { key: 'present', label: 'Present', tone: 'data-[on=true]:bg-brand-700 data-[on=true]:text-white data-[on=true]:ring-brand-700' },
  { key: 'late', label: 'Late', tone: 'data-[on=true]:bg-amber-500 data-[on=true]:text-white data-[on=true]:ring-amber-500' },
  { key: 'absent', label: 'Absent', tone: 'data-[on=true]:bg-rose-600 data-[on=true]:text-white data-[on=true]:ring-rose-600' },
  { key: 'leave', label: 'Leave', tone: 'data-[on=true]:bg-sky-600 data-[on=true]:text-white data-[on=true]:ring-sky-600' },
  { key: 'excused', label: 'Excused', tone: 'data-[on=true]:bg-slate-600 data-[on=true]:text-white data-[on=true]:ring-slate-600' },
]

export default function RollCall() {
  const [sp, setSp] = useSearchParams()
  const mine = useApi<{ data: MyClass }>('/teacher/my-class')
  useReloadOnRefresh(mine.reload)

  const sections = mine.data?.data.sections ?? []
  const sectionId = Number(sp.get('section')) || sections[0]?.id || 0
  const period = Number(sp.get('period') ?? 0)
  const [date, setDate] = useState(dayjs().format('YYYY-MM-DD'))

  const roster = useApi<{ data: RosterRow[] }>(
    sectionId ? `/attendance/roster?date=${date}&subject_type=student&section_id=${sectionId}&period_no=${period}` : null,
    [date, sectionId, period])
  useReloadOnRefresh(roster.reload)

  const rows = roster.data?.data ?? []

  // A new sheet starts blank. Compared during render rather than in an effect so
  // the blank sheet is what React commits, with no extra paint of the old marks.
  const sheet = `${date}|${sectionId}|${period}`
  const [picks, setPicks] = useState<Record<number, string>>({})
  const [picksSheet, setPicksSheet] = useState(sheet)
  if (picksSheet !== sheet) {
    setPicksSheet(sheet)
    setPicks({})
  }

  const section = sections.find((s) => s.id === sectionId)
  const periods = (mine.data?.data.periods ?? []).filter((p) => p.section_id === sectionId)
  // The roster is already asked for this period, so a row's own status is the saved mark for it.
  const shown = (r: RosterRow) => picks[r.id] !== undefined ? picks[r.id] : (r.status ?? '')
  const markedCount = rows.filter(shown).length
  const dirty = rows.filter((r) => (picks[r.id] ?? r.status ?? '') !== (r.status ?? '') && picks[r.id] !== undefined).length

  const setOne = (id: number, status: string) => setPicks((p) => ({ ...p, [id]: p[id] === status ? '' : status }))

  async function save() {
    const records = rows
      .map((r) => ({ subject_id: r.id, status: picks[r.id] ?? r.status ?? '' }))
      .filter((r) => r.status)
      .map((r) => ({ ...r, status: r.status as string }))
    if (!records.length) return toast.error('Mark at least one student first.')
    try {
      const { data } = await api.post('/attendance/mark', { date, subject_type: 'student', period_no: period, records })
      toast.success(data.message ?? 'Attendance saved.')
      setPicks({})
      roster.reload()
      mine.reload()
    } catch (e) {
      toast.error(apiError(e))
    }
  }

  function markAllPresent() {
    setPicks(Object.fromEntries(rows.map((r) => [r.id, 'present'])))
  }

  if (mine.loading || roster.loading) return <Screen><ListSkeleton rows={5} /></Screen>

  return (
    <Screen heading={section ? `${section.class ?? ''} ${section.name}` : 'Roll call'}
      sub={`${rows.length} students · ${period ? `period ${period}` : 'day register'}`}
      error={roster.error || mine.error} onRetry={roster.reload}>
      {!sections.length && <Card><Empty text="You are not the class teacher of any section." /></Card>}

      {sections.length > 1 && (
        <Segmented options={sections.map((s) => ({ key: String(s.id), label: `${s.class ?? ''} ${s.name}` }))}
          value={String(sectionId)} onChange={(v) => setSp({ section: v, period: String(period) })} />
      )}

      <div className="flex items-center gap-2">
        <label className="flex items-center gap-2 rounded-xl border border-line-strong bg-white px-3 py-2 text-[12.5px] font-semibold text-slate-700 shadow-card">
          <span className="text-ink-soft">Date</span>
          <input type="date" value={date} max={dayjs().format('YYYY-MM-DD')} className="nums bg-transparent outline-none"
            onChange={(e) => setDate(e.target.value)} />
        </label>
        <Btn size="sm" variant="ghost" onClick={markAllPresent}>All present</Btn>
      </div>

      {period === 0 && periods.length > 0 && (
        <Segmented options={[{ key: '0', label: 'Day register' }, ...periods.map((p) => ({ key: String(p.period_no), label: `P${p.period_no}`, note: p.start_time }))]}
          value="0" onChange={(v) => v !== '0' && setSp({ section: String(sectionId), period: v })} />
      )}
      {period > 0 && (
        <div className="flex items-center gap-2">
          <Bar percent={rows.length ? (rows.filter(shown).length / rows.length) * 100 : 0} tone="gold" />
          <button type="button" onClick={() => setSp({ section: String(sectionId), period: '0' })}
            className="shrink-0 text-[11.5px] font-semibold text-brand-700 underline">back to day</button>
        </div>
      )}

      <Card className="divide-y divide-line/70">
        {rows.map((r) => {
          const on = shown(r)
          return (
            <div key={r.id} className="px-3.5 py-3">
              <div className="flex items-baseline gap-2">
                <span className="nums w-8 shrink-0 text-[11px] font-semibold text-slate-400">{r.roll_no ?? '—'}</span>
                <p className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-slate-900">{r.first_name} {r.last_name}</p>
                {!on && <span className="text-[10.5px] font-semibold uppercase tracking-wide text-slate-300">unmarked</span>}
              </div>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {CHOICES.map((c) => (
                  <button key={c.key} type="button" aria-pressed={on === c.key} data-on={on === c.key} onClick={() => setOne(r.id, c.key)}
                    className={`rounded-lg px-2.5 py-1.5 text-[11.5px] font-semibold ring-1 ring-inset ring-line-strong bg-white text-slate-500 transition active:scale-95 ${c.tone}`}>
                    {c.label}
                  </button>
                ))}
              </div>
            </div>
          )
        })}
        {!rows.length && <Empty text="This section has no active students." />}
      </Card>

      {dirty > 0 && (
        <DockBar>
          <p className="min-w-0 flex-1 text-[12px] font-semibold text-slate-700">{dirty} change{dirty === 1 ? '' : 's'} to save · {markedCount}/{rows.length} marked</p>
          <Btn size="sm" onClick={() => void save()}>Save roll call</Btn>
          <Btn size="sm" variant="ghost" onClick={() => setPicks({})}>Discard</Btn>
        </DockBar>
      )}
    </Screen>
  )
}
