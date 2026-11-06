import { useRef, useState } from 'react'
import { api, apiError } from '../lib/api'
import { monthISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label, tone } from '../lib/labels'
import { IconAlert, IconCheck, IconFees, IconPlus, IconRefresh } from '../components/icons'
import { Badge, Btn, Card, Empty, ErrorNote, Field, Modal, Notice, PageHeader, StatCard, Table, Tabs, Td, Th, Toolbar, inputCls, selectCls } from '../components/ui'

interface Invoice {
  id: number; student_id: number; title: string; amount: number; gst_amount: number
  discount: number; late_fee: number; status: string; issue_date: string; due_date: string
  session_month: string; balance: number
  student?: { id: number; first_name: string; last_name: string; roll_no?: string | null } | null
}
interface Structure {
  id: number; name: string; category: string; amount: number; gst_percent: number
  frequency: string; due_day: number; is_active: boolean; class_id?: number | null
  program_id?: number | null; academic_year_id?: number | null; class?: { name: string } | null
}
interface Waiver {
  id: number; percent: number | null; amount: number | null; reason: string; status: string
  student?: { first_name: string; last_name: string; roll_no?: string } | null
  invoice?: { id: number; title: string; amount: number } | null
}
interface OutstandingRow {
  student_id: number; name: string; roll_no?: string | null; invoices: number; overdue: number; outstanding: number
}

const CHANNELS = ['cash', 'bank_transfer', 'jazzcash', 'easypaisa', 'cheque']
const FEE_WORDS: Record<string, string> = { unpaid: 'Unpaid', rejected: 'Rejected' }
const feeWord = (v: string) => FEE_WORDS[v] ?? label(v)
const FeeStatus = ({ value }: { value: string }) => <Badge tone={tone(value)}>{feeWord(value)}</Badge>

