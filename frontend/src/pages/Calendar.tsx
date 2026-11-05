import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, hasPerm } from '../lib/types'
import { label, tone as toneOf } from '../lib/labels'
import { toast } from '../components/Toast'
import { askConfirm } from '../components/Confirm'
import { IconAlert, IconCampuses, IconChevronLeft, IconChevronRight, IconPlus, IconTimetable, IconTrash } from '../components/icons'
import {
  Badge, Btn, Card, Empty, ErrorNote, Field, Modal, Notice, PageHeader, StatCard, Status, Toolbar, toneFor,
  inputCls, selectCls,
} from '../components/ui'

const TYPES = ['holiday', 'closure', 'exam', 'ptm', 'event', 'meeting', 'vacation']
const AUDIENCES = ['all', 'students', 'faculty', 'staff']
const WEEK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

interface CalendarEvent {
  id: number; title: string; type: string; start_date: string; end_date: string
  description?: string | null; audience: string; affects_attendance: boolean
  campus_id: number | null; is_campus_wide?: boolean
  campus?: { name: string; code: string } | null
}

interface CalSummary {
  month: string; this_month: number; holidays_this_month: number; today: string
  today_is_holiday: boolean
  today_events: { id: number; title: string; type: string; is_campus_wide: boolean }[]
  upcoming: CalendarEvent[]
  next_event?: CalendarEvent | null
}

interface Draft {
  id?: number; title: string; type: string; start_date: string; end_date: string
  audience: string; description: string; attendance: boolean; campus_id: string
}

/** UTC keeps a month calculation independent of the browser's own offset. */
const ymd = (d: Date) => d.toISOString().slice(0, 10)

