import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDateTime, hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../components/Toast'
import {
  IconAlert, IconCheck, IconClock, IconFrontDesk, IconPlus, IconUsers,
} from '../components/icons'
import {
  Badge, Btn, Empty, ErrorNote, Field, Modal, Notice, PageHeader, StatCard, Table, Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

interface Visitor {
  id: number; pass_no?: string | null; name: string; phone?: string | null; cnic?: string | null
  from_organization?: string | null; purpose: string; in_at: string; out_at?: string | null
  gate?: string | null; photo_id_taken: string
  meets_user?: { name: string } | null
  meets_student?: { first_name: string; last_name: string; admission_no: string } | null
  logger?: { name: string } | null
}
interface DeskSummary {
  today_in: number; today_out: number; still_inside: number; week_total: number
  top_purposes: { purpose: string; count: number }[]
}

const today = () => new Date().toISOString().slice(0, 10)
/** "2026-10-21 09:00:00" (Laravel) -> "2026-10-21T09:00" for a datetime-local input. */
const toLocalInput = (v?: string | null) => (v ? `${v.replace(' ', 'T').slice(0, 16)}` : '')

export default function FrontDesk() {
  const { user, campusId } = useAuth()
  const canManage = hasPerm(user, 'frontdesk.manage')

  const [date, setDate] = useState(today())
  const [openOnly, setOpenOnly] = useState(false)

  const query = `/frontdesk/visitors?date=${date}${openOnly ? '&open=1' : ''}`
  const { data, loading, error, reload } = useApi<{ data: Visitor[] }>(query, [date, openOnly])
  const { data: summaryData, reload: reloadSummary } = useApi<{ data: DeskSummary }>('/frontdesk/summary')
  const stats = summaryData?.data
  const rows = data?.data ?? []
  const [addOpen, setAddOpen] = useState(false)
  const [busy, setBusy] = useState(0)

  const refresh = () => { reload(); reloadSummary() }

  async function checkOut(v: Visitor) {
    setBusy(v.id)
    try {
      await api.post(`/frontdesk/visitors/${v.id}/checkout`)
      toast.success(`${v.name} checked out at gate ${v.gate ?? '—'}.`)
      refresh()
    } catch (e) {
      toast.error(apiError(e))
      reload()
    } finally {
      setBusy(0)
    }
  }

  const C = 7

  return (
    <div>
      <PageHeader title="Front Desk" subtitle="Visitor gate log — every pass is stamped in and out here" icon={<IconFrontDesk className="h-5 w-5" />}
        actions={canManage && (
          <Btn onClick={() => setAddOpen(true)} disabled={!campusId}
            icon={<IconPlus className="h-4 w-4" />}>Add Visitor</Btn>
        )} />

      {!campusId && <Notice tone="amber" title="Select a campus" className="mb-4">Pick a campus from the header before logging visitors — gate passes are numbered per campus.</Notice>}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="In today" value={stats?.today_in ?? '—'} hint="Visitors logged in" icon={<IconUsers className="h-4 w-4" />} tone="blue" />
        <StatCard label="Still inside" value={stats?.still_inside ?? '—'} hint="Open passes not checked out" icon={<IconClock className="h-4 w-4" />} tone="amber" />
        <StatCard label="Out today" value={stats?.today_out ?? '—'} hint="Checked out before now" icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="This week" value={stats?.week_total ?? '—'} hint="Visitors since Monday" icon={<IconFrontDesk className="h-4 w-4" />} tone="violet" />
      </div>

      {stats?.top_purposes?.length ? (
        <p className="mb-4 flex flex-wrap items-center gap-2 text-xs text-ink-soft">
          <span className="font-semibold">Top purposes today:</span>
          {stats.top_purposes.map((p) => <Badge key={p.purpose} tone="slate">{p.purpose} · {p.count}</Badge>)}
        </p>
      ) : null}

      <Toolbar>
        <input type="date" className={`${inputCls} w-44`} value={date} onChange={(e) => setDate(e.target.value)} aria-label="Visitor date" />
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="accent-brand-600" checked={openOnly} onChange={(e) => setOpenOnly(e.target.checked)} />
          Only visitors still inside
        </label>
      </Toolbar>

      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      <Table headers={<><Th>Pass #</Th><Th>Visitor</Th><Th>Phone</Th><Th>Purpose</Th><Th>Meets</Th><Th>In / Out</Th>{canManage && <Th />}</>}
        cols={C} loading={loading}>
        {rows.map((v) => (
          <tr key={v.id}>
            <Td className="nums text-xs font-semibold">{v.pass_no ?? '—'}</Td>
            <Td className="font-medium">{v.name}{v.from_organization && <span className="ml-1 text-xs text-slate-400">· {v.from_organization}</span>}</Td>
            <Td className="nums text-xs">{v.phone ?? '—'}</Td>
            <Td className="max-w-xs truncate">{v.purpose}</Td>
            <Td className="text-xs">
              {v.meets_student ? `${v.meets_student.first_name} ${v.meets_student.last_name}` : v.meets_user?.name ?? '—'}
            </Td>
            <Td className="nums whitespace-nowrap text-xs">
              {fmtDateTime(v.in_at)}
              <span className={v.out_at ? 'block text-emerald-700' : 'block text-amber-600'}>{v.out_at ? fmtDateTime(v.out_at) : 'still inside'}</span>
            </Td>
            {canManage && (
              <Td className="text-right whitespace-nowrap">
                {!v.out_at
                  ? <Btn size="sm" variant="soft" disabled={busy === v.id} onClick={() => checkOut(v)} icon={<IconCheck className="h-3.5 w-3.5" />}>Check out</Btn>
                  : <Badge tone="slate" dot>Out</Badge>}
              </Td>
            )}
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text={openOnly ? 'No visitors are still inside on this date.' : 'No visitor passes on this date.'} hint="Use Add Visitor to log the first gate pass." /></Td></tr>
        )}
      </Table>

      {addOpen && <VisitorModal onClose={() => setAddOpen(false)} onSaved={() => { setAddOpen(false); refresh() }} />}
    </div>
  )
}

function VisitorModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { data: users } = useApi<{ data: { id: number; name: string }[] }>('/users')
  const { data: students } = useApi<{ data: { id: number; first_name: string; last_name: string; admission_no: string }[] }>('/students?per_page=100&status=active')

  const [form, setForm] = useState({
    name: '', phone: '', purpose: '', from_organization: '', gate: 'Main Gate',
    meets_user_id: '', meets_student_id: '', photo_id_taken: 'yes',
    in_at: toLocalInput(new Date().toISOString().replace('T', ' ').slice(0, 19)),
    out_at: '',
  })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true); setErr('')
    const payload = {
      name: form.name, phone: form.phone || null, purpose: form.purpose,
      from_organization: form.from_organization || null, gate: form.gate || null,
      meets_user_id: form.meets_user_id ? Number(form.meets_user_id) : null,
      meets_student_id: form.meets_student_id ? Number(form.meets_student_id) : null,
      photo_id_taken: form.photo_id_taken,
      in_at: form.in_at ? new Date(form.in_at).toISOString() : null,
      out_at: form.out_at ? new Date(form.out_at).toISOString() : null,
    }
    try {
      const res = await api.post('/frontdesk/visitors', payload)
      toast.success(`Gate pass ${res.data?.data?.pass_no ?? ''} created for ${form.name}.`)
      onSaved()
    } catch (e) { setErr(apiError(e)); setBusy(false) }
  }

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <Modal title="New visitor pass" subtitle="The pass number is generated from this campus's daily sequence"
      onClose={onClose} size="md"
      footer={<><Btn variant="ghost" onClick={onClose}>Cancel</Btn><Btn onClick={save} disabled={busy || !form.name || !form.purpose}>{busy ? 'Logging…' : 'Log visitor'}</Btn></>}>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Visitor name"><input className={inputCls} autoFocus value={form.name} onChange={set('name')} placeholder="Full name" autoComplete="off" /></Field>
          <Field label="Phone"><input className={inputCls} value={form.phone} onChange={set('phone')} placeholder="0300-1234567" autoComplete="off" /></Field>
        </div>
        <Field label="Purpose of visit"><input className={inputCls} value={form.purpose} onChange={set('purpose')} placeholder="Fee payment follow-up" autoComplete="off" /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Meeting staff member">
            <select className={selectCls} value={form.meets_user_id} onChange={set('meets_user_id')}>
              <option value="">Not a staff visit</option>
              {(users?.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </Field>
          <Field label="Meeting student">
            <select className={selectCls} value={form.meets_student_id} onChange={set('meets_student_id')}>
              <option value="">Not a student visit</option>
              {(students?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.first_name} {s.last_name} — {s.admission_no}</option>)}
            </select>
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Time in"><input type="datetime-local" className={inputCls} value={form.in_at} onChange={set('in_at')} /></Field>
          <Field label="Time out (optional)" hint="Leave empty for a visitor who is still inside">
            <input type="datetime-local" className={inputCls} value={form.out_at} onChange={set('out_at')} />
          </Field>
          <Field label="Gate"><input className={inputCls} value={form.gate} onChange={set('gate')} /></Field>
          <Field label="Organization"><input className={inputCls} value={form.from_organization} onChange={set('from_organization')} placeholder="Company / school" autoComplete="off" /></Field>
          <Field label="Photo ID taken">
            <select className={selectCls} value={form.photo_id_taken} onChange={set('photo_id_taken')}>
              <option value="yes">Yes</option>
              <option value="no">No</option>
            </select>
          </Field>
        </div>
        {err && <div role="alert"><Notice tone="rose" title="Could not log the visitor"><span className="flex items-center gap-1.5"><IconAlert className="h-3.5 w-3.5 shrink-0" />{err}</span></Notice></div>}
        <p className="text-xs text-ink-soft">{label(form.photo_id_taken === 'yes' ? 'photo id taken' : 'no photo id')} · entry after today's date is refused.</p>
      </div>
    </Modal>
  )
}
