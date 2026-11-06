import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { monthISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm } from '../lib/types'
import { IconAccounts, IconFees, IconPayroll, IconPlus } from '../components/icons'
import { Badge, Btn, Empty, ErrorNote, Field, Modal, Notice, PageHeader, StatCard, Status, Table, Td, Th, inputCls } from '../components/ui'

interface Run {
  id: number; month: string; name: string; status: string; campus_id: number
  total_gross: string | number; total_deductions: string | number; total_net: string | number
  paid_on?: string | null; payslips_count: number
  campus?: { name: string; code: string } | null
  preparer?: { name: string } | null; approver?: { name: string } | null
}
interface Payslip {
  id: number; staff_id: number; basic: number; house_rent: number; medical: number
  gross: number; eobi: number; income_tax: number; other_deductions: number
  total_deductions: number; net_pay: number; unpaid_leave_days: number; status: string
  remarks?: string | null
  staff?: { first_name: string; last_name: string; employee_no: string; designation?: { name: string } | null } | null
}

const RUNS_C = 8
const SLIPS_C = 10

export default function Payroll() {
  const { user } = useAuth()
  const canProcess = hasPerm(user, 'payroll.process')
  const [runId, setRunId] = useState<number | null>(null)
  const [newOpen, setNewOpen] = useState(false)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  const { data, loading, error, reload } = useApi<{ data: Run[] }>('/payroll/runs')
  const runs = data?.data ?? []
  const selected = runs.find((r) => r.id === runId) ?? runs[0] ?? null
  const { data: detail, loading: detailLoading, error: detailError, reload: reloadDetail } = useApi<{ data: { run: Run; payslips: Payslip[] } }>(
    selected ? `/payroll/runs/${selected.id}/payslips` : null, [selected?.id])
  const payslips = detail?.data?.payslips ?? []
  const run = detail?.data?.run ?? selected

  const act = async (path: string, body?: Record<string, string>) => {
    if (!run) return
    setBusy(true); setNotice('')
    try {
      const res = await api.post(`/payroll/runs/${run.id}/${path}`, body)
      setNotice(res.data.message ?? 'Done.')
      reload(); reloadDetail()
    } catch (e) { setNotice(apiError(e)) } finally { setBusy(false) }
  }

  const totals = payslips.reduce(
    (t, p) => ({ gross: t.gross + Number(p.gross), deductions: t.deductions + Number(p.total_deductions), net: t.net + Number(p.net_pay) }),
    { gross: 0, deductions: 0, net: 0 })

  return (
    <div>
      <PageHeader title="Payroll" subtitle="Salary runs with EOBI pension and income tax computed from campus settings" icon={<IconPayroll className="h-5 w-5" />}
        actions={canProcess && <Btn onClick={() => setNewOpen(true)} icon={<IconPlus className="h-4 w-4" />}>New payroll run</Btn>} />

      {!localStorage.getItem('cms_campus') && !user?.is_head_office && (
        <Notice tone="amber" className="mb-4" title="Campus required">Select a campus to prepare payroll.</Notice>
      )}
      {notice && <Notice tone="green" className="mb-4">{notice}</Notice>}

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        <StatCard label="Gross" value={fmtPKR(run?.total_gross)} hint={`${payslips.length} payslip(s) in this run`} icon={<IconFees className="h-4 w-4" />} tone="green" />
        <StatCard label="Deductions" value={fmtPKR(run?.total_deductions)} hint="EOBI, income tax, other and unpaid leave" icon={<IconAccounts className="h-4 w-4" />} tone="rose" />
        <StatCard label="Net payable" value={fmtPKR(run?.total_net)} hint={run?.paid_on ? `Paid on ${fmtDate(run.paid_on)}` : 'Not disbursed yet'} icon={<IconPayroll className="h-4 w-4" />} tone="gold" />
      </div>

      <div className="mb-5">
        <h2 className="mb-2 text-sm font-semibold text-ink-soft">Runs</h2>
        <Table headers={<><Th>Month</Th><Th>Campus</Th><Th align="right">Staff</Th><Th align="right">Gross</Th><Th align="right">Deductions</Th><Th align="right">Net</Th><Th>Status</Th><Th /></>}
          cols={RUNS_C} loading={loading} error={error} onRetry={reload}>
          {runs.map((r) => (
            <tr key={r.id} onClick={() => setRunId(r.id)}
              className={`cursor-pointer ${r.id === run?.id ? 'bg-brand-100/60' : ''}`}>
              <Td className="font-medium">{r.month}<span className="ml-2 text-xs font-normal text-slate-400">{r.name}</span></Td>
              <Td className="nums">{r.campus?.code ?? '—'}</Td>
              <Td className="nums text-right">{r.payslips_count}</Td>
              <Td className="nums text-right">{fmtPKR(r.total_gross)}</Td>
              <Td className="nums text-right">{fmtPKR(r.total_deductions)}</Td>
              <Td className="nums text-right font-semibold">{fmtPKR(r.total_net)}</Td>
              <Td><Status value={r.status} dot /></Td>
              <Td className="text-right text-xs text-slate-400">{r.preparer?.name ?? ''}</Td>
            </tr>
          ))}
          {!runs.length && !loading && !error && <tr><Td colSpan={RUNS_C}><Empty text="No payroll runs yet for this campus." hint="Create a run to calculate payslips from active staff salaries." /></Td></tr>}
        </Table>
      </div>

      {run && (
        <div>
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-ink-soft">Payslips — {run.month} ({run.campus?.code})</h2>
            {canProcess && (
              <div className="flex gap-2">
                {['draft', 'calculated'].includes(run.status) && (
                  <Btn variant="ghost" onClick={() => act('calculate')} disabled={busy}>
                    {run.status === 'draft' ? 'Calculate payslips' : 'Recalculate'}
                  </Btn>
                )}
                {run.status === 'calculated' && <Btn onClick={() => act('transition', { status: 'approved' })} disabled={busy}>Approve run</Btn>}
                {run.status === 'approved' && <Btn onClick={() => act('transition', { status: 'paid' })} disabled={busy}>Mark paid</Btn>}
              </div>
            )}
          </div>
          <Table headers={<><Th>Employee</Th><Th>Designation</Th><Th align="right">Basic</Th><Th align="right">House rent</Th><Th align="right">Medical</Th><Th align="right">Gross</Th><Th align="right">EOBI</Th><Th align="right">Tax</Th><Th align="right">Other</Th><Th align="right">Net</Th></>}
            cols={SLIPS_C} loading={detailLoading} error={detailError} onRetry={reloadDetail}>
            {payslips.map((p) => (
              <tr key={p.id} title={p.remarks ?? undefined}>
                <Td className="font-medium">{p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : `#${p.staff_id}`}
                  <span className="ml-1 nums font-mono text-xs text-slate-400">{p.staff?.employee_no ?? ''}</span>
                  {p.unpaid_leave_days > 0 && <Badge tone="rose">{p.unpaid_leave_days}d unpaid</Badge>}
                </Td>
                <Td className="text-xs text-slate-500">{p.staff?.designation?.name ?? '—'}</Td>
                <Td className="nums text-right">{fmtPKR(p.basic)}</Td>
                <Td className="nums text-right">{fmtPKR(p.house_rent)}</Td>
                <Td className="nums text-right">{fmtPKR(p.medical)}</Td>
                <Td className="nums text-right">{fmtPKR(p.gross)}</Td>
                <Td className="nums text-right">{fmtPKR(p.eobi)}</Td>
                <Td className="nums text-right">{fmtPKR(p.income_tax)}</Td>
                <Td className="nums text-right">{fmtPKR(p.other_deductions)}</Td>
                <Td className="nums text-right font-semibold">{fmtPKR(p.net_pay)}</Td>
              </tr>
            ))}
            {!payslips.length && !detailLoading && !detailError && <tr><Td colSpan={SLIPS_C}><Empty text="Nothing calculated yet — run the calculator to build payslips from active staff salaries." /></Td></tr>}
            {!!payslips.length && (
              <tr className="bg-canvas font-semibold">
                <Td colSpan={5}>Totals · {payslips.length} payslip(s)</Td>
                <Td className="nums text-right">{fmtPKR(totals.gross)}</Td>
                <Td colSpan={3} className="nums text-right">deducted {fmtPKR(totals.deductions)}</Td>
                <Td className="nums text-right">{fmtPKR(totals.net)}</Td>
              </tr>
            )}
          </Table>
          <p className="mt-2 text-xs text-ink-soft">
            EOBI is a percentage of gross wages up to the monthly ceiling, and income tax applies to annualised gross above the tax-free threshold — both configurable per campus under Settings.
          </p>
        </div>
      )}

      {newOpen && <NewRunModal onClose={() => { setNewOpen(false); reload() }} />}
    </div>
  )
}

function NewRunModal({ onClose }: { onClose: () => void }) {
  const [form, setForm] = useState(() => ({ month: monthISO(), name: '' }))
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true); setErr('')
    try {
      const payload: Record<string, string> = { month: form.month }
      if (form.name) payload.name = form.name
      await api.post('/payroll/runs', payload)
      onClose()
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  return (
    <Modal size="md" title="New payroll run" subtitle="Creates a draft run for the selected campus and month." onClose={onClose}
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn type="submit" form="payroll-new-run" disabled={saving}>{saving ? 'Creating…' : 'Create run'}</Btn>
      </>}>
      <form id="payroll-new-run" onSubmit={submit} className="space-y-3">
        <Field label="Month"><input className={inputCls} type="month" value={form.month} onChange={(e) => setForm({ ...form, month: e.target.value })} /></Field>
        <Field label="Name (optional)"><input className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Monthly payroll — October 2026" /></Field>
        {err && <ErrorNote text={err} />}
      </form>
    </Modal>
  )
}
