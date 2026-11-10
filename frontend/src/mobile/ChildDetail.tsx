import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import dayjs from 'dayjs'
import { useApi } from '../lib/hooks'
import { fmtDate, fmtPKR } from '../lib/types'
import { Screen } from './MobileShell'
import { Bar, ListSkeleton, Metric, Row, Segmented } from './shellParts'
import { useReloadOnRefresh } from './refresh'
import { Badge, Card, Empty, Notice, Status } from '../components/ui'
import { IconFees, IconReport } from '../components/icons'

interface WardRef {
  id: number; name: string; roll_no: string | null; class: string | null; section: string | null
  campus: { id: number; name: string; code: string } | null; class_teacher: string | null
}

const TABS = [
  { key: 'attendance', label: 'Attendance' },
  { key: 'gate', label: 'Gate' },
  { key: 'fees', label: 'Fees' },
  { key: 'results', label: 'Results' },
]

export default function ChildDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const wards = useApi<{ data: WardRef[] }>('/guardian/wards')
  useReloadOnRefresh(wards.reload)
  const [tab, setTab] = useState('attendance')

  const list = wards.data?.data ?? []
  const child = list.find((w) => String(w.id) === id) ?? list[0]

  return (
    <Screen error={wards.error} onRetry={wards.reload}>
      {wards.loading && !child && <ListSkeleton rows={2} />}
      {list.length > 1 && (
        <Segmented options={list.map((w) => ({ key: String(w.id), label: w.name.split(' ')[0] }))}
          value={String(child?.id ?? '')} onChange={(next) => nav(`/app/child/${next}`)} />
      )}
      {child && (
        <>
          <Card className="p-4">
            <p className="text-[17px] font-semibold text-slate-900">{child.name}</p>
            <p className="mt-0.5 text-xs text-ink-soft">
              {[child.class, child.section].filter(Boolean).join(' · ')}{child.campus ? ` · ${child.campus.name}` : ''}
            </p>
            {child.class_teacher && <p className="mt-1 text-[11px] text-ink-soft">Class teacher: {child.class_teacher}</p>}
            <div className="scroll-slim mt-3.5 flex gap-1.5 overflow-x-auto" role="tablist">
              {TABS.map((t) => (
                <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)}
                  className={`shrink-0 rounded-xl px-3.5 py-2 text-[12.5px] font-semibold transition ${
                    tab === t.key ? 'bg-brand-700 text-white shadow-sm' : 'border border-line-strong bg-white text-ink-soft'
                  }`}>{t.label}</button>
              ))}
            </div>
          </Card>
          {tab === 'attendance' && <AttendanceTab id={child.id} />}
          {tab === 'gate' && <GateTab id={child.id} />}
          {tab === 'fees' && <FeesTab id={child.id} />}
          {tab === 'results' && <ResultsTab id={child.id} />}
        </>
      )}
    </Screen>
  )
}

