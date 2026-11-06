import { useRef, useState } from 'react'
import { api, apiError } from '../lib/api'
import { todayISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { IconAccounts, IconArrowRight, IconCheck, IconPlus, IconReport } from '../components/icons'
import { Badge, Btn, Card, Empty, ErrorNote, Field, Modal, Notice, PageHeader, Pagination, StatCard, Status, Table, Tabs, Td, Th, Toolbar, inputCls, selectCls } from '../components/ui'
import { askConfirm } from '../store/confirm'

interface ChartAccount {
  id: number; code: string; name: string; type: string; is_system: boolean; is_active: boolean
  parent?: string | null; debit: number; credit: number; balance: number
}
interface VoucherLine { account_id: number; debit: number; credit: number; memo?: string | null; account?: { code: string; name: string } | null }
interface Voucher {
  id: number; voucher_no: string; voucher_date: string; type: string; source: string; status: string
  narrative: string; remarks?: string | null; amount: number | string
  campus?: { code: string; name: string } | null; poster?: { name: string } | null; lines?: VoucherLine[]
}
interface LedgerRow { voucher_no: string; date: string; narrative: string; type: string; memo?: string | null; debit: number; credit: number; balance: number }
type Tab = 'chart' | 'vouchers' | 'trial' | 'pnl' | 'balance'

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense']
const VOUCHER_TYPES = ['receipt', 'payment', 'journal', 'contra']

export default function Accounts() {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'accounts.manage')
  const [tab, setTab] = useState<Tab>('chart')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [ledgerFor, setLedgerFor] = useState<ChartAccount | null>(null)
  const [editAccount, setEditAccount] = useState<ChartAccount | null>(null)
  const [voucherOpen, setVoucherOpen] = useState(false)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  const period = new URLSearchParams(Object.entries({ from, to }).filter(([, v]) => v) as [string, string][])
  const chart = useApi<{ data: ChartAccount[] }>(`/accounts/chart?${period}`, [from, to])
  const { data: summary, reload: reloadSummary } = useApi<{ data: {
    cash_on_hand: number; income_this_month: number; expense_this_month: number; net_this_month: number; vouchers: number; month: { from: string; to: string }
  } }>('/accounts/summary')
  const accounts = chart?.data?.data ?? []

  const reverse = async (v: Voucher) => {
    setBusy(true); setNotice('')
    try {
      setNotice((await api.post(`/vouchers/${v.id}/reverse`, { reason: 'Reversed from accounts page' })).data.message)
      chart.reload(); reloadSummary()
    } catch (e) { setNotice(apiError(e)) } finally { setBusy(false) }
  }

  const C = 7

  return (
    <div>
      <PageHeader title="Accounts" subtitle="Chart of accounts, journal vouchers and financial statements on a double-entry ledger"
        icon={<IconAccounts className="h-5 w-5" />}
        actions={canManage && <Btn onClick={() => setVoucherOpen(true)} icon={<IconPlus className="h-4 w-4" />}>New voucher</Btn>} />

      {!localStorage.getItem('cms_campus') && !user?.is_head_office && (
        <div className="mb-4"><Notice tone="amber">Select a campus to view its books.</Notice></div>
      )}
      {notice && <div className="mb-4"><Notice tone="green">{notice}</Notice></div>}

      <div className="mb-5 grid gap-3 sm:grid-cols-4">
        <StatCard label="Cash & bank" value={fmtPKR(summary?.data.cash_on_hand)} hint="Cash, bank and mobile wallets" icon={<IconAccounts className="h-4 w-4" />} tone="blue" />
        <StatCard label="Income (month)" value={fmtPKR(summary?.data.income_this_month)} hint={fmtDate(summary?.data.month.from)} icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="Expense (month)" value={fmtPKR(summary?.data.expense_this_month)} hint="Posted vouchers only" icon={<IconArrowRight className="h-4 w-4" />} tone="rose" />
        <StatCard label="Net (month)" value={fmtPKR(summary?.data.net_this_month)} hint={`${summary?.data.vouchers ?? 0} vouchers booked`} icon={<IconReport className="h-4 w-4" />} tone="gold" />
      </div>

      <Tabs active={tab} onChange={(key) => setTab(key as Tab)}
        tabs={[
          { key: 'chart', label: 'Chart of accounts' },
          { key: 'vouchers', label: 'Journal' },
          { key: 'trial', label: 'Trial balance' },
          { key: 'pnl', label: 'Profit & loss' },
          { key: 'balance', label: 'Balance sheet' },
        ]} />

      <Toolbar>
        <span className="text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Period</span>
        <input className={`${inputCls} w-40`} type="date" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From date" />
        <span className="text-ink-soft">–</span>
        <input className={`${inputCls} w-40`} type="date" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To date" />
        {(from || to) && <Btn size="sm" variant="ghost" onClick={() => { setFrom(''); setTo('') }}>Clear dates</Btn>}
      </Toolbar>

      {tab === 'chart' && (
        <div className="grid gap-4 lg:grid-cols-[3fr_1fr]">
          <Table headers={<><Th>Code</Th><Th>Account</Th><Th>Type</Th><Th align="right">Debit</Th><Th align="right">Credit</Th><Th align="right">Balance</Th><Th /></>}
            cols={C} loading={chart.loading} error={chart.error} onRetry={chart.reload}>
            {accounts.map((a) => (
              <tr key={a.id} className="cursor-pointer" onClick={() => setLedgerFor(a)}>
                <Td className="nums text-xs">{a.code}{a.parent && <span className="ml-1 text-slate-400">↳ {a.parent}</span>}</Td>
                <Td className="font-medium">{a.name}{!a.is_active && <Status value="inactive" />}</Td>
                <Td><Status value={a.type} /></Td>
                <Td className="nums text-right">{a.debit ? fmtPKR(a.debit) : '—'}</Td>
                <Td className="nums text-right">{a.credit ? fmtPKR(a.credit) : '—'}</Td>
                <Td className="nums text-right font-semibold">{fmtPKR(a.balance)}</Td>
                <Td className="text-right text-xs text-slate-400">
                  {canManage && !a.is_system && <span onClick={(event) => event.stopPropagation()}><Btn size="sm" variant="ghost" onClick={() => setEditAccount(a)}>Edit</Btn></span>}
                  <span className="ml-2">Ledger →</span>
                </Td>
              </tr>
            ))}
            {!accounts.length && <tr><Td colSpan={C}><Empty text="No accounts in this view." /></Td></tr>}
          </Table>
          {canManage && <NewAccount accounts={accounts} onCreated={() => chart.reload()} />}
        </div>
      )}

      {tab === 'vouchers' && <Journal period={period} onReverse={reverse} busy={busy} />}
      {tab === 'trial' && <TrialBalance period={period} />}
      {tab === 'pnl' && <ProfitLoss period={period} />}
      {tab === 'balance' && <BalanceSheet asOf={to} />}

      {ledgerFor && <LedgerModal account={ledgerFor} period={period} onClose={() => setLedgerFor(null)} />}
      {editAccount && <EditAccount account={editAccount} onClose={() => { setEditAccount(null); chart.reload() }} />}
      {voucherOpen && <VoucherModal accounts={accounts.filter((a) => a.is_active)} onClose={() => { setVoucherOpen(false); chart.reload(); reloadSummary() }} />}
    </div>
  )
}