export default function Calendar() {
  const { user, campusId } = useAuth()
  const canManage = hasPerm(user, 'calendar.manage')
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const [form, setForm] = useState<Draft | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(0)

  const [year, monthNo] = month.split('-').map(Number)
  const dayCount = new Date(Date.UTC(year, monthNo, 0)).getUTCDate()
  const from = `${month}-01`
  const to = `${month}-${String(dayCount).padStart(2, '0')}`
  const lead = (new Date(Date.UTC(year, monthNo - 1, 1)).getUTCDay() + 6) % 7

  const { data, loading, error, reload } = useApi<{ data: CalendarEvent[] }>(`/calendar?from=${from}&to=${to}`, [month])
  const { data: summaryData, reload: reloadSummary } = useApi<{ data: CalSummary }>('/calendar/summary')
  const { data: campusData } = useApi<{ data: { id: number; name: string; code: string }[] }>('/campuses')

  const rows = data?.data ?? []
  const stats = summaryData?.data
  const campuses = campusData?.data ?? []
  const today = stats?.today ?? ymd(new Date())
  // An organization-wide event can only be raised while no single campus is in scope.
  const wideScope = !campusId

  const daysInMonth = Array.from({ length: dayCount }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)
  const eventsOn = (day: string) => rows.filter((e) => e.start_date <= day && e.end_date >= day)

  function shiftMonth(step: number) {
    const next = new Date(Date.UTC(year, monthNo - 1 + step, 1))
    setMonth(next.toISOString().slice(0, 7))
  }

  function newDraft() {
    setErr('')
    setForm({
      title: '', type: 'holiday', start_date: today, end_date: today,
      audience: 'all', description: '', attendance: true, campus_id: '',
    })
  }

  function editDraft(e: CalendarEvent) {
    setErr('')
    setForm({
      id: e.id, title: e.title, type: e.type, start_date: e.start_date, end_date: e.end_date,
      audience: e.audience, description: e.description ?? '', attendance: !!e.affects_attendance,
      campus_id: e.campus_id ? String(e.campus_id) : '',
    })
  }

  async function save() {
    if (!form) return
    if (!form.title.trim()) { setErr('Give the event a title.'); return }
    if (form.end_date && form.end_date < form.start_date) { setErr('The end date cannot fall before the start date.'); return }
    setErr('')
    const payload: Record<string, unknown> = {
      title: form.title.trim(),
      type: form.type,
      start_date: form.start_date,
      end_date: form.end_date || form.start_date,
      audience: form.audience,
      description: form.description || null,
      affects_attendance: form.attendance,
    }
    if (wideScope) payload.campus_id = form.campus_id === '' ? null : Number(form.campus_id)
    try {
      if (form.id) await api.put(`/calendar/${form.id}`, payload)
      else await api.post('/calendar', payload)
      toast.success(form.id ? 'Event updated.' : 'Event added to the calendar.')
      setForm(null)
      reload(); reloadSummary()
    } catch (e) { setErr(apiError(e)) }
  }

  async function remove(e: CalendarEvent) {
    const ok = await askConfirm({
      title: 'Remove this event?',
      text: `“${e.title}” (${fmtDate(e.start_date)} – ${fmtDate(e.end_date)}) disappears from the calendar. Attendance marks already recorded are not changed.`,
      confirmLabel: 'Remove event',
    })
    if (!ok) return
    setBusy(e.id)
    try {
      await api.delete(`/calendar/${e.id}`)
      toast.success('Event removed from the calendar.')
      reload(); reloadSummary()
    } catch (err2) { toast.error(apiError(err2)) } finally { setBusy(0) }
  }

  const shutToday = !!stats?.today_is_holiday

  return (
    <div>
      <PageHeader title="School Calendar" subtitle="Holidays, closures, examinations and PTMs — campus-wide or for the whole organization"
        icon={<IconTimetable className="h-5 w-5" />}
        actions={canManage && <Btn onClick={newDraft} icon={<IconPlus className="h-4 w-4" />}>New event</Btn>} />

      {shutToday && (
        <Notice tone={stats?.today_events.some((t) => t.type === 'closure') ? 'rose' : 'amber'}
          title={`Today is a ${label(stats?.today_events[0]?.type ?? 'holiday')}`} className="mb-4">
          {stats?.today_events.map((t) => t.title).join(' · ')} — attendance is not taken for this campus today.
        </Notice>
      )}
      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Events this month" value={stats?.this_month ?? '—'} hint={stats?.month} icon={<IconTimetable className="h-4 w-4" />} tone="blue" />
        <StatCard label="Holidays & closures" value={stats?.holidays_this_month ?? '—'} hint="Days the campus stays shut" icon={<IconAlert className="h-4 w-4" />} tone="amber" />
        <StatCard label="Next event" value={stats?.next_event?.title ?? '—'} hint={stats?.next_event ? fmtDate(stats.next_event.start_date) : 'Nothing scheduled ahead'} icon={<IconChevronRight className="h-4 w-4" />} tone="green" />
        <StatCard label="Today" value={shutToday ? 'Closed' : 'Open day'} hint={fmtDate(today)} icon={<IconCampuses className="h-4 w-4" />} tone={shutToday ? 'rose' : 'green'} />
      </div>

      <Toolbar className="mb-3">
        <Btn size="sm" variant="ghost" onClick={() => shiftMonth(-1)} icon={<IconChevronLeft className="h-3.5 w-3.5" />}>Previous</Btn>
        <span className="nums min-w-40 text-center text-sm font-semibold text-slate-800">
          {new Date(Date.UTC(year, monthNo - 1, 1)).toLocaleDateString('en-PK', { month: 'long', year: 'numeric', timeZone: 'UTC' })}
        </span>
        <Btn size="sm" variant="ghost" onClick={() => shiftMonth(1)}>Next<IconChevronRight className="h-3.5 w-3.5" /></Btn>
        <Btn size="sm" variant="soft" onClick={() => setMonth(new Date().toISOString().slice(0, 7))}>This month</Btn>
        <span className="ml-auto flex flex-wrap items-center gap-3 text-xs text-ink-soft">
          <span className="flex items-center gap-1"><IconCampuses className="h-3.5 w-3.5" /> Organization-wide</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-brand-500" /> Keeps the campus shut</span>
        </span>
      </Toolbar>

      <Card className="mb-6 p-3">
        <div className="grid grid-cols-7 gap-1">
          {WEEK.map((w) => (
            <div key={w} className="px-1 pb-1 text-center text-[11px] font-semibold uppercase tracking-wide text-ink-soft">{w}</div>
          ))}
          {Array.from({ length: lead }, (_, i) => <div key={`blank-${i}`} />)}
          {daysInMonth.map((day) => {
            const events = eventsOn(day)
            const isToday = day === today
            return (
              <div key={day} className={`min-h-24 rounded-xl border p-1.5 ${isToday ? 'border-brand-400 bg-brand-50/60' : 'border-line bg-white'} ${events.some((e) => e.affects_attendance) ? 'bg-rose-50/40' : ''}`}>
                <p className={`nums mb-1 text-[11px] font-semibold ${isToday ? 'text-brand-700' : 'text-slate-500'}`}>{Number(day.slice(8))}</p>
                <div className="flex flex-col gap-1">
                  {events.slice(0, 3).map((e) => (
                    <span key={e.id} title={`${e.title} · ${label(e.type)} · ${fmtDate(e.start_date)} – ${fmtDate(e.end_date)}${e.is_campus_wide ? ' · all campuses' : ''}`}
                      className={`flex items-center gap-1 truncate rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${toneFor(toneOf(e.type))}`}>
                      {e.is_campus_wide && <IconCampuses className="h-3 w-3 shrink-0" />}
                      {e.affects_attendance && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-70" />}
                      <span className="truncate">{e.title}</span>
                    </span>
                  ))}
                  {events.length > 3 && <span className="px-1 text-[10px] font-medium text-ink-soft">+{events.length - 3} more</span>}
                </div>
              </div>
            )
          })}
        </div>
      </Card>

      <h2 className="mb-2 text-sm font-semibold text-slate-800">Everything in this month, in date order</h2>
      {loading && !rows.length && <Card className="p-2"><Empty text="Loading the calendar…" /></Card>}
      {!loading && !rows.length && (
        <Card className="p-2"><Empty text="No events in this month." hint={canManage ? 'Add a holiday, examination or PTM with New event.' : 'Holidays for your campus will appear here.'} /></Card>
      )}
      <div className="space-y-2">
        {rows.map((e) => (
          <Card key={e.id} className="flex flex-wrap items-center gap-3 p-4">
            <div className="nums min-w-32 text-xs font-semibold text-slate-600">
              {fmtDate(e.start_date)}
              {e.end_date !== e.start_date && <span className="block font-normal text-ink-soft">to {fmtDate(e.end_date)}</span>}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium text-slate-900">{e.title}</p>
              <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-soft">
                <Badge tone={toneOf(e.type)}>{label(e.type)}</Badge>
                <span>{label(e.audience)}</span>
                <span>· {e.is_campus_wide ? 'All campuses' : e.campus?.name ?? 'This campus'}</span>
                {e.affects_attendance && <span>· no attendance</span>}
              </p>
            </div>
            {canManage && (
              <div className="flex flex-wrap gap-1.5">
                <Btn size="sm" variant="ghost" onClick={() => editDraft(e)}>Edit</Btn>
                <Btn size="sm" variant="danger" disabled={busy === e.id} onClick={() => void remove(e)} icon={<IconTrash className="h-3.5 w-3.5" />}>Remove</Btn>
              </div>
            )}
            {e.description && <p className="basis-full pt-1 text-sm leading-6 text-slate-600">{e.description}</p>}
          </Card>
        ))}
      </div>

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? 'Edit calendar event' : 'New calendar event'}
          subtitle={wideScope ? 'Leave the campus as “All campuses” to shut every branch on that day.' : 'This event is for the active campus.'}
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={() => void save()}>{form.id ? 'Save changes' : 'Add to calendar'}</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Title"><input className={inputCls} maxLength={180} value={form.title} onChange={(ev) => setForm({ ...form, title: ev.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Type">
                <select className={selectCls} value={form.type} onChange={(ev) => {
                  const t = ev.target.value
                  setForm({ ...form, type: t, attendance: ['holiday', 'closure'].includes(t) ? true : form.attendance })
                }}>
                  {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
                </select>
              </Field>
              <Field label="Audience">
                <select className={selectCls} value={form.audience} onChange={(ev) => setForm({ ...form, audience: ev.target.value })}>
                  {AUDIENCES.map((a) => <option key={a} value={a}>{label(a)}</option>)}
                </select>
              </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Starts"><input type="date" className={inputCls} value={form.start_date} onChange={(ev) => setForm({ ...form, start_date: ev.target.value })} /></Field>
              <Field label="Ends" hint="Same day for a one-day event"><input type="date" className={inputCls} value={form.end_date} onChange={(ev) => setForm({ ...form, end_date: ev.target.value })} /></Field>
            </div>
            {wideScope && (
              <Field label="Campus" hint="Empty means every campus in the organization">
                <select className={selectCls} value={form.campus_id} onChange={(ev) => setForm({ ...form, campus_id: ev.target.value })}>
                  <option value="">All campuses</option>
                  {campuses.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                </select>
              </Field>
            )}
            <label className="flex items-center gap-2 rounded-xl border border-line bg-canvas/60 px-3 py-2.5 text-sm text-slate-700">
              <input type="checkbox" className="accent-brand-600" checked={form.attendance} onChange={(ev) => setForm({ ...form, attendance: ev.target.checked })} />
              Affects attendance — the campus is treated as shut on these days
            </label>
            <Field label="Description">
              <textarea className={`${inputCls} min-h-20`} maxLength={2000} value={form.description} onChange={(ev) => setForm({ ...form, description: ev.target.value })} />
            </Field>
            <div className="flex items-center gap-2 text-xs text-ink-soft">
              <span>Colour preview</span>
              <Status value={form.type} />
            </div>
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
