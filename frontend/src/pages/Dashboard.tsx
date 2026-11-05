import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { Badge, Card, DataList, Empty, ErrorNote, PageHeader, Skeleton, StatCard, Status, Td, Th } from '../components/ui'
import { fmtDate, fmtDateTime, fmtPKR } from '../lib/types'
import { label } from '../lib/labels'
import {
  IconAccounts, IconAdmissions, IconArrowRight, IconAttendance, IconAudit, IconChevronRight, IconDashboard,
  IconExams, IconFees, IconMessages, IconPayroll, IconRefresh, IconStaff, IconStudents,
} from '../components/icons'

interface Alert { level: 'critical' | 'warning' | 'info'; module: string; text: string; link: string | null }

interface CampusRow {
  campus_id: number; name: string; code: string
  students: number | null; staff: number | null; admissions_pending: number | null
  attendance_marks_today: number | null; attendance_percent: number | null
  fees_outstanding: number | null; fees_collected_month: number | null
}

interface Sections {
  students?: {
    active: number; admitted_this_month: number; awaiting_class: number
    graduated: number; withdrawn: number; by_gender: Record<string, number>
  }
  admissions?: {
    awaiting_review: number; awaiting_family: number; awaiting_enrolment: number
    enrolled_this_month: number; closed_this_month: number; by_status: Record<string, number>
  }
  attendance?: {
    date: string; students_expected: number; students_marked: number; unmarked: number
    present: number; absent: number; late: number; approved: number; present_percent: number | null
    trend: { date: string; present_percent: number | null }[]
    at_risk: {
      threshold: number; count: number
      students: { student_id: number; name: string; roll_no: string; days: number; present_percent: number }[]
    }
    staff: { marked: number; present: number; on_leave: number } | null
  }
  exams?: {
    upcoming: { id: number; name: string; type: string; start_date: string; end_date: string; status: string }[]
    awaiting_results: number; published: number; draft: number
  }
  fees?: {
    currency: string; outstanding: number; outstanding_invoices: number; overdue_invoices: number
    collected_this_month: number; receipts_this_month: number; waived_this_month: number
    waivers_pending: number; collected_trend: number[]; by_status: Record<string, number>
  }
  hr?: {
    headcount: number; active: number; probation: number; joined_this_month: number
    monthly_cost: number; leave_pending: number; on_leave_today: number
  }
  payroll?: {
    current: { id: number; month: string; status: string; gross: number; net: number } | null
    awaiting_approval: number; paid_this_month: number
  }
  accounts?: { income_this_month: number; expense_this_month: number; net_this_month: number; vouchers_draft: number }
  communication?: {
    unread_messages: number; threads_inbox: number; announcements_live: number
    announcements_published_week: number; announcements_draft: number
    deliveries_last_week: { channel: string; status: string; count: number }[]
  }
  audit?: {
    recent: { id: number; action: string; description: string; created_at: string; user: { id: number; name: string } | null }[]
  }
}

interface Dashboard {
  generated_at: string
  scope: { organization_id: number; campus: { id: number; name: string; code: string } | null; cross_campus: boolean }
  viewer: { name: string; roles: string[]; campuses: string[] }
  sections: Sections
  alerts: Alert[]
  campus_breakdown: CampusRow[] | null
}

const ALERT_STYLE = {
  critical: { rail: 'bg-rose-500', chip: 'rose' as const, label: 'Act now' },
  warning: { rail: 'bg-amber-400', chip: 'amber' as const, label: 'Watch' },
  info: { rail: 'bg-sky-400', chip: 'blue' as const, label: 'FYI' },
}

