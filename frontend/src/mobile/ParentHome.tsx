import { Link } from 'react-router-dom'
import { useApi } from '../lib/hooks'
import { fmtDate, fmtPKR } from '../lib/types'
import { useAuth } from '../store/auth'
import { Screen } from './MobileShell'
import { Bar, ListSkeleton, Metric, useReloadOnRefresh } from './shellParts'
import { Badge, Card, Empty } from '../components/ui'
import { IconAttendance, IconChevronRight, IconReport } from '../components/icons'
import { mobileAudience } from './roles'
import TeacherToday from './TeacherToday'

interface Ward {
  id: number
  name: string
  roll_no: string | null
  admission_no: string | null
  status: string
  campus: { id: number; name: string; code: string } | null
  class: string | null
  section: string | null
  class_teacher: string | null
  attendance: { percentage: number; present: number; absent: number; total: number } | null
  today: string | null
  fees: { balance: number; overdue_invoices: number; alert: boolean }
  latest_result: { exam_id: number; exam: string; start_date: string; percentage: number; grade: string } | null
}

const DAY_TONE: Record<string, 'green' | 'amber' | 'rose' | 'blue' | 'slate'> = {
  present: 'green', late: 'amber', absent: 'rose', leave: 'blue', excused: 'slate',
}

/** One route, two audiences: a family sees its children, staff see today's teaching. */
export default function MobileHome() {
  const { user } = useAuth()
  return mobileAudience(user) === 'parent' ? <ParentHome /> : <TeacherToday />
}

function ParentHome() {
  const { user } = useAuth()
  const { data, loading, error, reload } = useApi<{ data: Ward[] }>('/guardian/wards')
  useReloadOnRefresh(reload)
  const wards = data?.data ?? []

  return (
    <Screen heading={`Hello, ${user?.name?.split(' ')[0] ?? ''}`}
      sub={wards.length ? `${wards.length} child${wards.length === 1 ? '' : 'ren'} enrolled` : undefined}
      error={error} onRetry={reload}>
      {loading && <ListSkeleton />}
      {!loading && !wards.length && (
        <Card><Empty text="No children are linked to this account yet."
          hint="The school office links a family account to an admission record. Ask them if this looks wrong." /></Card>
      )}
      <div className="space-y-3.5">
        {wards.map((w) => <WardCard key={w.id} ward={w} />)}
      </div>
    </Screen>
  )
}

function WardCard({ ward: w }: { ward: Ward }) {
  const rate = w.attendance?.percentage ?? null
  const tone = rate === null ? 'slate' : rate >= 90 ? 'green' : rate >= 75 ? 'amber' : 'rose'
  return (
    <Card interactive className="overflow-hidden">
      <Link to={`/app/child/${w.id}`} className="block px-4 py-4">
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <p className="truncate text-[15px] font-semibold text-slate-900">{w.name}</p>
            <p className="mt-0.5 truncate text-xs text-ink-soft">
              {[w.class, w.section].filter(Boolean).join(' · ') || 'Class not set'}
              {w.campus && <span className="text-slate-400"> — {w.campus.name}</span>}
            </p>
          </div>
          {w.today
            ? <Badge tone={DAY_TONE[w.today] ?? 'slate'} dot>{w.today}</Badge>
            : <Badge tone="slate">not marked</Badge>}
          <IconChevronRight className="mt-1 h-4 w-4 shrink-0 text-slate-300" />
        </div>

        <div className="mt-3.5 grid grid-cols-3 gap-2">
          <Metric label="Attendance" value={rate === null ? '—' : `${rate}%`}
            sub={`${w.attendance?.present ?? 0}/${w.attendance?.total ?? 0} days`} tone={tone} />
          <Metric label="Fee balance" value={fmtPKR(w.fees.balance)}
            sub={w.fees.alert ? `${w.fees.overdue_invoices} overdue` : 'nothing overdue'} tone={w.fees.alert ? 'rose' : 'slate'} />
          <Metric label="Last result" value={w.latest_result?.grade ?? '—'}
            sub={w.latest_result ? w.latest_result.exam : 'not published'} tone="blue" />
        </div>

        {rate !== null && <div className="mt-3"><Bar percent={rate} tone={tone === 'amber' ? 'amber' : tone === 'rose' ? 'rose' : 'brand'} /></div>}

        {w.latest_result && (
          <p className="mt-2.5 flex items-center gap-1.5 text-[11px] text-ink-soft">
            <IconReport className="h-3.5 w-3.5" />{w.latest_result.percentage}% · {fmtDate(w.latest_result.start_date)}
          </p>
        )}
        {!w.today && (
          <p className="mt-2 flex items-center gap-1.5 text-[11px] text-slate-400">
            <IconAttendance className="h-3.5 w-3.5" />Today's attendance has not been marked yet.
          </p>
        )}
        {w.class_teacher && <p className="mt-1 text-[11px] text-ink-soft">Class teacher: {w.class_teacher}</p>}
      </Link>
    </Card>
  )
}
