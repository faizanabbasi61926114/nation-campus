import { useMemo, useRef, useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label, tone } from '../lib/labels'
import { IconCheck, IconLeave, IconPayroll, IconPlus, IconStaff, IconUsers } from '../components/icons'
import { Badge, Btn, Card, Empty, ErrorNote, Field, Modal, PageHeader, SearchInput, StatCard, Status, Table, Tabs, Td, Th, Toolbar, inputCls, selectCls } from '../components/ui'

interface StaffRow {
  id: number; employee_no: string; first_name: string; last_name: string; full_name?: string
  gender: string; email?: string | null; phone?: string | null; cnic?: string | null; joined_on: string
  base_salary: string | number; monthly_gross: number; status: string
  department_id: number | null; designation_id: number | null
  department?: { name: string } | null; designation?: { name: string; category?: string } | null
}
interface StaffDetail extends StaffRow {
  house_rent_percent: string; medical_allowance: string; other_deductions: string
  confirmed_on?: string | null; bank_account?: string | null; notes?: string | null
  leave_summary: { type: string; allowed: number; used: number; balance: number }[]
  recent_leaves: LeaveRow[]
}
interface LeaveRow {
  id: number; staff_id: number; type: string; from_date: string; to_date: string
  days: string; status: string; reason: string; decision_notes?: string | null
  staff?: { id: number; first_name: string; last_name: string; employee_no: string } | null
  decider?: { id: number; name: string } | null
}
interface Department { id: number; name: string; code: string; staff_count: number; head_user_id?: number | null; head?: { name: string } | null }
interface Designation {
  id: number; name: string; category: string; grade?: string | null
  min_salary?: string | number | null; max_salary?: string | number | null; department_id?: number | null
  department?: { name: string } | null
}

const DECISION_WORDS: Record<string, string> = { rejected: 'Rejected' }
const decisionWord = (v: string) => DECISION_WORDS[v] ?? label(v)
const LEAVE_TYPES = ['annual', 'casual', 'sick', 'earned', 'unpaid', 'media']

export default function Staff({ initial = 'directory' as 'directory' | 'leave' | 'setup' }) {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'staff.manage')
  const canHr = hasPerm(user, 'hr.manage')
  const canApprove = hasPerm(user, 'hr.leave_approve')
  const [tab, setTab] = useState<'directory' | 'leave' | 'setup'>(initial)

  const { data: summary, reload } = useApi<{ data: {
    headcount: number; active: number; probation: number; monthly_cost: number
    pending_leave: LeaveRow[]; on_leave_today: number; departments: Department[]
  } }>('/hr/summary')
  const stats = summary?.data

  return (
    <div>
      <PageHeader title="Human Resources" subtitle="Staff directory, leave approvals and organisation structure" icon={<IconStaff className="h-5 w-5" />} />

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Staff on roll" value={stats?.headcount ?? '—'} hint={`${stats?.active ?? 0} active · ${stats?.probation ?? 0} on probation`} icon={<IconUsers className="h-4 w-4" />} tone="blue" />
        <StatCard label="Monthly salary cost" value={fmtPKR(stats?.monthly_cost)} hint="Basic pay, before allowances" icon={<IconPayroll className="h-4 w-4" />} tone="gold" />
        <StatCard label="Leave awaiting decision" value={stats?.pending_leave?.length ?? '—'} hint="Applied by campus staff" icon={<IconLeave className="h-4 w-4" />} tone="amber" />
        <StatCard label="On leave today" value={stats?.on_leave_today ?? '—'} hint="Approved absence covering today" icon={<IconCheck className="h-4 w-4" />} tone="green" />
      </div>

      <Tabs active={tab} onChange={(key) => setTab(key as typeof tab)}
        tabs={[
          { key: 'directory', label: 'Staff directory' },
          { key: 'leave', label: 'Leave board' },
          { key: 'setup', label: 'Departments & grades' },
        ]} />

      {tab === 'directory' && <Directory canManage={canManage} onChanged={reload} />}
      {tab === 'leave' && <LeaveBoard canApply={canHr} canApprove={canApprove} onChanged={reload} />}
      {tab === 'setup' && <Setup canManage={canManage} />}
    </div>
  )
}