export default function Fees() {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'fees.manage')
  const canRecord = hasPerm(user, 'fees.payments_record')
  const canApprove = hasPerm(user, 'fees.waivers_approve')
  const [tab, setTab] = useState<'invoices' | 'outstanding' | 'waivers' | 'structures'>('invoices')

  const [status, setStatus] = useState('')
  const [month, setMonth] = useState('')
  const query = `/fee-invoices?${new URLSearchParams(Object.entries({ status, session_month: month }).filter(([, v]) => v) as [string, string][])}`
  const { data: ledger, loading, error, reload } = useApi<{ data: Paginated<Invoice>; summary: { billed: number; collected: number; outstanding: number } }>(query, [status, month])
  const [payFor, setPayFor] = useState<Invoice | null>(null)
  const [generateOpen, setGenerateOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')

  const rows = ledger?.data?.data ?? []
  const summary = ledger?.summary

  const applyLateFees = async () => {
    setBusy(true)
    try {
      setNotice((await api.post('/fees/apply-late-fees')).data.message)
      reload()
    } catch (e) { setNotice(apiError(e)) } finally { setBusy(false) }
  }

  const C = 9

  return (
    <div>
      <PageHeader title="Fee Management" subtitle="Invoicing, collections, concessions and outstanding balances" icon={<IconFees className="h-5 w-5" />}
        actions={canManage && (
          <>
            <Btn variant="ghost" onClick={applyLateFees} disabled={busy} icon={<IconRefresh className="h-4 w-4" />}>Apply late fees</Btn>
            <Btn onClick={() => setGenerateOpen(true)} icon={<IconPlus className="h-4 w-4" />}>Generate invoices</Btn>
          </>
        )} />

      {!localStorage.getItem('cms_campus') && !user?.is_head_office && (
        <div className="mb-4"><Notice tone="amber">Select a campus to collect fees.</Notice></div>
      )}
      {notice && <div className="mb-4"><Notice tone="green">{notice}</Notice></div>}

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        <StatCard label="Billed" value={fmtPKR(summary?.billed)} hint="Gross invoiced including GST and late fees" icon={<IconFees className="h-4 w-4" />} tone="blue" />
        <StatCard label="Collected" value={fmtPKR(summary?.collected)} hint="Cleared payments" icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="Outstanding" value={fmtPKR(summary?.outstanding)} hint="Recoverable balance" icon={<IconAlert className="h-4 w-4" />} tone="rose" />
      </div>

      <Tabs active={tab} onChange={(key) => setTab(key as typeof tab)}
        tabs={[
          { key: 'invoices', label: 'Invoice ledger' },
          { key: 'outstanding', label: 'Outstanding' },
          { key: 'waivers', label: 'Waivers' },
          { key: 'structures', label: 'Structures' },
        ]} />

      {tab === 'invoices' && (
        <div>
          <Toolbar>
            <select className={`${selectCls} w-40`} value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All statuses</option>
              {['unpaid', 'partial', 'paid', 'overdue', 'waived', 'cancelled'].map((s) => <option key={s} value={s}>{feeWord(s)}</option>)}
            </select>
            <input className={`${inputCls} w-36`} placeholder="Session 2026-10" value={month} onChange={(e) => setMonth(e.target.value)} />
          </Toolbar>
          <Table headers={<><Th>Student</Th><Th>Head</Th><Th>Session</Th><Th>Due</Th><Th align="right">Gross</Th><Th align="right">Discount</Th><Th align="right">Balance</Th><Th>Status</Th><Th /></>}
            cols={C} loading={loading} error={error} onRetry={reload}>
            {rows.map((inv) => {
              const gross = Number(inv.amount) + Number(inv.gst_amount) + Number(inv.late_fee)
              return (
                <tr key={inv.id}>
                  <Td className="font-medium">
                    {inv.student ? `${inv.student.first_name} ${inv.student.last_name}` : `#${inv.student_id}`}
                    <span className="ml-1 text-xs text-slate-400">{inv.student?.roll_no ?? ''}</span>
                  </Td>
                  <Td>{inv.title}</Td>
                  <Td className="nums text-xs">{inv.session_month}</Td>
                  <Td className="nums">{fmtDate(inv.due_date)}</Td>
                  <Td className="nums text-right">{fmtPKR(gross)}</Td>
                  <Td className="nums text-right">{inv.discount > 0 ? fmtPKR(inv.discount) : '—'}</Td>
                  <Td className="nums text-right font-semibold">{fmtPKR(inv.balance)}</Td>
                  <Td><FeeStatus value={inv.status} /></Td>
                  <Td className="text-right">
                    {canRecord && Number(inv.balance) > 0 && ['unpaid', 'partial'].includes(inv.status) && (
                      <Btn size="sm" variant="ghost" onClick={() => setPayFor(inv)}>Receive</Btn>
                    )}
                  </Td>
                </tr>
              )
            })}
            {!rows.length && <tr><Td colSpan={C}><Empty text="No invoices for this filter." /></Td></tr>}
          </Table>
          {Number(ledger?.data?.current_page ?? 1) < Number(ledger?.data?.last_page ?? 1) && (
            <p className="nums mt-2 text-xs text-ink-soft">Showing page {ledger?.data.current_page} of {ledger?.data.last_page} ({ledger?.data.total} invoices).</p>
          )}
        </div>
      )}

      {tab === 'outstanding' && <Outstanding />}
      {tab === 'waivers' && <Waivers canManage={canManage} canApprove={canApprove} onChanged={reload} />}
      {tab === 'structures' && <Structures canManage={canManage} />}

      {payFor && <PaymentModal invoice={payFor} onClose={() => { setPayFor(null); reload() }} />}
      {generateOpen && <GenerateModal onClose={() => { setGenerateOpen(false); reload() }} />}
    </div>
  )
}