/** Percentage bars for the last few days; null days are drawn as an empty column. */
function Bars({ points, threshold }: { points: { date: string; present_percent: number | null }[]; threshold: number }) {
  return (
    <div className="relative h-32">
      <div className="absolute inset-x-0 top-[12%] border-t border-dashed border-brand-300/60" title={`${threshold}% threshold`} />
      <div className="flex h-full items-end gap-1.5">
        {points.map((p) => {
          const pct = p.present_percent
          const h = pct === null ? 0 : Math.max(4, (pct / 100) * 100)
          const colour = pct === null
            ? 'bg-slate-100'
            : pct >= 90 ? 'bg-gradient-to-t from-brand-600 to-brand-400'
              : pct >= threshold ? 'bg-gradient-to-t from-brand-500 to-brand-300'
                : 'bg-gradient-to-t from-amber-500 to-amber-300'
          return (
            <div key={p.date} className="group flex flex-1 flex-col items-center gap-1.5">
              <div className="flex w-full flex-1 items-end">
                <div className={`w-full rounded-t-md transition-all duration-300 group-hover:brightness-110 ${colour}`}
                  style={{ height: `${h}%` }}
                  title={`${fmtDate(p.date)} — ${pct === null ? 'not marked' : `${pct}% present`}`} />
              </div>
              <span className="nums text-[10px] text-slate-400">{new Date(p.date).getDate()}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

function MoneyBars({ values }: { values: number[] }) {
  const max = Math.max(...values, 1)
  return (
    <div className="flex h-32 items-end gap-2">
      {values.map((v, i) => (
        <div key={i} className="group flex flex-1 flex-col items-center gap-1.5">
          <div className="flex w-full flex-1 items-end">
            <div className={`w-full rounded-t-md transition-all duration-300 group-hover:brightness-110 ${v > 0 ? 'bg-gradient-to-t from-gold-500 to-gold-300' : 'bg-slate-100'}`}
              style={{ height: `${Math.max(3, (v / max) * 100)}%` }} title={fmtPKR(v)} />
          </div>
          <span className="text-[10px] text-slate-400">{i === values.length - 1 ? 'this mo' : `-${values.length - 1 - i} mo`}</span>
        </div>
      ))}
    </div>
  )
}

function Section({ title, icon, to, action, children, span = 1 }: {
  title: string; icon?: ReactNode; to?: string; action?: ReactNode; children: ReactNode; span?: 1 | 2
}) {
  return (
    <Card className={`flex flex-col p-5 sm:p-6 ${span === 2 ? 'lg:col-span-2' : ''}`}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2 className="flex items-center gap-2.5 text-[15px] font-semibold text-slate-900">
          {icon && <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 p-1.5 text-brand-600 ring-1 ring-brand-100">{icon}</span>}
          {title}
        </h2>
        {action}
        {to && (
          <Link to={to} className="group inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 transition hover:bg-brand-50">
            Open<IconChevronRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
          </Link>
        )}
      </div>
      <div className="min-w-0 flex-1">{children}</div>
    </Card>
  )
}

function Kpi({ label, value, hint, to, icon, tone }: {
  label: string; value: ReactNode; hint?: string; to: string; icon: ReactNode; tone: 'green' | 'amber' | 'blue' | 'violet' | 'gold' | 'slate'
}) {
  return (
    <Link to={to} className="block rounded-2xl focus-visible:outline-offset-4">
      <StatCard label={label} value={value} hint={hint} icon={icon} tone={tone} />
    </Link>
  )
}

export default function Dashboard() {
  const { user } = useAuth()
  const { data, error, loading, reload } = useApi<{ data: Dashboard }>('/dashboard')
  const d = data?.data

  const greeting = `Assalam-o-Alaikum, ${user?.name?.split(' ')[0] ?? ''}`
  const subtitle = d
    ? `${d.scope.campus ? d.scope.campus.name : 'All campuses'} · ${d.viewer.roles.map((r) => label(r)).join(', ')}`
    : 'Loading your dashboard…'

  if (error) {
    return (
      <div>
        <PageHeader title="Dashboard" subtitle="Could not load the dashboard" icon={<IconDashboard />} />
        <ErrorNote text={error} onRetry={reload} />
      </div>
    )
  }

  if (loading || !d) {
    return (
      <div>
        <PageHeader title={greeting} subtitle={subtitle} />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Card key={i} className="p-5">
              <Skeleton className="h-3 w-24" />
              <Skeleton className="mt-3 h-7 w-20" />
              <Skeleton className="mt-3 h-3 w-32" />
            </Card>
          ))}
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-3">
          <Card className="p-5 lg:col-span-2"><Skeleton className="h-40 w-full" /></Card>
          <Card className="p-5"><Skeleton className="h-40 w-full" /></Card>
        </div>
      </div>
    )
  }

  const s = d.sections
  const kpis: ReactNode[] = []
  if (s.students) kpis.push(
    <Kpi key="students" label="Active students" value={s.students.active} icon={<IconStudents />} tone="green"
      hint={`${s.students.admitted_this_month} admitted this month`} to="/students" />)
  if (s.attendance) kpis.push(
    <Kpi key="attendance" label="Attendance today" icon={<IconAttendance />}
      value={s.attendance.present_percent === null ? 'Not marked yet' : `${s.attendance.present_percent}%`} tone="blue"
      hint={`${s.attendance.students_marked} of ${s.attendance.students_expected} marked`} to="/attendance" />)
  if (s.fees) kpis.push(
    <Kpi key="outstanding" label="Outstanding fees" value={fmtPKR(s.fees.outstanding)} icon={<IconFees />} tone="amber"
      hint={`${s.fees.overdue_invoices} overdue invoice(s)`} to="/fees" />)
  if (s.fees) kpis.push(
    <Kpi key="collected" label="Collected this month" value={fmtPKR(s.fees.collected_this_month)} icon={<IconAccounts />} tone="green"
      hint={`${s.fees.receipts_this_month} receipt(s)`} to="/fees" />)
  if (s.admissions) kpis.push(
    <Kpi key="admissions" label="Admissions to process" icon={<IconAdmissions />} tone="violet"
      value={s.admissions.awaiting_review + s.admissions.awaiting_enrolment}
      hint={`${s.admissions.awaiting_family} offer(s) with the family`} to="/admissions" />)
  if (s.hr) kpis.push(
    <Kpi key="hr" label="Staff on role" value={s.hr.active} icon={<IconStaff />} tone="blue"
      hint={`${s.hr.leave_pending} leave request(s) pending`} to="/staff" />)
  if (s.communication) kpis.push(
    <Kpi key="messages" label="Unread messages" value={s.communication.unread_messages} icon={<IconMessages />} tone="gold"
      hint={`${s.communication.announcements_live} live notice(s)`} to="/messages" />)
  if (s.payroll?.current) kpis.push(
    <Kpi key="payroll" label={`Payroll ${fmtDate(s.payroll.current.month)}`} value={fmtPKR(s.payroll.current.net)} icon={<IconPayroll />} tone="slate"
      hint={label(s.payroll.current.status)} to="/payroll" />)

  return (
    <div>
      <PageHeader
        title={greeting}
        subtitle={subtitle}
        icon={<IconDashboard />}
        actions={
          <>
            <button onClick={reload} title="Refresh figures"
              className="inline-flex items-center gap-1.5 rounded-xl border border-line-strong bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-card transition hover:border-brand-300 hover:text-brand-700">
              <IconRefresh className="h-3.5 w-3.5" />Refresh
            </button>
            <Badge tone={d.scope.campus ? 'blue' : 'green'} dot>{d.scope.campus ? d.scope.campus.code : 'Head office — all campuses'}</Badge>
          </>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {kpis}
      </div>

      <div className="mt-5 grid gap-4 lg:grid-cols-3 lg:[grid-auto-flow:row_dense]">
        <Section title="Needs attention" span={2} icon={<IconAudit />}
          action={<span className="nums rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{d.alerts.length}</span>}>
          {d.alerts.length === 0
            ? <Empty text="Nothing needs an action right now." hint="Attendance is marked, invoices are current and no approvals are waiting." icon={<IconAttendance />} />
            : (
              <ul className="space-y-2">
                {d.alerts.map((a, i) => {
                  const st = ALERT_STYLE[a.level]
                  return (
                    <li key={i} className="group relative overflow-hidden rounded-xl border border-line bg-white pr-3 pl-4 transition hover:border-brand-200 hover:shadow-card">
                      <span className={`absolute inset-y-0 left-0 w-1 ${st.rail}`} />
                      <div className="flex items-start gap-3 py-3">
                        <div className="min-w-0 flex-1">
                          <p className="text-sm text-slate-700">{a.text}</p>
                          <p className="mt-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-soft/70">{label(a.module)}</p>
                        </div>
                        {a.link && (
                          <Link to={a.link}
                            className="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-lg bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 transition group-hover:bg-brand-100">
                            Open<IconArrowRight className="h-3 w-3" />
                          </Link>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
        </Section>

        {s.attendance && (
          <Section title="Attendance, last 14 days" to="/attendance" icon={<IconAttendance />}>
            <Bars points={s.attendance.trend} threshold={s.attendance.at_risk.threshold} />
            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-soft">
              <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-brand-500" />good</span>
              <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-amber-400" />below {s.attendance.at_risk.threshold}%</span>
              <span className="ml-auto">{s.attendance.at_risk.count} pupil(s) at risk</span>
            </div>
          </Section>
        )}

        {d.campus_breakdown && (
          <Section title="Campus performance" span={2} icon={<IconStudents />}
            action={<span className="text-xs text-ink-soft">{d.campus_breakdown.length} active</span>}>
            <div className="scroll-slim -mx-2 overflow-x-auto px-2">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-line">
                    <Th className="pl-2">Campus</Th>
                    {s.students && <Th align="right">Students</Th>}
                    {s.attendance && <Th align="right">Today</Th>}
                    {s.fees && <Th align="right">Outstanding</Th>}
                    {s.fees && <Th align="right" className="pr-2">Collected</Th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-line/70">
                  {d.campus_breakdown.map((c) => (
                    <tr key={c.campus_id} className="transition-colors hover:bg-brand-50/40">
                      <Td className="pl-2">
                        <span className="mr-2 inline-flex rounded-md bg-brand-50 px-1.5 py-0.5 text-[11px] font-bold text-brand-700 ring-1 ring-brand-100">{c.code}</span>
                        <span className="text-slate-700">{c.name}</span>
                      </Td>
                      {s.students && <Td className="nums text-right font-medium">{c.students ?? '—'}</Td>}
                      {s.attendance && (
                        <Td className="nums">
                          {c.attendance_percent === null
                            ? <span className="text-slate-400">—</span>
                            : (
                              <span className="inline-flex items-center gap-2">
                                <span className="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100">
                                  <span className={`block h-full rounded-full ${c.attendance_percent >= 75 ? 'bg-brand-500' : 'bg-amber-400'}`}
                                    style={{ width: `${Math.min(100, c.attendance_percent)}%` }} />
                                </span>
                                <span className="text-slate-700">{c.attendance_percent}%</span>
                              </span>
                            )}
                        </Td>
                      )}
                      {s.fees && <Td className="nums text-right text-slate-700">{fmtPKR(c.fees_outstanding)}</Td>}
                      {s.fees && <Td className="nums pr-2 text-right text-brand-700">{fmtPKR(c.fees_collected_month)}</Td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Section>
        )}

        {s.fees && (
          <Section title="Fee collection, last 6 months" to="/fees" icon={<IconFees />}>
            <MoneyBars values={s.fees.collected_trend} />
            <p className="mt-3 text-xs text-ink-soft">
              {s.fees.outstanding_invoices} open invoice(s) · {fmtPKR(s.fees.outstanding)} still due
              {s.fees.waivers_pending > 0 && <> · {s.fees.waivers_pending} waiver request(s) pending</>}
            </p>
          </Section>
        )}

        {s.attendance && s.attendance.at_risk.count > 0 && (
          <Section title="Below the attendance threshold" to="/attendance" icon={<IconAttendance />}>
            <ul className="divide-y divide-line/70 text-sm">
              {s.attendance.at_risk.students.slice(0, 6).map((r) => (
                <li key={r.student_id} className="flex items-center justify-between gap-3 py-2">
                  <span className="truncate text-slate-700">{r.name} <span className="text-xs text-slate-400">#{r.roll_no}</span></span>
                  <span className="nums shrink-0 text-right text-rose-600">{r.present_percent}%<span className="ml-1 text-xs text-slate-400">of {r.days}d</span></span>
                </li>
              ))}
            </ul>
          </Section>
        )}

        {s.exams && (
          <Section title="Examinations" to="/exams" icon={<IconExams />}>
            {s.exams.upcoming.length === 0
              ? <Empty text="No exam scheduled." />
              : (
                <ul className="divide-y divide-line/70 text-sm">
                  {s.exams.upcoming.map((e) => (
                    <li key={e.id} className="flex items-center justify-between gap-3 py-2">
                      <span className="truncate text-slate-700">{e.name}</span>
                      <span className="shrink-0 text-xs text-ink-soft">{fmtDate(e.start_date)}</span>
                    </li>
                  ))}
                </ul>
              )}
            <p className="mt-3 text-xs text-ink-soft">{s.exams.awaiting_results} awaiting results · {s.exams.published} published</p>
          </Section>
        )}

        {(s.accounts || s.payroll) && (
          <Section title="Finance" to="/accounts" icon={<IconAccounts />}>
            <DataList rows={[
              ...(s.accounts ? ([
                ['Income this month', fmtPKR(s.accounts.income_this_month)],
                ['Expense this month', fmtPKR(s.accounts.expense_this_month)],
                ['Net', <span className={s.accounts.net_this_month < 0 ? 'text-rose-600' : 'text-brand-700'}>{fmtPKR(s.accounts.net_this_month)}</span>],
              ] as Array<[string, ReactNode]>) : []),
              ...(s.payroll?.current ? ([[`Payroll ${fmtDate(s.payroll.current.month)}`, <><Status value={s.payroll.current.status} /> · {fmtPKR(s.payroll.current.net)}</>]] as Array<[string, ReactNode]>) : []),
            ]} />
          </Section>
        )}

        {s.communication && (
          <Section title="Communication" to="/announcements" icon={<IconMessages />}>
            <DataList rows={[
              ['Unread messages', s.communication.unread_messages],
              ['Live notices', s.communication.announcements_live],
              ['Published this week', s.communication.announcements_published_week],
              ...(s.communication.announcements_draft > 0 ? ([['Drafts', s.communication.announcements_draft]] as Array<[string, ReactNode]>) : []),
            ]} />
            {s.communication.deliveries_last_week.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-1.5 border-t border-line/70 pt-3">
                {s.communication.deliveries_last_week.map((dl, i) => (
                  <Badge key={i} tone={dl.status === 'sent' ? 'green' : dl.status === 'skipped' ? 'amber' : 'rose'} dot>
                    {label(dl.channel)} {label(dl.status)}: {dl.count}
                  </Badge>
                ))}
              </div>
            )}
          </Section>
        )}

        {s.audit && (
          <Section title="Recent activity" to="/audit" icon={<IconAudit />}>
            <ul className="space-y-3 text-sm">
              {s.audit.recent.map((r) => (
                <li key={r.id} className="relative pl-4">
                  <span className="absolute left-0 top-2 h-1.5 w-1.5 rounded-full bg-brand-300 ring-3 ring-brand-50" />
                  <p className="truncate text-slate-700">{r.description}</p>
                  <p className="mt-0.5 text-xs text-ink-soft/80">{r.user?.name ?? 'System'} · {fmtDateTime(r.created_at)}</p>
                </li>
              ))}
            </ul>
          </Section>
        )}

        {!s.students && !s.attendance && !s.fees && (
          <Section title="Overview" icon={<IconMessages />}>
            <Empty text="Your role has access to the notice board only." hint="Open Announcements or Messages from the menu." icon={<IconMessages />} />
          </Section>
        )}
      </div>

      <p className="mt-6 text-xs text-ink-soft/80">Figures are for {d.scope.campus ? `the ${d.scope.campus.name} campus` : 'all campuses'} as at {d.generated_at}.</p>
    </div>
  )
}