function EditAccount({ account, onClose }: { account: ChartAccount; onClose: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const [name, setName] = useState(account.name)
  const [active, setActive] = useState(account.is_active)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  async function save(event: React.FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.put(`/accounts/${account.id}`, { name, is_active: active })
      onClose()
    } catch (e) {
      setError(apiError(e))
      setSaving(false)
    }
  }

  return (
    <Modal title="Edit account" subtitle={`${account.code} · ${label(account.type)}`}
      onClose={onClose} size="md"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => formRef.current?.requestSubmit()}>{saving ? 'Saving…' : 'Save changes'}</Btn>
      </>}>
      <form ref={formRef} onSubmit={save} className="space-y-4">
        <Field label="Name"><input className={inputCls} required value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
          Account active
        </label>
        {error && <ErrorNote text={error} />}
      </form>
    </Modal>
  )
}

function NewAccount({ accounts, onCreated }: { accounts: ChartAccount[]; onCreated: () => void }) {
  const [form, setForm] = useState({ code: '', name: '', type: 'expense', parent_id: '' })
  const [err, setErr] = useState('')

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      const payload: Record<string, string | number> = { ...form }
      if (!form.parent_id) delete payload.parent_id
      await api.post('/accounts/chart', payload)
      setForm({ ...form, code: '', name: '' }); onCreated()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <Card className="h-fit p-4">
      <h3 className="mb-3 text-sm font-semibold text-slate-800">Add account</h3>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Code"><input className={inputCls} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} placeholder="5300" /></Field>
          <Field label="Type">
            <select className={selectCls} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Name"><input className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Generator Fuel" /></Field>
        <Field label="Parent (optional)">
          <select className={selectCls} value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })}>
            <option value="">None</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
          </select>
        </Field>
        {err && <ErrorNote text={err} />}
        <Btn type="submit" className="w-full">Create account</Btn>
      </form>
    </Card>
  )
}