function PaymentModal({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const [form, setForm] = useState({ amount: String(invoice.balance), channel: 'cash', reference: '', paid_on: '', remarks: '' })
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true); setErr('')
    try {
      const payload: Record<string, string> = { amount: form.amount, channel: form.channel }
      if (form.reference) payload.reference = form.reference
      if (form.paid_on) payload.paid_on = form.paid_on
      if (form.remarks) payload.remarks = form.remarks
      await api.post(`/fee-invoices/${invoice.id}/payments`, payload)
      onClose()
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  return (
    <Modal title="Receive payment" subtitle={`${invoice.title} · outstanding ${fmtPKR(invoice.balance)}`}
      onClose={onClose} size="md"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => formRef.current?.requestSubmit()}>{saving ? 'Saving…' : 'Record payment'}</Btn>
      </>}>
      <form ref={formRef} onSubmit={submit} className="space-y-3">
        <Field label="Amount (PKR)"><input className={inputCls} type="number" step="0.01" max={invoice.balance} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
        <Field label="Channel">
          <select className={selectCls} value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
            {CHANNELS.map((c) => <option key={c} value={c}>{label(c)}</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Reference"><input className={inputCls} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} placeholder="JazzCash txn ID" /></Field>
          <Field label="Paid on"><input className={inputCls} type="date" value={form.paid_on} onChange={(e) => setForm({ ...form, paid_on: e.target.value })} /></Field>
        </div>
        <Field label="Remarks"><input className={inputCls} value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></Field>
        {err && <ErrorNote text={err} />}
      </form>
    </Modal>
  )
}

function GenerateModal({ onClose }: { onClose: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const { data: structures } = useApi<{ data: Structure[] }>('/fee-structures')
  const [form, setForm] = useState(() => ({ fee_structure_id: '', session_month: monthISO() }))
  const [err, setErr] = useState('')
  const [done, setDone] = useState('')

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.fee_structure_id) { setErr('Choose a fee structure first.'); return }
    setErr('')
    try {
      const res = await api.post('/fee-invoices/generate', form)
      setDone(res.data.message)
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <Modal title="Generate invoices" subtitle="Bill an active fee structure across the selected campus"
      onClose={onClose} size="md"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Close</Btn>
        <Btn onClick={() => formRef.current?.requestSubmit()}>Generate</Btn>
      </>}>
      <form ref={formRef} onSubmit={submit} className="space-y-3">
        <Field label="Fee structure">
          <select className={selectCls} value={form.fee_structure_id} onChange={(e) => setForm({ ...form, fee_structure_id: e.target.value })}>
            <option value="">Select…</option>
            {(structures?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name} — {fmtPKR(s.amount)} ({label(s.frequency)})</option>)}
          </select>
        </Field>
        <Field label="Session month"><input className={inputCls} type="month" value={form.session_month} onChange={(e) => setForm({ ...form, session_month: e.target.value })} /></Field>
        <p className="text-xs text-ink-soft">One invoice is created per active student in the selected campus; already-billed months are skipped.</p>
        {err && <ErrorNote text={err} />}
        {done && <Notice tone="green">{done}</Notice>}
      </form>
    </Modal>
  )
}