function AttendanceTab({ id }: { id: number }) {
  const months = Array.from({ length: 6 }, (_, i) => dayjs().subtract(i, 'month').format('YYYY-MM'))
  const [month, setMonth] = useState(months[0])
  const { data, loading, error, reload } = useApi<{ data: AttendanceMonth }>(`/guardian/wards/${id}/attendance?month=${month}`, [month])
  useReloadOnRefresh(reload)
  const a = data?.data

  return (
    <div className="space-y-4">
      <Segmented options={months.map((m) => ({ key: m, label: dayjs(`${m}-01`).format('MMM') }))} value={month} onChange={setMonth} />
      {error && <Card className="p-3"><Row label={error} /></Card>}
      {loading && <ListSkeleton rows={2} />}
      {a && (
        <>
          {!a.summary && <Card><Empty text={`Nothing marked for ${dayjs(`${month}-01`).format('MMMM')}.`} /></Card>}
          {a.summary && (
            <>
              <Card className="p-4">
                <div className="flex items-end justify-between gap-3">
                  <p className="nums text-[30px] font-semibold leading-none text-slate-900">{a.summary.percentage}%</p>
                  <p className="text-right text-[11px] text-ink-soft">
                    {a.summary.present} present of {a.summary.total} marked days<br />school warns below {a.threshold}%
                  </p>
                </div>
                <div className="mt-3"><Bar percent={a.summary.percentage} tone={a.summary.warning ? 'rose' : 'brand'} /></div>
                <div className="mt-3 grid grid-cols-4 gap-2">
                  <Metric label="Present" value={a.summary.present} tone="green" />
                  <Metric label="Absent" value={a.summary.absent} tone={a.summary.absent ? 'rose' : 'slate'} />
                  <Metric label="Late" value={a.summary.late} tone={a.summary.late ? 'amber' : 'slate'} />
                  <Metric label="Leave" value={a.summary.leave + a.summary.excused} />
                </div>
                {a.summary.warning && (
                  <Notice tone="rose" className="mt-3" title="Attendance is below the school's threshold">
                    Speak to the class teacher — {a.summary.absent} absent {a.summary.absent === 1 ? 'day' : 'days'} this month.
                  </Notice>
                )}
              </Card>

              {a.trend.length > 1 && (
                <Card className="p-4">
                  <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">Month by month</p>
                  <div className="flex items-end gap-2">
                    {a.trend.map((t) => (
                      <div key={t.month} className="min-w-0 flex-1 text-center">
                        <div className="mx-auto flex h-20 w-full items-end justify-center rounded-lg bg-canvas">
                          <div className={`w-3/5 rounded-t-md ${t.percentage < a.threshold ? 'bg-rose-400' : 'bg-brand-500'}`}
                            style={{ height: `${Math.max(4, t.percentage * 0.9)}%` }} title={`${t.percentage}%`} />
                        </div>
                        <p className="nums mt-1 text-[10px] font-semibold text-slate-700">{t.percentage}%</p>
                        <p className="text-[9.5px] uppercase text-slate-400">{dayjs(`${t.month}-01`).format('MMM')}</p>
                      </div>
                    ))}
                  </div>
                </Card>
              )}

              <Card className="divide-y divide-line/70">
                {a.days.map((d, i) => (
                  <div key={`${d.date}-${d.period_no}-${i}`} className="flex items-center gap-3 px-4 py-2.5">
                    <span className="nums w-16 shrink-0 text-[12px] font-semibold text-slate-700">{dayjs(d.date).format('D MMM')}</span>
                    <span className="w-7 shrink-0 text-[10.5px] text-slate-400">{d.period_no ? `P${d.period_no}` : 'day'}</span>
                    <span className="min-w-0 flex-1 truncate text-[11.5px] text-ink-soft">{d.remarks || ''}</span>
                    <Status value={d.status} dot />
                  </div>
                ))}
              </Card>
            </>
          )}
        </>
      )}
    </div>
  )
}

interface GateDays {
  ward: { id: number; name: string }
  days: Array<{
    date: string; state: string
    taps: Array<{ direction: string; time: string; gate: string | null; source: string; remarks: string | null }>
  }>
}

