import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { todayISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDateTime, hasPerm } from '../lib/types'
import { toast } from '../store/toasts'
import {
  IconAlert, IconCheck, IconGate, IconMessages, IconStudents, IconClock,
} from '../components/icons'
import {
  Badge, Btn, Empty, ErrorNote, Field, Notice, PageHeader, StatCard, Table, Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

interface Entry {
  id: number; student_id: number; name: string; roll_no: string | null; class: string | null
  campus: string | null; direction: string; at: string; time: string; gate: string | null
  source: string; remarks: string | null; notified: boolean; recorded_by: string | null
}
interface GateSummary {
  date: string; arrivals: number; departures: number; inside: number
  expected: number; untapped: number; families_alerted: number
}
interface StudentOption { id: number; first_name: string; last_name: string; roll_no: string | null }

const GATES = ['Main gate', 'Back gate', 'Bus bay', 'Reception']

/** The student gate log: a tap here is what tells a family their child arrived. */
export default function Entries() {
  const { user, campusId } = useAuth()
  const canRecord = hasPerm(user, 'frontdesk.manage')

  const [date, setDate] = useState(todayISO)
  const [direction, setDirection] = useState('')
  const [q, setQ] = useState('')

  const query = `/entries?date=${date}${direction ? `&direction=${direction}` : ''}${q ? `&q=${encodeURIComponent(q)}` : ''}`
  const { data, loading, error, reload } = useApi<{ data: Entry[] }>(query, [date, direction, q])
  const { data: summaryData, reload: reloadSummary } = useApi<{ data: GateSummary }>('/entries/summary')

  const rows = data?.data ?? []
  const stats = summaryData?.data
  const refresh = () => { reload(); reloadSummary() }

  return (
    <div>
      <PageHeader title="Gate entry" subtitle="Tap a child In or Out — the first arrival and departure of the day alert the family"
        icon={<IconGate className="h-5 w-5" />} />

      {!campusId && <Notice tone="amber" title="Select a campus" className="mb-4">Pick a campus from the header first — gate taps belong to one campus.</Notice>}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Arrivals today" value={stats?.arrivals ?? '—'} hint="Gate taps marked In" icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="Inside now" value={stats?.inside ?? '—'} hint="Last tap was In" icon={<IconStudents className="h-4 w-4" />} tone="blue" />
        <StatCard label="Departures" value={stats?.departures ?? '—'} hint={`of ${stats?.expected ?? 0} enrolled`} icon={<IconClock className="h-4 w-4" />} tone="violet" />
        <StatCard label="Families alerted" value={stats?.families_alerted ?? '—'} hint={`${stats?.untapped ?? 0} child(ren) not tapped yet`} icon={<IconMessages className="h-4 w-4" />} tone="amber" />
      </div>

      {canRecord && <RecordPanel onSaved={refresh} disabled={!campusId} />}

      <Toolbar>
        <input type="date" className={`${inputCls} w-44`} value={date} onChange={(e) => setDate(e.target.value)} aria-label="Entry date" />
        <select className={`${selectCls} w-40`} value={direction} onChange={(e) => setDirection(e.target.value)} aria-label="Direction">
          <option value="">Both In and Out</option>
          <option value="in">In only</option>
          <option value="out">Out only</option>
        </select>
        <input className={`${inputCls} w-56`} value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, roll or admission no." aria-label="Search students" autoComplete="off" />
      </Toolbar>

      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      <Table headers={<><Th>Student</Th><Th>Class</Th><Th>Direction</Th><Th>Time</Th><Th>Gate</Th><Th>Recorded by</Th><Th>Family</Th></>}
        cols={7} loading={loading}>
        {rows.map((e) => (
          <tr key={e.id}>
            <Td className="font-medium">{e.name}<span className="ml-1 text-xs text-slate-400">{e.roll_no ?? ''}</span></Td>
            <Td className="text-xs">{e.class ?? '—'}{e.campus && <span className="ml-1 text-slate-400">· {e.campus}</span>}</Td>
            <Td>
              <Badge tone={e.direction === 'in' ? 'green' : 'slate'} dot>{e.direction === 'in' ? 'In' : 'Out'}</Badge>
              {e.source === 'roll_call' && <span className="ml-2 text-[11px] text-ink-soft">from register</span>}
            </Td>
            <Td className="nums whitespace-nowrap text-xs">{fmtDateTime(e.at)}</Td>
            <Td className="text-xs">{e.gate ?? '—'}</Td>
            <Td className="text-xs">{e.recorded_by ?? '—'}</Td>
            <Td>{e.notified ? <Badge tone="blue">alerted</Badge> : <span className="text-[11px] text-slate-400">already alerted today</span>}</Td>
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={7}><Empty text="No gate taps on this date." hint="Record a child above, or check that the campus and date are right." /></Td></tr>
        )}
      </Table>

      {rows.some((e) => e.remarks) && (
        <p className="mt-3 flex items-start gap-1.5 text-xs text-ink-soft">
          <IconAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />Remarks written on a tap are included in the family alert.
        </p>
      )}
    </div>
  )
}

/** The desk's own panel: search a child, tap In or Out, done. */
function RecordPanel({ onSaved, disabled }: { onSaved: () => void; disabled: boolean }) {
  const [q, setQ] = useState('')
  // The endpoint caps a page at 100 rows, so the desk searches rather than scrolling a list.
  const { data: students, loading } = useApi<{ data: StudentOption[] }>(
    `/students?status=active&per_page=50&search=${encodeURIComponent(q)}`, [q])
  const [picked, setPicked] = useState<StudentOption | null>(null)
  const [gate, setGate] = useState(GATES[0])
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState('')

  async function tap(direction: 'in' | 'out') {
    if (!picked) return toast.error('Choose a student first.')
    setBusy(direction)
    try {
      const { data } = await api.post('/entries', {
        student_id: picked.id, direction, gate, remarks: remarks || null,
      })
      toast.success(data.message ?? 'Recorded.')
      setRemarks('')
      onSaved()
    } catch (e) {
      toast.error(apiError(e))
    } finally {
      setBusy('')
    }
  }

  // A fresh search page can drop the child already picked, which would silently reset the desk.
  const page = students?.data ?? []
  const options = picked && !page.some((s) => s.id === picked.id) ? [picked, ...page] : page

  return (
    <div className="mb-5 rounded-2xl border border-line bg-white p-4 shadow-card">
      <div className="grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
        <Field label="Student" hint={loading ? 'Searching…' : `${options.length} match(es)`}>
          <input className={inputCls} value={q} onChange={(e) => setQ(e.target.value)}
            placeholder="Type a name, roll or admission no." disabled={disabled} autoComplete="off" aria-label="Find a child to tap" />
        </Field>
        <Field label="Child">
          <select className={selectCls} value={picked?.id ?? ''} disabled={disabled || loading}
            onChange={(e) => setPicked(options.find((s) => s.id === Number(e.target.value)) ?? null)} aria-label="Student">
            <option value="">Select a child…</option>
            {options.map((s) => (
              <option key={s.id} value={s.id}>{s.first_name} {s.last_name}{s.roll_no ? ` — ${s.roll_no}` : ''}</option>
            ))}
          </select>
        </Field>
        <Field label="Gate">
          <input className={inputCls} list="gate-options" value={gate} onChange={(e) => setGate(e.target.value)} disabled={disabled} />
          <datalist id="gate-options">{GATES.map((g) => <option key={g} value={g} />)}</datalist>
        </Field>
        <Btn variant="soft" disabled={disabled || !!busy || !picked} onClick={() => tap('in')} icon={<IconCheck className="h-4 w-4" />}>
          {busy === 'in' ? 'Saving…' : 'Mark In'}
        </Btn>
        <Btn variant="ghost" disabled={disabled || !!busy || !picked} onClick={() => tap('out')}>
          {busy === 'out' ? 'Saving…' : 'Mark Out'}
        </Btn>
      </div>
      <div className="mt-3">
        <Field label="Note for the family" hint="Optional — travels with the alert">
          <input className={inputCls} value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Early pickup by grandfather" disabled={disabled} autoComplete="off" />
        </Field>
      </div>
    </div>
  )
}
