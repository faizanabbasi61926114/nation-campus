import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { todayISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../store/toasts'
import { IconAlert, IconDiscipline, IconPlus, IconRoles, IconStudents, IconCheck } from '../components/icons'
import {
  Badge, Btn, Card, DataList, Empty, ErrorNote, Field, Modal, Notice, PageHeader, SearchInput, StatCard, Status,
  Table, Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

const CATEGORIES = ['uniform', 'punctuality', 'bullying', 'conduct', 'damage', 'attendance', 'other']
const SEVERITIES = ['minor', 'moderate', 'major']
const ACTIONS = ['none', 'counselled', 'verbal_warning', 'parent_called', 'detention', 'suspension']
const CLOSING_ACTIONS = ACTIONS.filter((a) => a !== 'none')

interface StudentLite {
  id: number; first_name: string; last_name: string; admission_no: string
  school_class?: { name: string } | null; section?: { name: string } | null
}

interface DisciplineCase {
  id: number; student_id: number; incident_on: string; category: string; severity: string
  description: string; action: string; suspension_days: number; parent_notified: boolean
  status: string; remarks?: string | null; created_at: string
  student?: StudentLite | null
  handler?: { name: string } | null
  campus?: { name: string; code: string } | null
}

interface DiscSummary {
  open: number; closed: number; closed_this_month: number; major_open: number
  by_severity: Record<string, number>
  top_students: { student_id: number; name: string; admission_no?: string | null; class?: string | null; open_cases: number }[]
}

interface Draft {
  id?: number
  student_id: string
  incident_on: string
  category: string
  severity: string
  description: string
  action: string
  suspension_days: string
  parent_notified: boolean
  remarks: string
}

interface CloseDraft { id: number; action: string; suspension_days: string; parent_notified: boolean; remarks: string }

const fullName = (s?: StudentLite | null) => (s ? `${s.first_name} ${s.last_name}`.trim() : '—')

export default function Discipline() {
  const { user, campusId } = useAuth()
  const canManage = hasPerm(user, 'discipline.manage')

  const [status, setStatus] = useState('')
  const [severity, setSeverity] = useState('')
  const [classId, setClassId] = useState('')
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<Draft | null>(null)
  const [closing, setClosing] = useState<CloseDraft | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(0)

  const query = '/discipline/cases?per_page=100'
    + `${status ? `&status=${status}` : ''}${severity ? `&severity=${severity}` : ''}`
    + `${classId ? `&class_id=${classId}` : ''}${search ? `&search=${encodeURIComponent(search)}` : ''}`
  const { data, loading, error, reload } = useApi<{ data: Paginated<DisciplineCase> }>(query, [status, severity, classId, search])
  const { data: summaryData, reload: reloadSummary } = useApi<{ data: DiscSummary }>('/discipline/summary')
  const { data: studentData } = useApi<{ data: StudentLite[] }>('/students?per_page=100&status=active')
  const { data: classData } = useApi<{ data: { id: number; name: string }[] }>('/classes')

  const rows = data?.data.data ?? []
  const stats = summaryData?.data
  const students = studentData?.data ?? []
  const classes = classData?.data ?? []
  const repeat = (stats?.top_students ?? []).filter((s) => s.open_cases > 1)
  const C = canManage ? 8 : 7

  const refresh = () => { reload(); reloadSummary() }

  function newDraft() {
    setErr('')
    setForm({
      student_id: '', incident_on: todayISO(), category: 'conduct', severity: 'minor', description: '',
      action: 'none', suspension_days: '0', parent_notified: false, remarks: '',
    })
  }

  function editDraft(r: DisciplineCase) {
    setErr('')
    setForm({
      id: r.id, student_id: String(r.student_id), incident_on: r.incident_on, category: r.category,
      severity: r.severity, description: r.description, action: r.action,
      suspension_days: String(r.suspension_days ?? 0), parent_notified: !!r.parent_notified, remarks: r.remarks ?? '',
    })
  }

  async function save() {
    if (!form) return
    if (!form.student_id) { setErr('Choose the student this incident is recorded against.'); return }
    if (!form.description.trim()) { setErr('Describe what happened.'); return }
    if (form.action === 'suspension' && Number(form.suspension_days) < 1) {
      setErr('A suspension must say how many days the student is suspended for.')
      return
    }
    setErr('')
    const payload = {
      student_id: Number(form.student_id),
      incident_on: form.incident_on,
      category: form.category,
      severity: form.severity,
      description: form.description.trim(),
      action: form.action,
      suspension_days: form.action === 'suspension' ? Number(form.suspension_days) : 0,
      parent_notified: form.parent_notified,
      remarks: form.remarks || null,
    }
    try {
      if (form.id) await api.put(`/discipline/cases/${form.id}`, payload)
      else await api.post('/discipline/cases', payload)
      toast.success(form.id ? 'Case updated.' : 'Case opened — the student is now on the discipline register.')
      setForm(null)
      refresh()
    } catch (e) { setErr(apiError(e)) }
  }

  function openClose(r: DisciplineCase) {
    setErr('')
    setClosing({
      id: r.id, action: r.action === 'none' ? 'counselled' : r.action,
      suspension_days: String(r.suspension_days ?? 0), parent_notified: !!r.parent_notified, remarks: r.remarks ?? '',
    })
  }

  async function closeCase() {
    if (!closing) return
    if (!closing.remarks.trim()) { setErr('Closing a case needs the remarks that record what was decided.'); return }
    if (closing.action === 'suspension' && Number(closing.suspension_days) < 1) {
      setErr('A suspension must say how many days the student is suspended for.')
      return
    }
    setErr('')
    setBusy(closing.id)
    try {
      const r = await api.post(`/discipline/cases/${closing.id}/close`, {
        action: closing.action,
        remarks: closing.remarks.trim(),
        suspension_days: closing.action === 'suspension' ? Number(closing.suspension_days) : 0,
        parent_notified: closing.parent_notified,
      })
      toast.success(r.data.message ?? 'Case closed.')
      setClosing(null)
      refresh()
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  return (
    <div>
      <PageHeader title="Discipline" subtitle="Incidents, the action taken and case closure for the active campus"
        icon={<IconDiscipline className="h-5 w-5" />}
        actions={canManage && <Btn onClick={newDraft} disabled={!campusId} icon={<IconPlus className="h-4 w-4" />}>New case</Btn>} />

      {!campusId && <Notice tone="amber" title="Select a campus" className="mb-4">Cases belong to one campus and one student — pick a campus from the header first.</Notice>}
      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Open cases" value={stats?.open ?? '—'} hint="Awaiting an action or closure" icon={<IconAlert className="h-4 w-4" />} tone="amber" />
        <StatCard label="Major, still open" value={stats?.major_open ?? '—'} hint={`${stats?.by_severity?.moderate ?? 0} moderate · ${stats?.by_severity?.minor ?? 0} minor`} icon={<IconDiscipline className="h-4 w-4" />} tone="rose" />
        <StatCard label="Closed this month" value={stats?.closed_this_month ?? '—'} hint={`${stats?.closed ?? 0} closed in total`} icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="Repeat students" value={repeat.length} hint="More than one open case" icon={<IconStudents className="h-4 w-4" />} tone="violet" />
      </div>

      <div className="grid gap-4 lg:grid-cols-[3fr_1fr]">
        <div>
          <Toolbar>
            <SearchInput className="w-64 max-w-full" value={search} onChange={setSearch} placeholder="Search student, admission no, description…" />
            <div className="w-36">
              <select className={selectCls} aria-label="Case status" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">All statuses</option>
                {['open', 'closed'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
              </select>
            </div>
            <div className="w-36">
              <select className={selectCls} aria-label="Severity" value={severity} onChange={(e) => setSeverity(e.target.value)}>
                <option value="">All severity</option>
                {SEVERITIES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
              </select>
            </div>
            <div className="w-40">
              <select className={selectCls} aria-label="Class" value={classId} onChange={(e) => setClassId(e.target.value)}>
                <option value="">All classes</option>
                {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
          </Toolbar>

          <Table headers={<>
            <Th>Student</Th><Th>Incident</Th><Th>Category</Th><Th>Severity</Th><Th>Action</Th><Th>Status</Th><Th>Handled by</Th>
            {canManage && <Th align="right">Actions</Th>}
          </>} cols={C} loading={loading} error={error} onRetry={reload}>
            {rows.map((r) => (
              <tr key={r.id}>
                <Td className="font-medium">
                  {fullName(r.student)}
                  <p className="text-xs text-slate-400">
                    {r.student?.admission_no ?? '—'}
                    {r.student?.school_class?.name ? ` · ${r.student.school_class.name}` : ''}
                    {r.student?.section?.name ? ` · ${r.student.section.name}` : ''}
                  </p>
                </Td>
                <Td className="nums whitespace-nowrap text-xs">{fmtDate(r.incident_on)}</Td>
                <Td className="whitespace-nowrap">{label(r.category)}</Td>
                <Td><Status value={r.severity} dot /></Td>
                <Td className="whitespace-nowrap">
                  <span className="flex flex-wrap items-center gap-1">
                    {label(r.action)}
                    {r.action === 'suspension' && <span className="nums text-xs text-slate-400">{r.suspension_days} d</span>}
                  </span>
                  {r.parent_notified && <p className="text-xs text-slate-400">guardian notified</p>}
                </Td>
                <Td><Status value={r.status} /></Td>
                <Td className="text-xs">{r.handler?.name ?? '—'}</Td>
                {canManage && (
                  <Td className="text-right whitespace-nowrap">
                    <div className="flex flex-wrap justify-end gap-1">
                      {r.status === 'open' && <>
                        <Btn size="sm" variant="ghost" onClick={() => editDraft(r)}>Edit</Btn>
                        <Btn size="sm" disabled={busy === r.id} onClick={() => openClose(r)}>Close case</Btn>
                      </>}
                      {r.status === 'closed' && <Badge tone="slate">Record kept</Badge>}
                    </div>
                  </Td>
                )}
              </tr>
            ))}
            {!rows.length && !loading && !error && (
              <tr><Td colSpan={C}><Empty text={status || severity ? 'No cases match these filters.' : 'The discipline register is clean.'}
                hint={canManage ? 'Record an incident with New case when a student is written up.' : 'Cases recorded at your campus appear here.'} /></Td></tr>
            )}
          </Table>
        </div>

        <aside className="space-y-4">
          <Card className="p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><IconRoles className="h-4 w-4 text-brand-600" /> Most frequent</h2>
            {stats?.top_students?.length ? (
              <ul className="mt-3 space-y-2.5">
                {stats.top_students.map((s, i) => (
                  <li key={s.student_id} className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-medium text-slate-800">{i + 1}. {s.name}</p>
                      <p className="nums text-xs text-ink-soft">{s.admission_no ?? '—'}{s.class ? ` · ${s.class}` : ''}</p>
                    </div>
                    <Badge tone={s.open_cases > 1 ? 'rose' : 'slate'}>{s.open_cases} open</Badge>
                  </li>
                ))}
              </ul>
            ) : <p className="mt-3 text-sm text-ink-soft">No open cases, so no student appears more than once.</p>}
          </Card>

          {rows[0] && (
            <Card className="p-4">
              <h2 className="text-sm font-semibold text-slate-800">Latest case</h2>
              <DataList rows={[
                ['Student', fullName(rows[0].student)],
                ['Incident', fmtDate(rows[0].incident_on)],
                ['Category', label(rows[0].category)],
                ['Severity', label(rows[0].severity)],
                ['Action', label(rows[0].action)],
                ['Status', label(rows[0].status)],
                ['Handler', rows[0].handler?.name ?? '—'],
              ]} />
              <p className="mt-3 text-sm leading-6 text-slate-600">{rows[0].description}</p>
              {rows[0].remarks && <p className="mt-2 text-xs text-ink-soft">Remarks: {rows[0].remarks}</p>}
            </Card>
          )}
        </aside>
      </div>

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? 'Edit discipline case' : 'New discipline case'}
          subtitle="What happened, how serious it was and what was done"
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={() => void save()}>{form.id ? 'Save changes' : 'Open case'}</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Student">
              <select className={selectCls} value={form.student_id} disabled={!!form.id} onChange={(e) => setForm({ ...form, student_id: e.target.value })}>
                <option value="">Choose a student…</option>
                {students.map((s) => (
                  <option key={s.id} value={s.id}>
                    {fullName(s)} · {s.admission_no}{s.school_class?.name ? ` · ${s.school_class.name}` : ''}
                  </option>
                ))}
              </select>
            </Field>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Date">
                <input type="date" className={inputCls} value={form.incident_on} onChange={(e) => setForm({ ...form, incident_on: e.target.value })} />
              </Field>
              <Field label="Category">
                <select className={selectCls} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                  {CATEGORIES.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                </select>
              </Field>
              <Field label="Severity">
                <select className={selectCls} value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}>
                  {SEVERITIES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </select>
              </Field>
            </div>
            <Field label="What happened">
              <textarea className={`${inputCls} min-h-24`} maxLength={2000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Action taken">
                <select className={selectCls} value={form.action} onChange={(e) => setForm({ ...form, action: e.target.value, suspension_days: e.target.value === 'suspension' ? form.suspension_days || '1' : '0' })}>
                  {ACTIONS.map((a) => <option key={a} value={a}>{label(a)}</option>)}
                </select>
              </Field>
              {form.action === 'suspension' ? (
                <Field label="Suspension days" hint="Must be at least one day">
                  <input className={inputCls} inputMode="numeric" value={form.suspension_days} onChange={(e) => setForm({ ...form, suspension_days: e.target.value })} />
                </Field>
              ) : (
                <Field label="Remarks">
                  <input className={inputCls} value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} />
                </Field>
              )}
            </div>
            {form.action === 'suspension' && (
              <Field label="Remarks">
                <input className={inputCls} value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} />
              </Field>
            )}
            <label className="flex items-center gap-2 rounded-xl border border-line bg-canvas/60 px-3 py-2.5 text-sm text-slate-700">
              <input type="checkbox" className="accent-brand-600" checked={form.parent_notified} onChange={(e) => setForm({ ...form, parent_notified: e.target.checked })} />
              Guardian called or notified in writing
            </label>
            <p className="text-xs text-ink-soft">A case can only be opened against a student of the campus you are working in.</p>
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}

      {closing && (
        <Modal size="sm" onClose={() => setClosing(null)} title="Close this case"
          subtitle="The action and the remarks become the final record"
          footer={<>
            <Btn variant="ghost" onClick={() => setClosing(null)}>Cancel</Btn>
            <Btn onClick={() => void closeCase()} disabled={busy === closing.id}>Close case</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Action taken">
              <select className={selectCls} value={closing.action} onChange={(e) => setClosing({ ...closing, action: e.target.value, suspension_days: e.target.value === 'suspension' ? closing.suspension_days || '1' : '0' })}>
                {CLOSING_ACTIONS.map((a) => <option key={a} value={a}>{label(a)}</option>)}
              </select>
            </Field>
            {closing.action === 'suspension' && (
              <Field label="Suspension days" hint="Must be at least one day">
                <input className={inputCls} inputMode="numeric" value={closing.suspension_days} onChange={(e) => setClosing({ ...closing, suspension_days: e.target.value })} />
              </Field>
            )}
            <Field label="Remarks" hint="Required — what was decided and why">
              <textarea className={`${inputCls} min-h-20`} maxLength={2000} value={closing.remarks} onChange={(e) => setClosing({ ...closing, remarks: e.target.value })} />
            </Field>
            <label className="flex items-center gap-2 rounded-xl border border-line bg-canvas/60 px-3 py-2.5 text-sm text-slate-700">
              <input type="checkbox" className="accent-brand-600" checked={closing.parent_notified} onChange={(e) => setClosing({ ...closing, parent_notified: e.target.checked })} />
              Guardian notified
            </label>
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