/** The gate timeline: when the desk tapped the child in and out, day by day. */
function GateTab({ id }: { id: number }) {
  const { data, loading, error, reload } = useApi<{ data: GateDays }>(`/guardian/wards/${id}/entries`)
  useReloadOnRefresh(reload)
  const g = data?.data

  return (
    <div className="space-y-4">
      {error && <Card className="p-3"><Row label={error} /></Card>}
      {loading && <ListSkeleton rows={3} />}
      {g && (
        <>
          {!g.days.length && (
            <Card><Empty text="No gate record yet."
              hint="The school front desk taps each child in and out. You also get a message here on the first arrival of the day." /></Card>
          )}
          {g.days.map((d) => (
            <Card key={d.date} className="p-4">
              <div className="flex items-baseline justify-between gap-3">
                <p className="nums text-[13.5px] font-semibold text-slate-900">{dayjs(d.date).format('dddd, D MMM')}</p>
                <Badge tone={d.state === 'inside' ? 'green' : 'slate'} dot>{d.state === 'inside' ? 'inside at close' : 'gone home'}</Badge>
              </div>
              <div className="mt-2.5 space-y-2">
                {d.taps.map((t, i) => (
                  <div key={`${t.time}-${i}`} className="flex items-start gap-2.5">
                    <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${t.direction === 'in' ? 'bg-brand-500' : 'bg-slate-300'}`} />
                    <p className="min-w-0 flex-1 text-[12.5px] text-slate-700">
                      <span className="font-semibold">{t.direction === 'in' ? 'Arrived' : 'Left'}</span>
                      <span className="nums"> · {t.time}</span>
                      <span className="text-ink-soft"> · {t.gate ?? 'front desk'}</span>
                      {t.source === 'roll_call' && <span className="text-ink-soft"> · from the class register</span>}
                      {t.remarks && <span className="mt-0.5 block text-[11.5px] italic text-ink-soft">“{t.remarks}”</span>}
                    </p>
                  </div>
                ))}
              </div>
            </Card>
          ))}
          {!!g.days.length && (
            <p className="px-1 text-[11px] text-slate-400">Only the first arrival and the first departure of a day send you a message.</p>
          )}
        </>
      )}
    </div>
  )
}

interface AttendanceMonth {
  month: string
  threshold: number
  summary: { present: number; absent: number; leave: number; excused: number; late: number; total: number; percentage: number; warning: boolean } | null
  days: Array<{ date: string; period_no: number; status: string; remarks: string | null }>
  trend: Array<{ month: string; present: number; absent: number; total: number; percentage: number }>
}

function FeesTab({ id }: { id: number }) {
  const { data, loading, error, reload } = useApi<{ data: FeesPayload }>(`/guardian/wards/${id}/fees`)
  useReloadOnRefresh(reload)
  const f = data?.data

  return (
    <div className="space-y-4">
      {error && <Card className="p-3"><Row label={error} /></Card>}
      {loading && <ListSkeleton rows={2} />}
      {f && (
        <>
          <Card className="p-4">
            <div className="flex items-end justify-between gap-3">
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">Payable now</p>
                <p className="nums mt-1 text-[26px] font-semibold leading-none text-slate-900">{fmtPKR(f.summary.balance)}</p>
              </div>
              {f.summary.overdue_balance > 0 && (
                <Badge tone="rose" dot>{fmtPKR(f.summary.overdue_balance)} overdue</Badge>
              )}
            </div>
            <div className="mt-3"><Bar percent={f.summary.billed ? (f.summary.paid / f.summary.billed) * 100 : 0}
              tone={f.summary.overdue_balance > 0 ? 'rose' : f.summary.balance > 0 ? 'gold' : 'brand'} /></div>
            <div className="mt-3 divide-y divide-line/70">
              <Row label="Billed" value={fmtPKR(f.summary.billed)} />
              <Row label="Paid" value={fmtPKR(f.summary.paid)} />
              {f.summary.waived > 0 && <Row label="Waived" value={fmtPKR(f.summary.waived)} />}
            </div>
            {f.summary.balance === 0 && <p className="mt-3 text-[11.5px] text-ink-soft">Nothing outstanding. Thank you.</p>}
          </Card>

          {f.invoices.length === 0 && <Card><Empty text="No fee bills have been raised for this child." /></Card>}
          {f.invoices.map((inv) => (
            <Card key={inv.id} className="p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-[14px] font-semibold text-slate-900">{inv.title}</p>
                  <p className="mt-0.5 text-[11px] text-ink-soft">
                    Due {fmtDate(inv.due_date)}{inv.session_month ? ` · ${inv.session_month}` : ''}
                  </p>
                </div>
                {inv.is_overdue
                  ? <Badge tone="rose" dot>overdue</Badge>
                  : <Status value={inv.status} />}
              </div>
              <div className="mt-2.5 grid grid-cols-3 gap-2">
                <Metric label="Total" value={fmtPKR(inv.total_due)} />
                <Metric label="Paid" value={fmtPKR(inv.paid)} tone="green" />
                <Metric label="Balance" value={fmtPKR(inv.balance)} tone={inv.balance > 0 ? 'amber' : 'slate'} />
              </div>
              {inv.payments.length > 0 && (
                <div className="mt-2.5 divide-y divide-line/70">
                  {inv.payments.map((p, i) => (
                    <Row key={`${p.receipt_no}-${i}`} label={<span className="flex items-center gap-1.5"><IconFees className="h-3.5 w-3.5 text-brand-500" />{p.receipt_no ?? 'Deposit'} · {p.channel ?? 'cash'}</span>}
                      value={`${fmtPKR(p.amount)} · ${fmtDate(p.paid_on)}`} />
                  ))}
                </div>
              )}
            </Card>
          ))}
        </>
      )}
    </div>
  )
}

interface FeesPayload {
  summary: { billed: number; paid: number; balance: number; overdue_balance: number; waived: number; currency: string }
  invoices: Array<{
    id: number; title: string; session_month: string | null; total_due: number; paid: number; balance: number
    due_date: string | null; status: string; is_overdue: boolean
    payments: Array<{ receipt_no: string | null; amount: number; channel: string | null; paid_on: string | null; status: string }>
  }>
}

function ResultsTab({ id }: { id: number }) {
  const { data, loading, error, reload } = useApi<{ data: { results: ExamResult[] } }>(`/guardian/wards/${id}/results`)
  useReloadOnRefresh(reload)
  const rows = data?.data.results ?? []

  return (
    <div className="space-y-4">
      {error && <Card className="p-3"><Row label={error} /></Card>}
      {loading && <ListSkeleton rows={2} />}
      {!loading && !rows.length && (
        <Card><Empty text="No results published yet." hint="Marks stay with the school until the exam is published." icon={<IconReport />} /></Card>
      )}
      {rows.map((r) => (
        <Card key={r.exam_id} className="p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-[14px] font-semibold text-slate-900">{r.exam}</p>
              <p className="mt-0.5 text-[11px] text-ink-soft">{fmtDate(r.start_date)} · {r.total_marks} of {r.max_marks} marks</p>
            </div>
            <div className="shrink-0 text-right">
              <p className="nums text-[22px] font-semibold leading-none text-brand-700">{r.grade}</p>
              <p className="nums mt-1 text-[11px] text-ink-soft">{r.percentage}%</p>
            </div>
          </div>
          <div className="mt-2.5 flex flex-wrap items-center gap-2">
            <Badge tone={r.result === 'Pass' ? 'green' : 'rose'} dot>{r.result}</Badge>
            {r.absent_count > 0 && <Badge tone="amber">{r.absent_count} absent</Badge>}
            {r.remark && <span className="text-[11.5px] text-ink-soft">{r.remark}</span>}
          </div>
          <div className="mt-3 divide-y divide-line/70">
            {r.subjects.map((s, i) => (
              <div key={`${s.subject}-${i}`} className="flex items-center gap-2.5 py-2">
                <span className="min-w-0 flex-1 truncate text-[13px] text-slate-700">{s.subject}</span>
                {s.is_absent
                  ? <span className="text-[11px] font-semibold text-slate-400">absent</span>
                  : <span className="nums text-[12.5px] font-semibold text-slate-800">{s.marks_obtained}/{s.max_marks}</span>}
                <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${s.passed ? 'bg-brand-500' : 'bg-rose-500'}`} />
              </div>
            ))}
          </div>
        </Card>
      ))}
    </div>
  )
}

interface ExamResult {
  exam_id: number; exam: string; type: string; start_date: string; percentage: number; grade: string
  remark: string | null; total_marks: number; max_marks: number; absent_count: number; result: string
  subjects: Array<{ subject: string; marks_obtained: number | null; max_marks: number; pass_marks: number; is_absent: boolean; passed: boolean }>
}
