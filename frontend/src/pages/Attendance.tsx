import { useMemo, useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { toast } from '../components/Toast'
import { IconAttendance } from '../components/icons'
import { label } from '../lib/labels'
import { Btn, Empty, Notice, PageHeader, Status, StatusDot, Table, Tabs, Td, Th, Toolbar, inputCls, selectCls, type Tone } from '../components/ui'

interface RosterRow {
  id: number; first_name: string; last_name?: string; roll_no?: string | null
  status: string | null; marked: boolean
}
interface MonthlyRow {
  subject_id: number; name: string; present: number; absent: number; leave: number
  excused: number; total: number; percentage: number; warning: boolean
}

/** Marking colours come from the same tone vocabulary as Badge, so the register
 *  legend, the buttons and the monthly summary all read the same way. */
const STATUSES: { key: string; label: string; tone: Tone; on: string }[] = [
  { key: 'present', label: 'P', tone: 'green', on: 'bg-brand-600 text-white shadow-sm' },
  { key: 'absent', label: 'A', tone: 'rose', on: 'bg-rose-600 text-white shadow-sm' },
  { key: 'late', label: 'L', tone: 'amber', on: 'bg-amber-500 text-white shadow-sm' },
  { key: 'leave', label: 'Lv', tone: 'blue', on: 'bg-sky-600 text-white shadow-sm' },
  { key: 'excused', label: 'E', tone: 'slate', on: 'bg-slate-500 text-white shadow-sm' },
]

export default function Attendance() {
  const { user } = useAuth()
  const canMark = user?.permissions?.includes('attendance.mark') || user?.permissions?.includes('*')
  const campusSelected = !!localStorage.getItem('cms_campus')
  const [tab, setTab] = useState<'mark' | 'monthly'>('mark')
  const [subjectType, setSubjectType] = useState<'student' | 'staff'>('student')
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))

  const { data: sections } = useApi<{ data: { id: number; name: string; school_class?: { name: string } }[] }>('/sections')
  const [sectionId, setSectionId] = useState<number | null>(null)
  const effectiveSection = sectionId ?? sections?.data[0]?.id ?? null

  const rosterKey = tab === 'mark' && campusSelected
    ? subjectType === 'staff'
      ? `/attendance/roster?date=${date}&subject_type=staff`
      : effectiveSection ? `/attendance/roster?date=${date}&subject_type=student&section_id=${effectiveSection}` : null
    : null
  const { data: roster, loading: rosterLoading, error: rosterError, reload } = useApi<{ data: RosterRow[] }>(rosterKey, [rosterKey])
  const { data: monthly, loading: monthlyLoading, error: monthlyError, reload: reloadMonthly } = useApi<{ data: MonthlyRow[] }>(
    tab === 'monthly' && campusSelected
      ? subjectType === 'staff'
        ? `/attendance/monthly?month=${month}&subject_type=staff`
        : effectiveSection ? `/attendance/monthly?month=${month}&subject_type=student&section_id=${effectiveSection}` : null
      : null,
    [tab, month, subjectType, effectiveSection, campusSelected],
  )

  const [draft, setDraft] = useState<Record<number, string>>({})
  const [saving, setSaving] = useState(false)
  const rows = roster?.data ?? []
  const counts = useMemo(() => {
    const c: Record<string, number> = {}
    for (const r of rows) { const s = draft[r.id] ?? r.status; if (s) c[s] = (c[s] ?? 0) + 1 }
    return c
  }, [rows, draft])

  async function save() {
    const records = rows
      .map((r) => ({ subject_id: r.id, status: draft[r.id] ?? r.status }))
      .filter((r): r is { subject_id: number; status: string } => !!r.status)
    if (!records.length) return
    setSaving(true)
    try {
      await api.post('/attendance/mark', { date, subject_type: subjectType, records })
      setDraft({})
      reload()
    } catch (e) { toast.error(apiError(e)) } finally { setSaving(false) }
  }

  return (
    <div>
      <PageHeader title="Attendance" subtitle="Daily register and monthly summaries for the selected campus" icon={<IconAttendance className="h-5 w-5" />} />
      {!campusSelected && (
        <Notice tone="amber" title="No campus selected" className="mb-4">
          Select a campus from the switcher above to record or view attendance.
        </Notice>
      )}
      <div className="flex flex-wrap items-start gap-x-6">
        <Tabs active={tab} onChange={(k) => setTab(k as 'mark' | 'monthly')}
          tabs={[{ key: 'mark', label: 'Daily register' }, { key: 'monthly', label: 'Monthly summary' }]} />
        <Tabs active={subjectType} onChange={(k) => { setSubjectType(k as 'student' | 'staff'); setDraft({}) }}
          tabs={[{ key: 'student', label: 'Students' }, { key: 'staff', label: 'Staff' }]} />
      </div>
      <Toolbar>
        {subjectType === 'student' && (
          <div className="w-52">
            <select className={selectCls} aria-label="Section" value={effectiveSection ?? ''} onChange={(e) => { setSectionId(Number(e.target.value)); setDraft({}) }}>
              {(sections?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.school_class?.name} · {s.name}</option>)}
            </select>
          </div>
        )}
        {tab === 'mark'
          ? (
            <div className="w-44">
              <input type="date" className={inputCls} aria-label="Register date" value={date} max={new Date().toISOString().slice(0, 10)} onChange={(e) => { setDate(e.target.value); setDraft({}) }} />
            </div>
          )
          : (
            <div className="w-40">
              <input type="month" className={inputCls} aria-label="Summary month" value={month} onChange={(e) => setMonth(e.target.value)} />
            </div>
          )}
        {tab === 'mark' && canMark && (
          <Btn className="ml-auto" onClick={save} disabled={saving || !Object.keys(draft).length}>
            {saving ? 'Saving…' : `Save ${Object.keys(draft).length ? Object.keys(draft).length + ' change(s)' : 'register'}`}
          </Btn>
        )}
      </Toolbar>

      {tab === 'mark' ? (
        <>
          <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5">
            {STATUSES.map((s) => <StatusDot key={s.key} tone={s.tone} label={`${label(s.key)} ${counts[s.key] ?? 0}`} />)}
            <span className="nums ml-auto text-xs text-ink-soft">Unmarked: {rows.length - Object.values(counts).reduce((a, b) => a + b, 0)}</span>
          </div>
          <Table headers={<><Th>Roll #</Th><Th>Name</Th><Th>Status</Th></>}
            cols={3} loading={rosterLoading} error={rosterError} onRetry={reload}>
            {rows.map((r) => {
              const current = draft[r.id] ?? r.status
              return (
                <tr key={r.id}>
                  <Td className="nums text-xs">{r.roll_no ?? `#${r.id}`}</Td>
                  <Td className="font-medium">{[r.first_name, r.last_name].filter(Boolean).join(' ')}</Td>
                  <Td>
                    <div className="flex gap-1">
                      {STATUSES.map((s) => (
                        <button key={s.key} disabled={!canMark}
                          onClick={() => setDraft({ ...draft, [r.id]: s.key })}
                          title={label(s.key)} aria-label={label(s.key)} aria-pressed={current === s.key}
                          className={`h-7 w-8 rounded-lg text-xs font-semibold transition ${current === s.key ? s.on : 'border border-line-strong bg-white text-slate-500 hover:border-brand-300 hover:text-brand-700'} disabled:cursor-not-allowed disabled:opacity-40`}>
                          {s.label}
                        </button>
                      ))}
                      {!r.marked && !current && <span className="self-center pl-2 text-[11px] text-slate-400">not marked</span>}
                    </div>
                  </Td>
                </tr>
              )
            })}
            {!rows.length && !rosterLoading && (
              <tr><Td colSpan={3}><Empty text={campusSelected ? 'Nothing to show for this selection.' : 'Select a campus first.'} hint={canMark ? 'Pick a section and date, then mark the register.' : undefined} /></Td></tr>
            )}
          </Table>
        </>
      ) : (
        <Table headers={<><Th>Name</Th><Th align="right">Present</Th><Th align="right">Absent</Th><Th align="right">Leave</Th><Th align="right">Excused</Th><Th align="right">Days</Th><Th align="right">%</Th></>}
          cols={7} loading={monthlyLoading} error={monthlyError} onRetry={reloadMonthly}>
          {(monthly?.data ?? []).map((r) => (
            <tr key={r.subject_id}>
              <Td className="font-medium">{r.name}{r.warning && <span className="ml-2 inline-flex"><Status value="low" tone="rose" /></span>}</Td>
              <Td className="nums text-right">{r.present}</Td>
              <Td className="nums text-right">{r.absent}</Td>
              <Td className="nums text-right">{r.leave}</Td>
              <Td className="nums text-right">{r.excused}</Td>
              <Td className="nums text-right">{r.total}</Td>
              <Td className="text-right">
                <div className="flex items-center justify-end gap-2">
                  <div className="h-1.5 w-24 overflow-hidden rounded-full bg-line-strong">
                    <div className={`h-full ${r.warning ? 'bg-rose-500' : 'bg-brand-500'}`} style={{ width: `${r.percentage}%` }} />
                  </div>
                  <span className={`nums text-sm font-semibold ${r.warning ? 'text-rose-600' : 'text-slate-700'}`}>{r.percentage}%</span>
                </div>
              </Td>
            </tr>
          ))}
          {!monthly?.data?.length && !monthlyLoading && <tr><Td colSpan={7}><Empty text="No attendance recorded for this period." hint="Mark the daily register for the dates in this month first." /></Td></tr>}
        </Table>
      )}
    </div>
  )
}