function Outstanding() {
  const { data, loading, error, reload } = useApi<{ data: OutstandingRow[] }>('/fees/outstanding')
  const rows = data?.data ?? []
  const total = rows.reduce((a, r) => a + r.outstanding, 0)
  const C = 5
  return (
    <div>
      <p className="nums mb-3 text-sm text-ink-soft">{rows.length} defaulters owing {fmtPKR(total)}.</p>
      <Table headers={<><Th>Roll #</Th><Th>Student</Th><Th>Invoices</Th><Th>Overdue</Th><Th align="right">Outstanding</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((r) => (
          <tr key={r.student_id}>
            <Td className="nums text-xs">{r.roll_no ?? '—'}</Td>
            <Td className="font-medium">{r.name}</Td>
            <Td className="nums">{r.invoices}</Td>
            <Td className="nums">{r.overdue > 0 ? <Badge tone="rose">{r.overdue}</Badge> : '0'}</Td>
            <Td className="nums text-right font-semibold text-rose-700">{fmtPKR(r.outstanding)}</Td>
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No outstanding dues." /></Td></tr>}
      </Table>
    </div>
  )
}

function Waivers({ canManage, canApprove, onChanged }: { canManage: boolean; canApprove: boolean; onChanged: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const { data, loading, error, reload } = useApi<{ data: Waiver[] }>('/fee-waivers')
  const { data: students } = useApi<{ data: { id: number; first_name: string; last_name: string; roll_no?: string }[] }>('/students?per_page=200')
  const { data: invoices } = useApi<{ data: Paginated<Invoice> }>('/fee-invoices?per_page=100&status=unpaid')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ student_id: '', invoice_id: '', percent: '50', amount: '', reason: '' })
  const [err, setErr] = useState('')
  const rows = data?.data ?? []
  const C = 6

  const decide = async (id: number, decision: string) => {
    try { await api.post(`/fee-waivers/${id}/decide`, { decision }); reload(); onChanged() } catch (e) { setErr(apiError(e)) }
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      const payload: Record<string, string | number> = { student_id: form.student_id, reason: form.reason, percent: form.percent }
      if (form.invoice_id) payload.invoice_id = form.invoice_id
      await api.post('/fee-waivers', payload)
      setOpen(false); reload(); onChanged()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div>
      <Toolbar className="justify-end">{canManage && <Btn onClick={() => setOpen(true)} icon={<IconPlus className="h-4 w-4" />}>Request concession</Btn>}</Toolbar>
      {err && <div className="mb-3"><ErrorNote text={err} /></div>}
      <Table headers={<><Th>Student</Th><Th>Invoice</Th><Th>Concession</Th><Th>Reason</Th><Th>Status</Th><Th /></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((w) => (
          <tr key={w.id}>
            <Td className="font-medium">{w.student ? `${w.student.first_name} ${w.student.last_name}` : '—'}</Td>
            <Td>{w.invoice ? `${w.invoice.title} (${fmtPKR(w.invoice.amount)})` : 'All invoices'}</Td>
            <Td className="nums">{w.percent ? `${Number(w.percent)}%` : fmtPKR(w.amount)}</Td>
            <Td className="max-w-[16rem] truncate">{w.reason}</Td>
            <Td><FeeStatus value={w.status} /></Td>
            <Td className="text-right">
              {w.status === 'pending' && canApprove && (
                <span className="flex justify-end gap-2">
                  <Btn size="sm" variant="ghost" onClick={() => decide(w.id, 'approved')}>Approve</Btn>
                  <Btn size="sm" variant="danger" onClick={() => decide(w.id, 'rejected')}>Reject</Btn>
                </span>
              )}
            </Td>
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No concession requests." /></Td></tr>}
      </Table>

      {open && (
        <Modal title="Request fee concession" subtitle="Waive a percentage of an invoice or the whole student balance"
          onClose={() => setOpen(false)} size="md"
          footer={<>
            <Btn variant="ghost" onClick={() => setOpen(false)}>Cancel</Btn>
            <Btn onClick={() => formRef.current?.requestSubmit()}>Submit request</Btn>
          </>}>
          <form ref={formRef} onSubmit={submit} className="space-y-3">
            <Field label="Student">
              <select className={selectCls} value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })}>
                <option value="">Select…</option>
                {(students?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.roll_no ? `${s.roll_no} — ` : ''}{s.first_name} {s.last_name}</option>)}
              </select>
            </Field>
            <Field label="Invoice (optional — leave blank for the whole student)">
              <select className={selectCls} value={form.invoice_id} onChange={(e) => setForm({ ...form, invoice_id: e.target.value })}>
                <option value="">All outstanding invoices</option>
                {(invoices?.data?.data ?? []).filter((i) => String(i.student_id) === form.student_id || !form.student_id)
                  .map((i) => <option key={i.id} value={i.id}>{i.title} — {fmtPKR(i.balance)}</option>)}
              </select>
            </Field>
            <Field label="Percent waived"><input className={inputCls} type="number" min="1" max="100" value={form.percent} onChange={(e) => setForm({ ...form, percent: e.target.value })} /></Field>
            <Field label="Reason"><input className={inputCls} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} placeholder="Loss of guardian income" /></Field>
            {err && <ErrorNote text={err} />}
          </form>
        </Modal>
      )}
    </div>
  )
}