function Directory({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const query = `/staff?${new URLSearchParams(Object.entries({ status, search }).filter(([, v]) => v) as [string, string][])}`
  const { data, loading, error, reload } = useApi<{ data: Paginated<StaffRow> }>(query, [status, search])
  const [addOpen, setAddOpen] = useState(false)
  const [detail, setDetail] = useState<StaffDetail | null>(null)
  const [editStaff, setEditStaff] = useState<StaffDetail | null>(null)
  const rows = data?.data?.data ?? []
  const C = 8

  const open = async (id: number) => {
    try { setDetail((await api.get(`/staff/${id}`)).data.data) } catch { /* handled by the list */ }
  }

  return (
    <div>
      <Toolbar>
        <select className={`${selectCls} w-40`} value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Staff status">
          <option value="">All</option>
          {['active', 'probation', 'resigned', 'terminated'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <SearchInput className="w-56" value={search} onChange={setSearch} placeholder="Name or employee no" />
        {canManage && <Btn className="ml-auto" onClick={() => setAddOpen(true)} icon={<IconPlus className="h-4 w-4" />}>Add staff member</Btn>}
      </Toolbar>

      <Table headers={<><Th>Employee #</Th><Th>Name</Th><Th>Department</Th><Th>Designation</Th><Th>Joined</Th><Th align="right">Basic</Th><Th align="right">Gross / month</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((s) => (
          <tr key={s.id} className="cursor-pointer" onClick={() => open(s.id)}>
            <Td className="nums text-xs">{s.employee_no}</Td>
            <Td className="font-medium">{s.first_name} {s.last_name}<span className="ml-1 text-xs text-slate-400">{label(s.gender)}</span></Td>
            <Td>{s.department?.name ?? '—'}</Td>
            <Td>{s.designation?.name ?? '—'}</Td>
            <Td className="nums">{fmtDate(s.joined_on)}</Td>
            <Td className="nums text-right">{fmtPKR(s.base_salary)}</Td>
            <Td className="nums text-right font-semibold">{fmtPKR(s.monthly_gross)}</Td>
            <Td><Status value={s.status} /></Td>
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No staff match this filter." /></Td></tr>}
      </Table>
      <p className="nums mt-2 text-xs text-ink-soft">{data?.data?.total ?? 0} staff in this campus. Click a row for salary and leave history.</p>

      {addOpen && <StaffModal onClose={() => { setAddOpen(false); reload(); onChanged() }} />}
      {editStaff && <StaffModal staff={editStaff} onClose={() => { setEditStaff(null); reload(); onChanged() }} />}
      {detail && <StaffDetailModal staff={detail} onClose={() => setDetail(null)} onEdit={(record) => { setDetail(null); setEditStaff(record) }} canManage={canManage} />}
    </div>
  )
}

function StaffDetailModal({ staff, onClose, onEdit, canManage }: { staff: StaffDetail; onClose: () => void; onEdit: (staff: StaffDetail) => void; canManage: boolean }) {
  return (
    <Modal title={`${staff.first_name} ${staff.last_name}`}
      subtitle={`${staff.employee_no} · ${staff.designation?.name ?? 'Unassigned'} · ${staff.department?.name ?? 'No department'}`}
      onClose={onClose} size="lg"
      footer={<>
        {canManage && <Btn variant="ghost" onClick={() => onEdit(staff)}>Edit details</Btn>}
        <Btn variant="ghost" onClick={onClose}>Close</Btn>
      </>}>
      <div className="mb-3"><Status value={staff.status} /></div>

      <div className="mb-4 grid gap-3 text-sm sm:grid-cols-3">
        <Info label="Joined" value={fmtDate(staff.joined_on)} />
        <Info label="Confirmed" value={staff.confirmed_on ? fmtDate(staff.confirmed_on) : 'Pending'} />
        <Info label="Basic salary" value={fmtPKR(staff.base_salary)} />
        <Info label="House rent" value={`${Number(staff.house_rent_percent)}%`} />
        <Info label="Medical allowance" value={fmtPKR(staff.medical_allowance)} />
        <Info label="Other deductions" value={fmtPKR(staff.other_deductions)} />
        <Info label="Gross per month" value={fmtPKR(staff.monthly_gross)} />
        <Info label="Email" value={staff.email ?? '—'} />
        <Info label="Bank account" value={staff.bank_account ?? '—'} />
      </div>

      <h4 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Leave balance — calendar year</h4>
      <Table headers={<><Th>Type</Th><Th align="right">Allowed</Th><Th align="right">Used</Th><Th align="right">Balance</Th></>} cols={4}>
        {staff.leave_summary.map((l) => (
          <tr key={l.type}>
            <Td>{label(l.type)}</Td>
            <Td className="nums text-right">{l.allowed}</Td>
            <Td className="nums text-right">{l.used}</Td>
            <Td className={`nums text-right font-semibold ${l.balance < 0 ? 'text-rose-600' : 'text-emerald-700'}`}>{l.balance}</Td>
          </tr>
        ))}
        {!staff.leave_summary.length && <tr><Td colSpan={4}><Empty text="No entitlement configured." /></Td></tr>}
      </Table>

      <h4 className="mb-2 mt-4 text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Recent leave</h4>
      <Table headers={<><Th>Type</Th><Th>From</Th><Th>To</Th><Th align="right">Days</Th><Th>Status</Th></>} cols={5}>
        {staff.recent_leaves.map((l) => (
          <tr key={l.id}>
            <Td>{label(l.type)}</Td>
            <Td className="nums">{fmtDate(l.from_date)}</Td>
            <Td className="nums">{fmtDate(l.to_date)}</Td>
            <Td className="nums text-right">{Number(l.days)}</Td>
            <Td><Badge tone={tone(l.status)}>{decisionWord(l.status)}</Badge></Td>
          </tr>
        ))}
        {!staff.recent_leaves.length && <tr><Td colSpan={5}><Empty text="No leave recorded." /></Td></tr>}
      </Table>

      {staff.notes && <Card className="mt-3 p-3 text-sm text-ink-soft">{staff.notes}</Card>}
    </Modal>
  )
}

const Info = ({ label: caption, value }: { label: string; value: string }) => (
  <div><p className="text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">{caption}</p><p className="nums font-medium text-slate-800">{value}</p></div>
)

function StaffModal({ onClose, staff }: { onClose: () => void; staff?: StaffDetail }) {
  const formRef = useRef<HTMLFormElement>(null)
  const { data: departments } = useApi<{ data: Department[] }>('/departments')
  const { data: designations } = useApi<{ data: Designation[] }>('/designations')
  const [form, setForm] = useState({
    first_name: staff?.first_name ?? '', last_name: staff?.last_name ?? '', gender: staff?.gender ?? 'male',
    joined_on: staff?.joined_on ?? new Date().toISOString().slice(0, 10),
    base_salary: String(staff?.base_salary ?? ''), house_rent_percent: String(staff?.house_rent_percent ?? '30'),
    medical_allowance: String(staff?.medical_allowance ?? '0'), other_deductions: String(staff?.other_deductions ?? '0'),
    department_id: String(staff?.department_id ?? ''), designation_id: String(staff?.designation_id ?? ''),
    email: staff?.email ?? '', phone: staff?.phone ?? '', cnic: staff?.cnic ?? '', bank_account: staff?.bank_account ?? '',
    status: staff?.status ?? 'active', notes: staff?.notes ?? '',
    confirmed_on: staff?.confirmed_on ?? '', resigned_on: '',
  })
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const gross = useMemo(() => {
    const basic = Number(form.base_salary) || 0
    return basic + (basic * Number(form.house_rent_percent || 0)) / 100 + (Number(form.medical_allowance) || 0)
  }, [form.base_salary, form.house_rent_percent, form.medical_allowance])

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true); setErr('')
    try {
      const payload: Record<string, string> = { ...form }
      if (!staff) Object.keys(payload).forEach((k) => { if (payload[k] === '') delete payload[k] })
      if (staff) await api.put(`/staff/${staff.id}`, payload)
      else await api.post('/staff', payload)
      onClose()
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Modal title={staff ? `Edit ${staff.first_name} ${staff.last_name}` : 'Add staff member'}
      subtitle={staff ? `${staff.employee_no} · updates the payroll structure` : 'The employee number is assigned from the campus series'}
      onClose={onClose} size="md"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => formRef.current?.requestSubmit()}>{saving ? 'Saving…' : 'Create record'}</Btn>
      </>}>
      <form ref={formRef} onSubmit={submit} className="grid grid-cols-2 gap-3">
        <Field label="First name"><input className={inputCls} required value={form.first_name} onChange={set('first_name')} /></Field>
        <Field label="Last name"><input className={inputCls} required value={form.last_name} onChange={set('last_name')} /></Field>
        <Field label="Gender">
          <select className={selectCls} value={form.gender} onChange={set('gender')}>
            {['male', 'female', 'other'].map((g) => <option key={g} value={g}>{label(g)}</option>)}
          </select>
        </Field>
        <Field label="Status">
          <select className={selectCls} value={form.status} onChange={set('status')}>
            {['active', 'probation', 'resigned', 'terminated'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
        </Field>
        <Field label="Joined on"><input className={inputCls} type="date" required value={form.joined_on} onChange={set('joined_on')} /></Field>
        {staff && <Field label="Confirmed on"><input className={inputCls} type="date" value={form.confirmed_on} onChange={set('confirmed_on')} /></Field>}
        <Field label="Department">
          <select className={selectCls} value={form.department_id} onChange={set('department_id')}>
            <option value="">Unassigned</option>
            {(departments?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </Field>
        <Field label="Designation">
          <select className={selectCls} value={form.designation_id} onChange={set('designation_id')}>
            <option value="">Unassigned</option>
            {(designations?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </Field>
        <Field label="Basic salary (PKR)"><input className={inputCls} type="number" min="0" required value={form.base_salary} onChange={set('base_salary')} /></Field>
        <Field label="House rent %"><input className={inputCls} type="number" min="0" max="100" value={form.house_rent_percent} onChange={set('house_rent_percent')} /></Field>
        <Field label="Medical allowance"><input className={inputCls} type="number" min="0" value={form.medical_allowance} onChange={set('medical_allowance')} /></Field>
        <Field label="Other fixed deductions"><input className={inputCls} type="number" min="0" value={form.other_deductions} onChange={set('other_deductions')} /></Field>
        <Field label="CNIC"><input className={inputCls} placeholder="35202-1234567-1" value={form.cnic} onChange={set('cnic')} /></Field>
        <Field label="Email"><input className={inputCls} type="email" value={form.email} onChange={set('email')} /></Field>
        <Field label="Phone"><input className={inputCls} placeholder="+92 300 1234567" value={form.phone} onChange={set('phone')} /></Field>
        <Field label="Bank account"><input className={inputCls} value={form.bank_account} onChange={set('bank_account')} /></Field>
        <div className="col-span-2"><Field label="Notes"><textarea className={inputCls} rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field></div>
        <p className="nums col-span-2 text-sm text-ink-soft">Gross salary on this structure: <strong>{fmtPKR(gross)}</strong> per month.</p>
        {err && <div className="col-span-2"><ErrorNote text={err} /></div>}
      </form>
    </Modal>
  )
}

function LeaveBoard({ canApply, canApprove, onChanged }: { canApply: boolean; canApprove: boolean; onChanged: () => void }) {
  const [status, setStatus] = useState('pending')
  const { data, loading, error, reload } = useApi<{ data: LeaveRow[] }>(`/leave-applications?status=${status}`, [status])
  const [applyOpen, setApplyOpen] = useState(false)
  const [busy, setBusy] = useState(0)
  const [err, setErr] = useState('')
  const rows = data?.data ?? []
  const C = 8

  const decide = async (leave: LeaveRow, decision: 'approved' | 'rejected') => {
    setBusy(leave.id); setErr('')
    try {
      await api.post(`/leave-applications/${leave.id}/decide`, { decision, notes: decision === 'approved' ? 'Approved from the leave board.' : 'No cover available.' })
      reload(); onChanged()
    } catch (e) { setErr(apiError(e)) } finally { setBusy(0) }
  }

  return (
    <div>
      <Toolbar>
        <select className={`${selectCls} w-44`} value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Decision status">
          {['pending', 'approved', 'rejected', ''].map((s) => <option key={s} value={s}>{s ? decisionWord(s) : 'All decisions'}</option>)}
        </select>
        {canApply && <Btn className="ml-auto" onClick={() => setApplyOpen(true)} icon={<IconPlus className="h-4 w-4" />}>Apply for leave</Btn>}
      </Toolbar>
      {err && <div className="mb-3"><ErrorNote text={err} /></div>}

      <Table headers={<><Th>Employee</Th><Th>Type</Th><Th>From</Th><Th>To</Th><Th align="right">Days</Th><Th>Reason</Th><Th>Status</Th><Th /></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((l) => (
          <tr key={l.id}>
            <Td className="font-medium">
              {l.staff ? `${l.staff.first_name} ${l.staff.last_name}` : `#${l.staff_id}`}
              <span className="ml-1 nums text-xs text-slate-400">{l.staff?.employee_no}</span>
            </Td>
            <Td>{label(l.type)}</Td>
            <Td className="nums">{fmtDate(l.from_date)}</Td>
            <Td className="nums">{fmtDate(l.to_date)}</Td>
            <Td className="nums text-right">{Number(l.days)}</Td>
            <Td className="max-w-xs truncate text-ink-soft" >{l.reason}</Td>
            <Td>
              <Badge tone={tone(l.status)}>{decisionWord(l.status)}</Badge>
              {l.decider && <p className="mt-1 text-xs text-slate-400">by {l.decider.name}</p>}
            </Td>
            <Td className="text-right whitespace-nowrap">
              {canApprove && l.status === 'pending' && (
                <>
                  <Btn size="sm" variant="ghost" disabled={busy === l.id} onClick={() => decide(l, 'approved')}>Approve</Btn>
                  <Btn size="sm" variant="ghost" disabled={busy === l.id} onClick={() => decide(l, 'rejected')}>Reject</Btn>
                </>
              )}
            </Td>
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No leave applications for this filter." /></Td></tr>}
      </Table>

      {applyOpen && <LeaveModal onClose={() => { setApplyOpen(false); reload(); onChanged() }} />}
    </div>
  )
}

function LeaveModal({ onClose }: { onClose: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const { data } = useApi<{ data: { data: StaffRow[] } }>('/staff?status=active&per_page=100')
  const [form, setForm] = useState({ staff_id: '', type: 'casual', from_date: new Date().toISOString().slice(0, 10), to_date: '', reason: '' })
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true); setErr('')
    try {
      await api.post('/leave-applications', { ...form, to_date: form.to_date || form.from_date })
      onClose()
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Modal title="Apply for leave" subtitle="Days are counted excluding the campus week off"
      onClose={onClose} size="md"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => formRef.current?.requestSubmit()}>{saving ? 'Submitting…' : 'Submit application'}</Btn>
      </>}>
      <form ref={formRef} onSubmit={submit} className="space-y-3">
        <Field label="Staff member">
          <select className={selectCls} required value={form.staff_id} onChange={set('staff_id')}>
            <option value="">Select…</option>
            {(data?.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.first_name} {s.last_name} — {s.employee_no}</option>)}
          </select>
        </Field>
        <Field label="Leave type">
          <select className={selectCls} value={form.type} onChange={set('type')}>
            {LEAVE_TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="From"><input className={inputCls} type="date" required value={form.from_date} onChange={set('from_date')} /></Field>
          <Field label="To"><input className={inputCls} type="date" value={form.to_date} onChange={set('to_date')} /></Field>
        </div>
        <Field label="Reason"><input className={inputCls} required maxLength={255} value={form.reason} onChange={set('reason')} /></Field>
        <p className="text-xs text-ink-soft">Unpaid leave reduces the monthly payslip.</p>
        {err && <ErrorNote text={err} />}
      </form>
    </Modal>
  )
}

function Setup({ canManage }: { canManage: boolean }) {
  const { data: departments, loading: loadingDepts, error: errorDepts, reload: reloadDepts } = useApi<{ data: Department[] }>('/departments')
  const { data: designations, loading: loadingDsg, error: errorDsg, reload: reloadDsg } = useApi<{ data: Designation[] }>('/designations')
  const { data: staffOptions } = useApi<{ data: { data: { full_name: string; user_id: number | null }[] } }>('/staff?per_page=100&status=active')
  const [dept, setDept] = useState<{ id?: number; name: string; code: string; head_user_id: string }>({ name: '', code: '', head_user_id: '' })
  const [dsg, setDsg] = useState<{ id?: number; name: string; category: string; grade: string; department_id: string; min_salary: string; max_salary: string }>({ name: '', category: 'teaching', grade: '', department_id: '', min_salary: '', max_salary: '' })
  const [err, setErr] = useState('')

  const createDept = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    try {
      const payload = { name: dept.name, code: dept.code, head_user_id: dept.head_user_id ? Number(dept.head_user_id) : null }
      if (dept.id) await api.put(`/departments/${dept.id}`, payload)
      else await api.post('/departments', payload)
      setDept({ name: '', code: '', head_user_id: '' }); reloadDepts()
    } catch (e) { setErr(apiError(e)) }
  }
  const createDsg = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    try {
      const payload = {
        name: dsg.name, category: dsg.category, grade: dsg.grade || null,
        department_id: dsg.department_id ? Number(dsg.department_id) : null,
        min_salary: dsg.min_salary ? Number(dsg.min_salary) : null,
        max_salary: dsg.max_salary ? Number(dsg.max_salary) : null,
      }
      if (dsg.id) await api.put(`/designations/${dsg.id}`, payload)
      else await api.post('/designations', payload)
      setDsg({ name: '', category: 'teaching', grade: '', department_id: '', min_salary: '', max_salary: '' }); reloadDsg()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <div>
        <h3 className="mb-2 text-sm font-semibold text-slate-700">Departments</h3>
        <Table headers={<><Th>Code</Th><Th>Name</Th><Th>Head</Th><Th align="right">Staff</Th>{canManage && <Th>Actions</Th>}</>}
          cols={canManage ? 5 : 4} loading={loadingDepts} error={errorDepts} onRetry={reloadDepts}>
          {(departments?.data ?? []).map((d) => (
            <tr key={d.id}>
              <Td className="nums text-xs">{d.code}</Td>
              <Td className="font-medium">{d.name}</Td>
              <Td>{d.head?.name ?? '—'}</Td>
              <Td className="nums text-right">{d.staff_count}</Td>
              {canManage && <Td><Btn size="sm" variant="ghost" onClick={() => setDept({ id: d.id, name: d.name, code: d.code, head_user_id: String(d.head_user_id ?? '') })}>Edit</Btn></Td>}
            </tr>
          ))}
          {!loadingDepts && !errorDepts && !departments?.data?.length && <tr><Td colSpan={canManage ? 5 : 4}><Empty text="No departments yet." /></Td></tr>}
        </Table>
        {canManage && (
          <form onSubmit={createDept} className="mt-3 flex flex-wrap items-end gap-2">
            <Field label="Name"><input className={`${inputCls} w-44`} required value={dept.name} onChange={(e) => setDept({ ...dept, name: e.target.value })} /></Field>
            <Field label="Code"><input className={`${inputCls} w-24 uppercase`} required maxLength={20} value={dept.code} onChange={(e) => setDept({ ...dept, code: e.target.value.toUpperCase() })} /></Field>
            <Field label="Department head">
              <select className={`${selectCls} w-48`} value={dept.head_user_id} onChange={(e) => setDept({ ...dept, head_user_id: e.target.value })}>
                <option value="">Unassigned</option>
                {(staffOptions?.data.data ?? []).filter((person) => person.user_id).map((person) => <option key={person.user_id} value={person.user_id!}>{person.full_name}</option>)}
              </select>
            </Field>
            <Btn type="submit">{dept.id ? 'Save department' : 'Add department'}</Btn>
            {dept.id && <Btn variant="ghost" onClick={() => setDept({ name: '', code: '', head_user_id: '' })}>Cancel edit</Btn>}
          </form>
        )}
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold text-slate-700">Designations &amp; grades</h3>
        <Table cols={canManage ? 6 : 5} loading={loadingDsg} error={errorDsg} onRetry={reloadDsg}
          headers={<><Th>Designation</Th><Th>Category</Th><Th>Department</Th><Th>Grade</Th><Th align="right">Scale</Th>{canManage && <Th>Actions</Th>}</>}>
          {(designations?.data ?? []).map((d) => (
            <tr key={d.id}>
              <Td className="font-medium">{d.name}</Td>
              <Td>{label(d.category)}</Td>
              <Td>{d.department?.name ?? '—'}</Td>
              <Td className="nums text-xs">{d.grade ?? '—'}</Td>
              <Td className="nums text-right text-xs">{d.min_salary ? `${fmtPKR(d.min_salary)} – ${fmtPKR(d.max_salary)}` : '—'}</Td>
              {canManage && <Td><Btn size="sm" variant="ghost" onClick={() => setDsg({
                id: d.id, name: d.name, category: d.category, grade: d.grade ?? '',
                department_id: String(d.department_id ?? ''),
                min_salary: String(d.min_salary ?? ''), max_salary: String(d.max_salary ?? ''),
              })}>Edit</Btn></Td>}
            </tr>
          ))}
          {!loadingDsg && !errorDsg && !designations?.data?.length && <tr><Td colSpan={canManage ? 6 : 5}><Empty text="No designations yet." /></Td></tr>}
        </Table>
        {canManage && (
          <form onSubmit={createDsg} className="mt-3 grid grid-cols-2 gap-2">
            <Field label="Designation"><input className={inputCls} required value={dsg.name} onChange={(e) => setDsg({ ...dsg, name: e.target.value })} /></Field>
            <Field label="Category">
              <select className={selectCls} value={dsg.category} onChange={(e) => setDsg({ ...dsg, category: e.target.value })}>
                {['teaching', 'administrative', 'support', 'senior'].map((c) => <option key={c} value={c}>{label(c)}</option>)}
              </select>
            </Field>
            <Field label="Department">
              <select className={selectCls} value={dsg.department_id} onChange={(e) => setDsg({ ...dsg, department_id: e.target.value })}>
                <option value="">None</option>
                {(departments?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </Field>
            <Field label="Grade"><input className={inputCls} placeholder="BPS-16" value={dsg.grade} onChange={(e) => setDsg({ ...dsg, grade: e.target.value })} /></Field>
            <Field label="Min salary"><input className={inputCls} type="number" min="0" value={dsg.min_salary} onChange={(e) => setDsg({ ...dsg, min_salary: e.target.value })} /></Field>
            <Field label="Max salary"><input className={inputCls} type="number" min="0" value={dsg.max_salary} onChange={(e) => setDsg({ ...dsg, max_salary: e.target.value })} /></Field>
            <div className="col-span-2 flex gap-2">
              <Btn type="submit">{dsg.id ? 'Save designation' : 'Add designation'}</Btn>
              {dsg.id && <Btn variant="ghost" onClick={() => setDsg({ name: '', category: 'teaching', grade: '', department_id: '', min_salary: '', max_salary: '' })}>Cancel edit</Btn>}
            </div>
          </form>
        )}
      </div>
      {err && <div className="lg:col-span-2"><ErrorNote text={err} /></div>}
    </div>
  )
}
