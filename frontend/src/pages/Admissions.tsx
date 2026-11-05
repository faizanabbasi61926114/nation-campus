import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { toast } from '../components/Toast'
import { IconAdmissions, IconPlus } from '../components/icons'
import { label } from '../lib/labels'
import { Btn, Empty, ErrorNote, Field, Modal, PageHeader, Status, Table, Tabs, Td, Th, inputCls, selectCls } from '../components/ui'

interface Admission {
  id: number; application_no: string; applicant_name: string; gender: string; dob: string; father_name: string
  mother_name?: string; cnic_bform?: string; class_id?: number | null; program_id?: number; phone: string
  previous_school?: string; previous_marks?: number; status: string; program?: { name: string }
  remarks?: string
}

const NEXT: Record<string, string[]> = {
  pending: ['screening', 'rejected'], screening: ['interview', 'test', 'rejected', 'waitlisted'],
  interview: ['test', 'offered', 'rejected'], test: ['offered', 'rejected', 'waitlisted'],
  offered: ['accepted', 'rejected'], accepted: ['enrolled', 'withdrawn'], waitlisted: ['offered', 'rejected'],
}

export default function Admissions() {
  const [status, setStatus] = useState('')
  const { data, loading, error, reload } = useApi<{ data: Admission[] }>(`/admissions?per_page=50${status ? `&status=${status}` : ''}`, [status])
  const { data: programs } = useApi<{ data: { id: number; name: string }[] }>('/programs')
  const [form, setForm] = useState<Record<string, string> | null>(null)
  const [err, setErr] = useState('')
  const rows = data?.data ?? []
  const C = 6

  async function act(id: number, to: string) {
    try { await api.post(`/admissions/${id}/transition`, { to }); reload() } catch (e) { toast.error(apiError(e)) }
  }
  async function enroll(id: number) {
    try {
      const r = await api.post(`/admissions/${id}/enroll`, {})
      await api.post(`/admissions/${id}/transition`, { to: 'enrolled' }).catch(() => undefined)
      toast.success(`Enrolled as ${r.data.data.admission_no}`)
      reload()
    } catch (e) { toast.error(apiError(e)) }
  }
  async function save() {
    if (!form) return
    setErr('')
    const { $id, ...payload } = form
    try {
      if ($id) await api.put(`/admissions/${$id}`, payload)
      else await api.post('/admissions', payload)
      setForm(null); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div>
      <PageHeader title="Admissions" subtitle="Application pipeline for the active campus" icon={<IconAdmissions className="h-5 w-5" />}
        actions={<Btn onClick={() => setForm({ applicant_name: '', gender: 'male', dob: '2012-01-01', father_name: '', mother_name: '', cnic_bform: '', phone: '', program_id: String(programs?.data[0]?.id ?? ''), previous_marks: '' })} icon={<IconPlus className="h-4 w-4" />}>New Application</Btn>} />
      <Tabs active={status} onChange={setStatus}
        tabs={['', 'pending', 'screening', 'test', 'offered', 'accepted', 'enrolled', 'rejected']
          .map((s) => ({ key: s, label: s ? label(s) : 'All' }))} />
      <Table headers={<><Th>App #</Th><Th>Applicant</Th><Th>Program</Th><Th align="right">Prev Marks</Th><Th>Status</Th><Th>Actions</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((a) => (
          <tr key={a.id}>
            <Td className="nums text-xs">{a.application_no}</Td>
            <Td className="font-medium">{a.applicant_name}<p className="text-xs text-slate-400">{a.previous_school}</p></Td>
            <Td>{a.program?.name ?? '—'}</Td>
            <Td className="nums text-right">{a.previous_marks ?? '—'}</Td>
            <Td><Status value={a.status} dot /></Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {!['enrolled', 'rejected', 'withdrawn'].includes(a.status) && (
                  <Btn size="sm" variant="soft" onClick={() => setForm({
                    $id: String(a.id), applicant_name: a.applicant_name, gender: a.gender, dob: a.dob,
                    father_name: a.father_name, mother_name: a.mother_name ?? '', cnic_bform: a.cnic_bform ?? '',
                    phone: a.phone, previous_school: a.previous_school ?? '', previous_marks: String(a.previous_marks ?? ''),
                    program_id: String(a.program_id ?? programs?.data.find((p) => p.name === a.program?.name)?.id ?? ''),
                    remarks: a.remarks ?? '',
                  })}>Edit</Btn>
                )}
                {(NEXT[a.status] ?? []).map((t) => (
                  <Btn key={t} size="sm" variant="ghost" onClick={() => act(a.id, t)}>{label(t)}</Btn>
                ))}
                {a.status === 'accepted' && <Btn size="sm" onClick={() => enroll(a.id)}>Enroll →</Btn>}
              </div>
            </Td>
          </tr>
        ))}
        {!rows.length && !loading && <tr><Td colSpan={C}><Empty text={status ? `No applications in “${label(status)}”.` : 'No applications yet.'} hint="Submit an application with New Application to start the pipeline." /></Td></tr>}
      </Table>

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.$id ? 'Edit Admission Application' : 'New Admission Application'}
          subtitle="Applicant details for the active campus"
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={save}>{form.$id ? 'Save changes' : 'Submit Application'}</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Applicant name"><input className={inputCls} value={form.applicant_name} onChange={(e) => setForm({ ...form, applicant_name: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Gender"><select className={selectCls} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}><option value="male">{label('male')}</option><option value="female">{label('female')}</option><option value="other">{label('other')}</option></select></Field>
              <Field label="DOB"><input type="date" className={inputCls} value={form.dob} onChange={(e) => setForm({ ...form, dob: e.target.value })} /></Field>
            </div>
            <Field label="Father name"><input className={inputCls} value={form.father_name} onChange={(e) => setForm({ ...form, father_name: e.target.value })} /></Field>
            <Field label="Mother name"><input className={inputCls} value={form.mother_name || ''} onChange={(e) => setForm({ ...form, mother_name: e.target.value })} /></Field>
            <Field label="CNIC / B-Form"><input className={inputCls} value={form.cnic_bform || ''} onChange={(e) => setForm({ ...form, cnic_bform: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Phone"><input className={inputCls} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+92 300 1234567" /></Field>
              <Field label="Prev marks %"><input className={inputCls} value={form.previous_marks} onChange={(e) => setForm({ ...form, previous_marks: e.target.value })} /></Field>
            </div>
            <Field label="Program">
              <select className={selectCls} value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })}>
                {(programs?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </Field>
            <Field label="Previous school"><input className={inputCls} value={form.previous_school || ''} onChange={(e) => setForm({ ...form, previous_school: e.target.value })} /></Field>
            <Field label="Remarks"><textarea className={`${inputCls} min-h-20`} value={form.remarks || ''} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></Field>
            {err && <ErrorNote text={err} className="mt-1" />}
          </div>
        </Modal>
      )}
    </div>
  )
}
