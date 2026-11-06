import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDateTime, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../store/toasts'
import { askConfirm } from '../store/confirm'
import { IconCertificate, IconCheck, IconClock, IconClose, IconPlus } from '../components/icons'
import {
  Badge, Btn, Empty, ErrorNote, Field, Modal, Notice, PageHeader, SearchInput, StatCard, Status, Table, Tabs,
  Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

const TYPES = ['bonafide', 'transfer', 'character', 'marksheet', 'internship', 'other']

interface StudentLite {
  id: number; first_name: string; last_name: string; admission_no: string
  school_class?: { name: string } | null; section?: { name: string } | null
}

interface Certificate {
  id: number; student_id: number; type: string; title?: string | null; purpose?: string | null
  fee?: string | number | null; status: string; reference_no?: string | null
  remarks?: string | null; decided_at?: string | null; issued_at?: string | null; created_at: string
  student?: StudentLite | null
  campus?: { name: string; code: string } | null
  requester?: { name: string } | null
  decider?: { name: string } | null
}

interface CertSummary {
  pending: number; approved: number; rejected: number; issued: number
  awaiting_issue: number; issued_this_month: number
}

interface PrintSheet {
  header: string; body: string; text: string; student_name: string
  admission_no?: string | null; type: string; reference_no?: string | null
  campus_name: string; issued_at?: string | null; printable: boolean
}

interface Draft {
  id?: number
  student_id: string
  type: string
  title: string
  purpose: string
  fee: string
}

const fullName = (s?: StudentLite | null) => (s ? `${s.first_name} ${s.last_name}`.trim() : '—')

export default function Certificates() {
  const { user, campusId } = useAuth()
  const canManage = hasPerm(user, 'documents.manage')

  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<Draft | null>(null)
  const [sheet, setSheet] = useState<PrintSheet | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(0)

  const query = `/certificates?per_page=100${status ? `&status=${status}` : ''}${type ? `&type=${type}` : ''}`
    + `${search ? `&search=${encodeURIComponent(search)}` : ''}`
  const { data, loading, error, reload } = useApi<{ data: Paginated<Certificate> }>(query, [status, type, search])
  const { data: summaryData, reload: reloadSummary } = useApi<{ data: CertSummary }>('/certificates/summary')
  const { data: studentData } = useApi<{ data: StudentLite[] }>('/students?per_page=300&status=active')

  const rows = data?.data.data ?? []
  const stats = summaryData?.data
  const students = studentData?.data ?? []
  const C = canManage ? 8 : 7

  const refresh = () => { reload(); reloadSummary() }

  function newDraft() {
    setErr('')
    setForm({ student_id: '', type: 'bonafide', title: '', purpose: '', fee: '' })
  }

  function editDraft(row: Certificate) {
    setErr('')
    setForm({
      id: row.id,
      student_id: String(row.student_id ?? ''),
      type: row.type,
      title: row.title ?? '',
      purpose: row.purpose ?? '',
      fee: row.fee === null || row.fee === undefined ? '' : String(Number(row.fee)),
    })
  }

  async function save() {
    if (!form) return
    if (!form.student_id) { setErr('Choose the student the certificate is for.'); return }
    setErr('')
    const payload = {
      student_id: Number(form.student_id),
      type: form.type,
      title: form.title || null,
      purpose: form.purpose || null,
      fee: form.fee === '' ? null : Number(form.fee),
    }
    try {
      if (form.id) await api.put(`/certificates/${form.id}`, payload)
      else await api.post('/certificates', payload)
      toast.success(form.id ? 'Request updated.' : 'Request recorded — it is in the approval queue.')
      setForm(null)
      refresh()
    } catch (e) { setErr(apiError(e)) }
  }

  async function decide(row: Certificate, decision: 'approved' | 'rejected') {
    const who = fullName(row.student)
    const ok = await askConfirm({
      title: decision === 'approved' ? 'Approve this request?' : 'Reject this request?',
      text: decision === 'approved'
        ? `${who} — ${label(row.type)} certificate${row.purpose ? ` for ${row.purpose}` : ''}. Once approved it can be issued with a serial number.`
        : `${who} — ${label(row.type)} certificate. A rejected request cannot be decided a second time; the desk must raise a fresh one.`,
      confirmLabel: decision === 'approved' ? 'Approve' : 'Reject',
      tone: decision === 'approved' ? 'primary' : 'danger',
    })
    if (!ok) return
    setBusy(row.id)
    try {
      const r = await api.post(`/certificates/${row.id}/decide`, { decision })
      toast.success(r.data.message ?? `Request ${decision}.`)
      refresh()
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  async function issue(row: Certificate) {
    const ok = await askConfirm({
      title: 'Issue this certificate?',
      text: `${fullName(row.student)} — ${label(row.type)}. A serial number is minted for this organization and never reused.`,
      confirmLabel: 'Issue certificate',
      tone: 'primary',
    })
    if (!ok) return
    setBusy(row.id)
    try {
      const r = await api.post(`/certificates/${row.id}/issue`)
      toast.success(r.data.message ?? 'Certificate issued.')
      refresh()
      const p = await api.get(`/certificates/${row.id}/print`)
      setSheet(p.data.data as PrintSheet)
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  async function showPrint(row: Certificate) {
    setBusy(row.id)
    try {
      const p = await api.get(`/certificates/${row.id}/print`)
      setSheet(p.data.data as PrintSheet)
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  async function copySheet() {
    if (!sheet) return
    try {
      await navigator.clipboard.writeText(sheet.text)
      toast.success('Certificate wording copied.')
    } catch {
      toast.error('The browser blocked copying — select the wording and copy it manually.')
    }
  }

  return (
    <div>
      <PageHeader title="Certificates & Documents" subtitle="Requests, approvals and the issued register for the active campus"
        icon={<IconCertificate className="h-5 w-5" />}
        actions={canManage && (
          <Btn onClick={newDraft} disabled={!campusId} icon={<IconPlus className="h-4 w-4" />}>New request</Btn>
        )} />

      {!campusId && <Notice tone="amber" title="Select a campus" className="mb-4">Certificates are raised and issued per campus — pick one from the header first.</Notice>}
      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Awaiting decision" value={stats?.pending ?? '—'} hint="Raised by the desk or a class teacher" icon={<IconClock className="h-4 w-4" />} tone="amber" />
        <StatCard label="Approved, not issued" value={stats?.awaiting_issue ?? '—'} hint="Ready for a serial number" icon={<IconCheck className="h-4 w-4" />} tone="blue" />
        <StatCard label="Issued" value={stats?.issued ?? '—'} hint={`${stats?.issued_this_month ?? 0} this month`} icon={<IconCertificate className="h-4 w-4" />} tone="green" />
        <StatCard label="Rejected" value={stats?.rejected ?? '—'} hint="A fresh request is needed to re-apply" icon={<IconClose className="h-4 w-4" />} tone="rose" />
      </div>

      <Tabs active={status} onChange={setStatus} tabs={[
        { key: '', label: 'All', count: stats ? stats.pending + stats.approved + stats.issued + stats.rejected : undefined },
        { key: 'pending', label: 'Awaiting decision', count: stats?.pending },
        { key: 'approved', label: 'Approved', count: stats?.approved },
        { key: 'issued', label: 'Issued', count: stats?.issued },
        { key: 'rejected', label: 'Rejected', count: stats?.rejected },
      ]} />

      <Toolbar>
        <SearchInput className="w-72 max-w-full" value={search} onChange={setSearch} placeholder="Search student, admission no, reference…" />
        <div className="w-44">
          <select className={selectCls} aria-label="Certificate type" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">All types</option>
            {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
          </select>
        </div>
      </Toolbar>

      <Table headers={<>
        <Th>Student</Th><Th>Type</Th><Th>Purpose</Th><Th>Status</Th><Th>Reference</Th><Th>Requested by</Th><Th>Decided</Th>
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
            <Td className="whitespace-nowrap">{label(r.type)}</Td>
            <Td className="max-w-xs truncate" >{r.purpose ?? '—'}</Td>
            <Td><Status value={r.status} dot /></Td>
            <Td className="nums whitespace-nowrap text-xs">{r.reference_no ?? '—'}</Td>
            <Td className="text-xs">{r.requester?.name ?? '—'}</Td>
            <Td className="nums whitespace-nowrap text-xs">{r.decided_at ? fmtDateTime(r.decided_at) : '—'}</Td>
            {canManage && (
              <Td className="text-right whitespace-nowrap">
                <div className="flex flex-wrap justify-end gap-1">
                  {r.status === 'pending' && <>
                    <Btn size="sm" variant="ghost" onClick={() => editDraft(r)}>Edit</Btn>
                    <Btn size="sm" variant="soft" disabled={busy === r.id} onClick={() => void decide(r, 'rejected')}>Reject</Btn>
                    <Btn size="sm" disabled={busy === r.id} onClick={() => void decide(r, 'approved')} icon={<IconCheck className="h-3.5 w-3.5" />}>Approve</Btn>
                  </>}
                  {r.status === 'approved' && (
                    <Btn size="sm" disabled={busy === r.id} onClick={() => void issue(r)} icon={<IconCertificate className="h-3.5 w-3.5" />}>Issue</Btn>
                  )}
                  {(r.status === 'issued' || r.status === 'approved') && (
                    <Btn size="sm" variant="ghost" disabled={busy === r.id} onClick={() => void showPrint(r)}>
                      {r.status === 'issued' ? 'Print' : 'Preview'}
                    </Btn>
                  )}
                </div>
              </Td>
            )}
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text={status ? `Nothing in “${label(status)}”.` : 'No certificate requests yet.'}
            hint={canManage ? 'Use New request to log what the guardian applied for.' : 'Requests raised at your campus appear here.'} /></Td></tr>
        )}
      </Table>

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? 'Edit certificate request' : 'New certificate request'}
          subtitle="What was applied for, and who applied for it"
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={() => void save()}>{form.id ? 'Save changes' : 'Record request'}</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Student">
              <select className={selectCls} value={form.student_id} disabled={!!form.id}
                onChange={(e) => setForm({ ...form, student_id: e.target.value })}>
                <option value="">Choose a student…</option>
                {students.map((s) => (
                  <option key={s.id} value={s.id}>
                    {fullName(s)} · {s.admission_no}{s.school_class?.name ? ` · ${s.school_class.name}` : ''}
                  </option>
                ))}
              </select>
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Type">
                <select className={selectCls} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                  {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
                </select>
              </Field>
              <Field label="Fee (PKR)" hint="Leave empty when the certificate is free">
                <input className={inputCls} inputMode="decimal" value={form.fee} onChange={(e) => setForm({ ...form, fee: e.target.value })} />
              </Field>
            </div>
            <Field label="Addressed to (optional)" hint="Shown as the heading, e.g. “To the Visa Officer, UAE Embassy”. Default: To whom it may concern">
              <input className={inputCls} maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            </Field>
            <Field label="Purpose">
              <textarea className={`${inputCls} min-h-20`} maxLength={1000} value={form.purpose}
                placeholder="Opening a bank account, scholarship application, BISE attestation…"
                onChange={(e) => setForm({ ...form, purpose: e.target.value })} />
            </Field>
            <p className="text-xs text-ink-soft">The wording is assembled from the student's live record when the certificate is printed, so a corrected profile reaches every certificate issued afterwards.</p>
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}

      {sheet && (
        <Modal size="lg" onClose={() => setSheet(null)} title="Certificate wording"
          subtitle={sheet.reference_no ? `Reference ${sheet.reference_no}` : 'No serial number allotted yet'}
          footer={<>
            <Btn variant="ghost" onClick={() => setSheet(null)}>Close</Btn>
            {!sheet.printable && <Badge tone="amber">Not issued yet</Badge>}
            <Btn onClick={() => void copySheet()} icon={<IconCheck className="h-4 w-4" />}>Copy wording</Btn>
          </>}>
          <div className="rounded-xl border border-line bg-white px-6 py-7 shadow-card">
            <p className="text-center text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-soft">{sheet.campus_name}</p>
            {sheet.text.split('\n\n').map((part, i, all) => (
              <p key={i} className={`whitespace-pre-line ${
                i === 0
                  ? 'mt-4 text-center text-base font-semibold uppercase tracking-wide text-slate-900'
                  : i === all.length - 1
                    ? 'mt-6 text-right text-[13px] font-medium text-slate-600'
                    : 'mt-4 text-sm leading-7 text-slate-700'
              }`}>{part}</p>
            ))}
          </div>
        </Modal>
      )}
    </div>
  )
}