function Structures({ canManage }: { canManage: boolean }) {
  const { data, loading, error, reload } = useApi<{ data: Structure[] }>('/fee-structures')
  const { data: classes } = useApi<{ data: { id: number; name: string }[] }>('/classes')
  const { data: programs } = useApi<{ data: { id: number; name: string }[] }>('/programs')
  const { data: years } = useApi<{ data: { id: number; name: string }[] }>('/academic-years')
  const blankForm = { name: '', category: 'tuition', amount: '', gst_percent: '0', frequency: 'monthly', due_day: '10', class_id: '', program_id: '', academic_year_id: '', is_active: true }
  const [form, setForm] = useState<{ id?: number; name: string; category: string; amount: string; gst_percent: string; frequency: string; due_day: string; class_id: string; program_id: string; academic_year_id: string; is_active: boolean }>(blankForm)
  const [err, setErr] = useState('')
  const rows = data?.data ?? []
  const C = canManage ? 6 : 5

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      const { id, ...values } = form
      const payload = {
        ...values, amount: Number(form.amount), gst_percent: Number(form.gst_percent), due_day: Number(form.due_day),
        class_id: form.class_id ? Number(form.class_id) : null,
        program_id: form.program_id ? Number(form.program_id) : null,
        academic_year_id: form.academic_year_id ? Number(form.academic_year_id) : null,
      }
      if (id) await api.put(`/fee-structures/${id}`, payload)
      else await api.post('/fee-structures', payload)
      setForm(blankForm); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
      <Table headers={<><Th>Head</Th><Th>Category</Th><Th>Frequency</Th><Th>Due day</Th><Th align="right">Amount</Th>{canManage && <Th>Actions</Th>}</>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((s) => (
          <tr key={s.id}>
            <Td className="font-medium">{s.name}{s.class && <span className="ml-2 text-xs text-slate-400">{s.class.name}</span>}</Td>
            <Td>{label(s.category)}</Td>
            <Td>{label(s.frequency)}</Td>
            <Td className="nums">{s.due_day}</Td>
            <Td className="nums text-right">{fmtPKR(s.amount)}{Number(s.gst_percent) > 0 && <span className="ml-1 text-xs text-slate-400">+{Number(s.gst_percent)}%</span>}</Td>
            {canManage && <Td><Btn size="sm" variant="ghost" onClick={() => setForm({
              id: s.id, name: s.name, category: s.category, amount: String(s.amount),
              gst_percent: String(s.gst_percent), frequency: s.frequency, due_day: String(s.due_day),
              class_id: s.class_id ? String(s.class_id) : '', program_id: s.program_id ? String(s.program_id) : '',
              academic_year_id: s.academic_year_id ? String(s.academic_year_id) : '', is_active: s.is_active,
            })}>Edit</Btn></Td>}
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No fee structures for this campus." /></Td></tr>}
      </Table>

      {canManage && (
        <Card className="h-fit p-4">
          <h3 className="mb-3 text-sm font-semibold text-slate-800">{form.id ? 'Edit fee structure' : 'New fee structure'}</h3>
          <form onSubmit={submit} className="space-y-3">
            <Field label="Name"><input className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Monthly Tuition Fee — 2027" /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Category">
                <select className={selectCls} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                  {['tuition', 'exam', 'transport', 'library', 'sports', 'other'].map((c) => <option key={c} value={c}>{label(c)}</option>)}
                </select>
              </Field>
              <Field label="Frequency">
                <select className={selectCls} value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value })}>
                  {['monthly', 'quarterly', 'annual', 'one_time'].map((f) => <option key={f} value={f}>{label(f)}</option>)}
                </select>
              </Field>
            </div>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Amount"><input className={inputCls} type="number" min="1" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
              <Field label="GST %"><input className={inputCls} type="number" min="0" value={form.gst_percent} onChange={(e) => setForm({ ...form, gst_percent: e.target.value })} /></Field>
              <Field label="Due day"><input className={inputCls} type="number" min="1" max="28" value={form.due_day} onChange={(e) => setForm({ ...form, due_day: e.target.value })} /></Field>
            </div>
            <Field label="Class (optional)">
              <select className={selectCls} value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })}>
                <option value="">All classes</option>
                {(classes?.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Program (optional)">
              <select className={selectCls} value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })}>
                <option value="">All programs</option>
                {(programs?.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            <Field label="Academic year (optional)">
              <select className={selectCls} value={form.academic_year_id} onChange={(e) => setForm({ ...form, academic_year_id: e.target.value })}>
                <option value="">Any year</option>
                {(years?.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
            {form.id && <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Structure active</label>}
            {err && <ErrorNote text={err} />}
            <Btn type="submit" className="w-full">{form.id ? 'Save changes' : 'Create structure'}</Btn>
            {form.id && <Btn variant="ghost" className="w-full" onClick={() => { setForm(blankForm); setErr('') }}>Cancel editing</Btn>}
          </form>
        </Card>
      )}
    </div>
  )
}