function LedgerModal({ account, period, onClose }: { account: ChartAccount; period: URLSearchParams; onClose: () => void }) {
  const { data, loading, error, reload } = useApi<{ data: { account: { code: string; name: string; type: string }; lines: LedgerRow[]; totals: { debit: number; credit: number; closing: number } } }>(
    `/accounts/${account.id}/ledger?${period}`, [period.toString()])
  const body = data?.data
  const C = 6
  return (
    <Modal title={body?.account.name ?? account.name}
      subtitle={`${body?.account.code ?? account.code} · ${label(body?.account.type ?? account.type)}`}
      onClose={onClose} size="lg"
      footer={<Btn variant="ghost" onClick={onClose}>Close</Btn>}>
      <Table headers={<><Th>Voucher</Th><Th>Date</Th><Th>Narrative</Th><Th align="right">Debit</Th><Th align="right">Credit</Th><Th align="right">Balance</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {(body?.lines ?? []).map((l, i) => (
          <tr key={`${l.voucher_no}-${i}`}>
            <Td className="nums text-xs">{l.voucher_no}</Td>
            <Td className="nums text-xs">{fmtDate(l.date)}</Td>
            <Td>{l.narrative}{l.memo && <span className="ml-1 text-xs text-slate-400">({l.memo})</span>}</Td>
            <Td className="nums text-right">{l.debit ? fmtPKR(l.debit) : '—'}</Td>
            <Td className="nums text-right">{l.credit ? fmtPKR(l.credit) : '—'}</Td>
            <Td className="nums text-right font-semibold">{fmtPKR(l.balance)}</Td>
          </tr>
        ))}
        {(body?.lines ?? []).length === 0 && <tr><Td colSpan={C}><Empty text="No postings in this period." /></Td></tr>}
      </Table>
      {body && (
        <p className="nums mt-3 text-sm text-ink-soft">
          Debits {fmtPKR(body.totals.debit)} · credits {fmtPKR(body.totals.credit)} · closing balance <strong className="text-slate-800">{fmtPKR(body.totals.closing)}</strong>
        </p>
      )}
    </Modal>
  )
}

function Journal({ period, onReverse, busy }: { period: URLSearchParams; onReverse: (v: Voucher) => void; busy: boolean }) {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'accounts.manage')
  const [type, setType] = useState('')
  const [source, setSource] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<number | null>(null)
  const query = new URLSearchParams({ ...Object.fromEntries(period), page: String(page) })
  if (type) query.set('type', type)
  if (source) query.set('source', source)
  const { data, loading, error, reload } = useApi<{ data: Paginated<Voucher> }>(`/vouchers?${query}`, [type, source, page, period.toString()])
  const rows = data?.data?.data ?? []
  const C = 8

  const reverse = async (v: Voucher) => { onReverse(v); reload() }

  return (
    <div>
      <Toolbar>
        <select className={`${selectCls} w-48`} value={type} onChange={(e) => { setType(e.target.value); setPage(1) }}>
          <option value="">All voucher types</option>
          {VOUCHER_TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
        </select>
        <select className={`${selectCls} w-40`} value={source} onChange={(e) => { setSource(e.target.value); setPage(1) }}>
          <option value="">All sources</option>
          {['manual', 'fee_payment', 'payroll', 'seed'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
        </select>
      </Toolbar>
      <Table headers={<><Th>Voucher #</Th><Th>Date</Th><Th>Narrative</Th><Th>Type</Th><Th>Source</Th><Th align="right">Amount</Th><Th>Status</Th><Th /></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((v) => (
          <FragmentRow key={v.id}>
            <tr className="cursor-pointer" onClick={() => setOpen(open === v.id ? null : v.id)}>
              <Td className="nums text-xs">{v.voucher_no}</Td>
              <Td className="nums text-xs">{fmtDate(v.voucher_date)}</Td>
              <Td className="max-w-[22rem] truncate">{v.narrative}</Td>
              <Td><Status value={v.type} /></Td>
              <Td className="text-xs text-ink-soft">{label(v.source)}{v.campus ? ` · ${v.campus.code}` : ''}</Td>
              <Td className="nums text-right">{fmtPKR(v.amount)}</Td>
              <Td><Status value={v.status} /></Td>
              <Td className="text-right">
                {canManage && v.status === 'posted' && (
                  <Btn size="sm" variant="ghost"
                    onClick={() => void askConfirm({
                      title: 'Reverse this voucher?',
                      text: `Voucher ${v.voucher_no} (${fmtPKR(v.amount)}) will be marked as reversed. The original entry stays in the ledger for audit.`,
                      confirmLabel: 'Reverse',
                    }).then((ok) => { if (ok) reverse(v) })}>Reverse</Btn>
                )}
              </Td>
            </tr>
            {open === v.id && (
              <tr className="bg-canvas/70">
                <Td colSpan={C}>
                  <table className="w-full text-xs">
                    <thead><tr className="text-left text-ink-soft"><Th>Account</Th><Th>Memo</Th><Th align="right">Debit</Th><Th align="right">Credit</Th></tr></thead>
                    <tbody>
                      {(v.lines ?? []).map((l, i) => (
                        <tr key={i}>
                          <Td className="nums py-1">{l.account?.code} — {l.account?.name}</Td>
                          <Td className="py-1">{l.memo ?? '—'}</Td>
                          <Td className="nums py-1 text-right">{l.debit ? fmtPKR(l.debit) : '—'}</Td>
                          <Td className="nums py-1 text-right">{l.credit ? fmtPKR(l.credit) : '—'}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {v.remarks && <p className="mt-2 text-xs text-ink-soft">{v.remarks}</p>}
                </Td>
              </tr>
            )}
          </FragmentRow>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="No vouchers posted yet." /></Td></tr>}
      </Table>
      {busy && <p className="mt-2 text-xs text-ink-soft">Working…</p>}
      <Pagination page={data?.data?.current_page ?? 1} lastPage={data?.data?.last_page ?? 1}
        total={data?.data?.total ?? 0} perPage={data?.data?.per_page} onPage={setPage} busy={busy} />
    </div>
  )
}

function FragmentRow({ children }: { children: React.ReactNode }) {
  return <>{children}</>
}

function VoucherModal({ accounts, onClose }: { accounts: ChartAccount[]; onClose: () => void }) {
  const formRef = useRef<HTMLFormElement>(null)
  const [form, setForm] = useState(() => ({ voucher_date: todayISO(), type: 'payment', narrative: '', remarks: '' }))
  const [lines, setLines] = useState([{ account_id: '', debit: '', credit: '', memo: '' }, { account_id: '', debit: '', credit: '', memo: '' }])
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const debit = lines.reduce((t, l) => t + Number(l.debit || 0), 0)
  const credit = lines.reduce((t, l) => t + Number(l.credit || 0), 0)
  const balanced = debit > 0 && Math.abs(debit - credit) < 0.01

  const setLine = (i: number, patch: Partial<(typeof lines)[number]>) => setLines(lines.map((l, idx) => (idx === i ? { ...l, ...patch } : l)))

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    if (!balanced) { setErr('Debits and credits must match before posting.'); return }
    const payload = {
      ...form,
      lines: lines.filter((l) => l.account_id).map((l) => ({
        account_id: Number(l.account_id), debit: Number(l.debit || 0), credit: Number(l.credit || 0), memo: l.memo || undefined,
      })),
    }
    if (payload.lines.length < 2) { setErr('A voucher needs at least two account lines.'); return }
    setSaving(true)
    try { await api.post('/vouchers', payload); onClose() }
    catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  return (
    <Modal title="Post journal voucher"
      subtitle="Fee receipts and payroll post themselves; use this for rent, utilities, purchases and corrections."
      onClose={onClose} size="lg"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving || !balanced} onClick={() => formRef.current?.requestSubmit()}>{saving ? 'Posting…' : 'Post voucher'}</Btn>
      </>}>
      <form ref={formRef} onSubmit={submit} className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Voucher date"><input className={inputCls} type="date" max={todayISO()} value={form.voucher_date} onChange={(e) => setForm({ ...form, voucher_date: e.target.value })} /></Field>
          <Field label="Type">
            <select className={selectCls} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {VOUCHER_TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
            </select>
          </Field>
          <Field label="Narrative"><input className={inputCls} required value={form.narrative} onChange={(e) => setForm({ ...form, narrative: e.target.value })} placeholder="October electricity bill" /></Field>
        </div>

        <div className="space-y-2 rounded-xl border border-line p-3">
          <div className="grid grid-cols-[2fr_1fr_1fr_1.5fr_auto] gap-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">
            <span>Account</span><span>Debit</span><span>Credit</span><span>Memo</span><span />
          </div>
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-[2fr_1fr_1fr_1.5fr_auto] items-center gap-2">
              <select className={selectCls} value={l.account_id} onChange={(e) => setLine(i, { account_id: e.target.value })}>
                <option value="">Select…</option>
                {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
              </select>
              <input className={`${inputCls} nums`} type="number" min="0" step="0.01" value={l.debit} onChange={(e) => setLine(i, { debit: e.target.value, credit: '' })} />
              <input className={`${inputCls} nums`} type="number" min="0" step="0.01" value={l.credit} onChange={(e) => setLine(i, { credit: e.target.value, debit: '' })} />
              <input className={inputCls} value={l.memo} onChange={(e) => setLine(i, { memo: e.target.value })} />
              <Btn size="sm" variant="ghost" className="px-2" onClick={() => setLines(lines.filter((_, idx) => idx !== i).length > 1 ? lines.filter((_, idx) => idx !== i) : lines)}>×</Btn>
            </div>
          ))}
          <div className="flex items-center justify-between">
            <Btn variant="soft" onClick={() => setLines([...lines, { account_id: '', debit: '', credit: '', memo: '' }])}>Add line</Btn>
            <span className={`nums text-sm ${balanced ? 'text-emerald-700' : 'text-rose-600'}`}>
              Dr {fmtPKR(debit)} / Cr {fmtPKR(credit)} {balanced ? '· balanced' : debit !== credit ? '· out of balance' : ''}
            </span>
          </div>
        </div>

        <Field label="Remarks"><input className={inputCls} value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></Field>
        {err && <ErrorNote text={err} />}
      </form>
    </Modal>
  )
}

function AmountRows({ rows }: { rows: { code: string; name: string; amount: number }[] }) {
  return (
    <>
      {rows.map((r) => (
        <tr key={r.code}>
          <Td className="nums text-xs">{r.code}</Td>
          <Td>{r.name}</Td>
          <Td className="nums text-right">{fmtPKR(r.amount)}</Td>
        </tr>
      ))}
      {!rows.length && <tr><Td colSpan={3}><Empty text="No movement in this period." /></Td></tr>}
    </>
  )
}

function TrialBalance({ period }: { period: URLSearchParams }) {
  const { data, loading, error, reload } = useApi<{ data: { rows: { code: string; name: string; type: string; debit: number; credit: number }[]; totals: { debit: number; credit: number } } }>(
    `/reports/trial-balance?${period}`, [period.toString()])
  const rows = data?.data?.rows ?? []
  const balanced = Math.abs((data?.data?.totals.debit ?? 0) - (data?.data?.totals.credit ?? 0)) < 0.01
  const C = 5
  return (
    <div>
      <Table headers={<><Th>Code</Th><Th>Account</Th><Th>Type</Th><Th align="right">Debit</Th><Th align="right">Credit</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((r) => (
          <tr key={r.code}>
            <Td className="nums text-xs">{r.code}</Td>
            <Td>{r.name}</Td>
            <Td><Status value={r.type} /></Td>
            <Td className="nums text-right">{r.debit ? fmtPKR(r.debit) : '—'}</Td>
            <Td className="nums text-right">{r.credit ? fmtPKR(r.credit) : '—'}</Td>
          </tr>
        ))}
        {!rows.length && <tr><Td colSpan={C}><Empty text="Trial balance is empty for this period and campus." /></Td></tr>}
        {!!rows.length && (
          <tr className="bg-canvas/70 font-semibold">
            <Td colSpan={3}>Totals {!balanced && <Badge tone="rose">out of balance</Badge>}</Td>
            <Td className="nums text-right">{fmtPKR(data?.data?.totals.debit)}</Td>
            <Td className="nums text-right">{fmtPKR(data?.data?.totals.credit)}</Td>
          </tr>
        )}
      </Table>
    </div>
  )
}

function ProfitLoss({ period }: { period: URLSearchParams }) {
  const { data, loading, error, reload } = useApi<{ data: { period: { from: string; to: string }; income: { code: string; name: string; amount: number }[]; expense: { code: string; name: string; amount: number }[]; total_income: number; total_expense: number; net: number } }>(
    `/reports/profit-loss?${period}`, [period.toString()])
  const d = data?.data
  const C = 3
  const stmt = <><Th>Code</Th><Th>Head</Th><Th align="right">Amount</Th></>
  return (
    <div>
      <p className="nums mb-3 text-sm text-ink-soft">Period {fmtDate(d?.period.from)} – {fmtDate(d?.period.to)}</p>
      <div className="grid gap-4 lg:grid-cols-2">
        <div>
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Income</h3>
          <Table headers={stmt} cols={C} loading={loading} error={error} onRetry={reload}>
            <AmountRows rows={d?.income ?? []} />
            <tr className="bg-canvas/70 font-semibold"><Td colSpan={2}>Total income</Td><Td className="nums text-right">{fmtPKR(d?.total_income)}</Td></tr>
          </Table>
        </div>
        <div>
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Expense</h3>
          <Table headers={stmt} cols={C} loading={loading} error={error} onRetry={reload}>
            <AmountRows rows={d?.expense ?? []} />
            <tr className="bg-canvas/70 font-semibold"><Td colSpan={2}>Total expense</Td><Td className="nums text-right">{fmtPKR(d?.total_expense)}</Td></tr>
          </Table>
        </div>
      </div>
      <Card className={`mt-4 flex items-center justify-between p-4 ${Number(d?.net ?? 0) >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'}`}>
        <span className="text-sm font-medium text-slate-700">Net {Number(d?.net ?? 0) >= 0 ? 'surplus' : 'deficit'} for the period</span>
        <span className="nums text-xl font-semibold">{fmtPKR(d?.net)}</span>
      </Card>
    </div>
  )
}

function BalanceSheet({ asOf }: { asOf: string }) {
  const { data, loading, error, reload } = useApi<{ data: {
    as_of: string; assets: { code: string; name: string; amount: number }[]; liabilities: { code: string; name: string; amount: number }[]
    equity: { code: string; name: string; amount: number }[]; total_assets: number; total_liabilities: number; total_equity: number; current_surplus: number; balanced: boolean
  } }>(`/reports/balance-sheet${asOf ? `?as_of=${asOf}` : ''}`, [asOf])
  const d = data?.data
  const C = 3
  const stmt = <><Th>Code</Th><Th>Head</Th><Th align="right">Amount</Th></>
  return (
    <div>
      <div className="mb-3 flex items-center gap-3">
        <p className="nums text-sm text-ink-soft">As at {fmtDate(d?.as_of)}</p>
        {d && <Badge tone={d.balanced ? 'green' : 'rose'}>{d.balanced ? 'Assets = liabilities + equity' : 'Ledger does not balance'}</Badge>}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        {([
          ['Assets', d?.assets ?? [], d?.total_assets],
          ['Liabilities', d?.liabilities ?? [], d?.total_liabilities],
          ['Equity', d?.equity ?? [], d?.total_equity],
        ] as [string, { code: string; name: string; amount: number }[], number | undefined][]).map(([group, rows, total]) => (
          <div key={group}>
            <h3 className="mb-2 text-sm font-semibold text-slate-800">{group}</h3>
            <Table headers={stmt} cols={C} loading={loading} error={error} onRetry={reload}>
              <AmountRows rows={rows} />
              <tr className="bg-canvas/70 font-semibold"><Td colSpan={2}>Total {group.toLowerCase()}</Td><Td className="nums text-right">{fmtPKR(total)}</Td></tr>
            </Table>
          </div>
        ))}
      </div>
      <Card className="mt-4 flex items-center justify-between p-4">
        <span className="text-sm font-medium text-slate-700">Current-year surplus carried in equity</span>
        <span className="nums text-xl font-semibold">{fmtPKR(d?.current_surplus)}</span>
      </Card>
    </div>
  )
}
