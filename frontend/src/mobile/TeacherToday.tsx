import { Link, useNavigate } from 'react-router-dom'
import { useApi } from '../lib/hooks'
import { fmtDate } from '../lib/types'
import { Screen } from './MobileShell'
import { Bar, ListSkeleton } from './shellParts'
import { useReloadOnRefresh } from './refresh'
import { Badge, Btn, Card, Empty, Status } from '../components/ui'
import { IconAttendance, IconChevronRight, IconExams, IconTimetable } from '../components/icons'

interface MyClass {
  date: string
  teacher: { id: number; name: string }
  sections: Array<{
    id: number; name: string; class: string | null
    campus: { id: number; name: string; code: string } | null
    students: number; attendance_marked_today: number; attendance_present_today: number; attendance_complete: boolean
  }>
  periods: Array<{
    slot_id: number; period_no: number; section_id: number; section: string; subject: string | null
    start_time: string; end_time: string; room: string | null
    students: number; attendance_marked: number; attendance_complete: boolean
  }>
  exams: Array<{ id: number; name: string; type: string; start_date: string; end_date: string | null; status: string }>
}

export default function TeacherToday() {
  const { data, loading, error, reload } = useApi<{ data: MyClass }>('/teacher/my-class')
  useReloadOnRefresh(reload)
  const mine = data?.data
  const nav = useNavigate()

  if (loading) return <Screen><ListSkeleton rows={4} /></Screen>

  const openRoll = (sectionId: number, periodNo = 0) => nav(`/app/attendance?section=${sectionId}&period=${periodNo}`)

  return (
    <Screen heading="Today" sub={mine ? `${fmtDate(mine.date)} · ${mine.teacher.name}` : undefined} error={error} onRetry={reload}>
      <section className="space-y-3">
        <h3 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">My classes</h3>
        {!mine?.sections.length && (
          <Card><Empty text="You are not the class teacher of any section." hint="Ask your principal to set you as class teacher to see a roll here." /></Card>
        )}
        {mine?.sections.map((s) => (
          <Card key={s.id} className="p-4">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate text-[15px] font-semibold text-slate-900">{s.class ?? 'Class'} {s.name}</p>
                <p className="mt-0.5 text-xs text-ink-soft">{s.students} students{s.campus ? ` · ${s.campus.code}` : ''}</p>
              </div>
              {s.attendance_complete
                ? <Badge tone="green" dot>roll done</Badge>
                : <Badge tone={s.attendance_marked_today ? 'amber' : 'slate'}>{s.attendance_marked_today}/{s.students}</Badge>}
            </div>
            <div className="mt-3"><Bar percent={s.students ? (s.attendance_marked_today / s.students) * 100 : 0}
              tone={s.attendance_complete ? 'brand' : s.attendance_marked_today ? 'gold' : 'rose'} /></div>
            <div className="mt-3 flex items-center justify-between gap-2">
              <p className="text-[11px] text-ink-soft">{s.attendance_present_today} present today</p>
              <Btn size="sm" variant={s.attendance_complete ? 'ghost' : 'primary'} icon={<IconAttendance className="h-3.5 w-3.5" />}
                onClick={() => openRoll(s.id)}>
                {s.attendance_complete ? 'Review' : 'Take roll'}
              </Btn>
            </div>
          </Card>
        ))}
      </section>

      <section className="space-y-3">
        <h3 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">My periods today</h3>
        {!mine?.periods.length && (
          <Card><Empty text="No timetable slots for today." hint="Periods appear here once the campus timetable has a slot for you on this weekday." /></Card>
        )}
        <Card className="divide-y divide-line/70">
          {mine?.periods.map((p) => (
            <button key={p.slot_id} type="button" onClick={() => openRoll(p.section_id, p.period_no)}
              className="flex w-full items-center gap-3 px-4 py-3 text-left transition active:bg-brand-50/60">
              <span className="w-11 shrink-0">
                <span className="nums block text-[13px] font-semibold text-slate-800">{p.start_time}</span>
                <span className="block text-[10px] text-slate-400">P{p.period_no}</span>
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[14px] font-semibold text-slate-900">{p.subject ?? 'Free period'}</span>
                <span className="mt-0.5 block truncate text-[11px] text-ink-soft">
                  {p.section}{p.room ? ` · ${p.room}` : ''} · {p.attendance_marked}/{p.students} marked
                </span>
              </span>
              {p.attendance_complete && <IconAttendance className="h-4 w-4 shrink-0 text-brand-600" />}
              <IconChevronRight className="h-4 w-4 shrink-0 text-slate-300" />
            </button>
          ))}
        </Card>
      </section>

      <section className="space-y-3">
        <h3 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">Papers you can still enter</h3>
        {!mine?.exams.length && (
          <Card><Empty text="Nothing awaiting marks." hint="Scheduled, ongoing and completed exams appear here until results are published." /></Card>
        )}
        <Card className="divide-y divide-line/70">
          {mine?.exams.map((e) => (
            <Link key={e.id} to={`/app/marks?exam=${e.id}`} className="flex items-center gap-3 px-4 py-3 transition active:bg-brand-50/60">
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100"><IconExams className="h-4 w-4" /></span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[14px] font-semibold text-slate-900">{e.name}</span>
                <span className="block truncate text-[11px] text-ink-soft">{fmtDate(e.start_date)}</span>
              </span>
              <Status value={e.status} />
              <IconChevronRight className="h-4 w-4 shrink-0 text-slate-300" />
            </Link>
          ))}
        </Card>
      </section>

      <p className="flex items-center justify-center gap-1.5 pt-1 text-[11px] text-slate-400">
        <IconTimetable className="h-3.5 w-3.5" />Full timetable, reports and records stay on the desktop workspace.
      </p>
    </Screen>
  )
}
