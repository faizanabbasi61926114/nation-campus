import { Link } from 'react-router-dom'
import { useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { IconArrowRight, IconReport } from '../components/icons'
import { Card, Empty, Notice, PageHeader } from '../components/ui'

const reports = [
  { title: 'Campus performance', description: 'Student enrolment, attendance, admissions and campus comparisons.', to: '/', permission: 'dashboard.view', label: 'Open dashboard' },
  { title: 'Student records', description: 'Review student status, class placement and guardian details.', to: '/students', permission: 'students.view', label: 'View students' },
  { title: 'Admissions pipeline', description: 'Track applications and review each admission stage.', to: '/admissions', permission: 'admissions.view', label: 'View admissions' },
  { title: 'Attendance', description: 'Review attendance registers and student attendance trends.', to: '/attendance', permission: 'attendance.view', label: 'View attendance' },
  { title: 'Examination results', description: 'Review examination schedules, marks and published results.', to: '/exams', permission: 'exams.view', label: 'View examinations' },
  { title: 'Financial statements', description: 'Trial balance, profit and loss, balance sheet and account ledgers.', to: '/accounts', permission: 'accounts.view', label: 'Open accounts' },
  { title: 'Payroll', description: 'Review payroll runs and staff compensation.', to: '/payroll', permission: 'payroll.view', label: 'View payroll' },
]

export default function Reports() {
  const { user } = useAuth()
  const available = reports.filter((report) => hasPerm(user, report.permission))

  return (
    <div>
      <PageHeader title="Reports" subtitle="Read-only summaries generated from your operational records" icon={<IconReport className="h-5 w-5" />} />
      <Notice tone="blue" title="Live records only" className="mb-5">
        Reports reflect the current records in each module. To correct information, edit the source record in its module; audit history and calculated reports are intentionally not editable.
      </Notice>
      <div className="grid gap-4 md:grid-cols-2">
        {available.map((report) => (
          <Card key={report.title} interactive className="flex flex-col p-5">
            <h2 className="font-semibold text-slate-900">{report.title}</h2>
            <p className="mt-2 flex-1 text-sm leading-6 text-ink-soft">{report.description}</p>
            <Link to={report.to} className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 transition hover:gap-2.5 hover:text-brand-800">
              {report.label}<IconArrowRight className="h-4 w-4" />
            </Link>
          </Card>
        ))}
        {!available.length && (
          <Card className="p-2">
            <Empty icon={<IconReport className="h-4 w-4" />} text="You do not have access to any reports." hint="Ask an administrator to grant a reporting permission for your campus." />
          </Card>
        )}
      </div>
    </div>
  )
}
