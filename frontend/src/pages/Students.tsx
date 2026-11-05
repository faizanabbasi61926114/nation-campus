import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { IconPlus, IconStudents } from '../components/icons'
import { label } from '../lib/labels'
import { Btn, Empty, ErrorNote, Field, Modal, PageHeader, SearchInput, Status, Table, Td, Th, Toolbar, inputCls, selectCls } from '../components/ui'

interface Student {
  id: number; admission_no: string; roll_no?: string; first_name: string; last_name: string; gender: string
  dob: string; father_name: string; mother_name?: string; cnic_bform?: string
  guardian_name?: string; guardian_phone?: string; address?: string; city?: string; status: string
  class_id?: number | null; section_id?: number | null; program_id?: number | null; academic_year_id?: number | null; notes?: string
  school_class?: { id: number; name: string }; section?: { name: string }
}

export default function Students() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const { data, loading, error, reload } = useApi<{ data: Student[]; total?: number; last_page?: number }>(`/students?per_page=50&search=${encodeURIComponent(search)}${status ? `&status=${status}` : ''}`, [search, status])
  const { data: classes } = useApi<{ data: { id: number; name: string; program_id: number }[] }>('/classes')
  const { data: sections } = useApi<{ data: { id: number; name: string; class_id: number }[] }>('/sections')
  const { data: programs } = useApi<{ data: { id: number; name: string }[] }>('/programs')
  const [form, setForm] = useState<Partial<Student> & { $new?: boolean } | null>(null)
  const [err, setErr] = useState('')
  const rows = data?.data ?? []
  const C = 6

  async function save() {
    if (!form) return
    setErr('')
    const body: Record<string, unknown> = { ...form }
    delete body.$new
    delete body.school_class; delete body.section
    try {
      if (form.$new) await api.post('/students', body)
      else await api.put(`/students/${form.id}`, body)
      setForm(null); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div>
      <PageHeader title="Students"
        subtitle={`${rows.length} shown${typeof data?.total === 'number' ? ` of ${data.total}` : ''} in active campus scope`}
        icon={<IconStudents className="h-5 w-5" />}
        actions={<Btn onClick={() => setForm({ $new: true, first_name: '', last_name: '', gender: 'male', dob: '2012-01-01', father_name: '' })} icon={<IconPlus className="h-4 w-4" />}>Add Student</Btn>} />
      <Toolbar>
        <SearchInput className="w-72 max-w-full" value={search} onChange={setSearch} placeholder="Search name, admission/roll no…" />
        <div className="w-40">
          <select className={selectCls} aria-label="Student status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {['active', 'inactive', 'graduated', 'transferred', 'suspended'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
          </select>
        </div>
      </Toolbar>
      <Table headers={<><Th>Admission #</Th><Th>Roll #</Th><Th>Name</Th><Th>Class</Th><Th>Guardian</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((s) => (
          <tr key={s.id} className="cursor-pointer" onClick={() => setForm(s)}>
            <Td className="nums text-xs">{s.admission_no}</Td>
            <Td className="nums">{s.roll_no || '—'}</Td>
            <Td className="font-medium">{s.first_name} {s.last_name}<span className="ml-2 text-xs text-slate-400">{label(s.gender)}</span></Td>
            <Td>{s.school_class?.name ?? '—'} {s.section ? `· ${s.section.name}` : ''}</Td>
            <Td className="nums text-xs">{s.guardian_phone || '—'}</Td>
            <Td><Status value={s.status} dot /></Td>
          </tr>
        ))}
        {!rows.length && !loading && <tr><Td colSpan={C}><Empty text="No students match this search or status." hint="Clear the filters, or add a student with Add Student." /></Td></tr>}
      </Table>

      {form && (
        <Modal size="lg" onClose={() => setForm(null)}
          title={form.$new ? 'New Student' : `${form.first_name} ${form.last_name}`}
          subtitle={form.$new ? 'Enrol a student into the active campus' : form.admission_no}
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={save}>Save</Btn>
          </>}>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label="First name"><input className={inputCls} value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} /></Field>
              <Field label="Last name"><input className={inputCls} value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} /></Field>
            </div>
            <Field label="Father name"><input className={inputCls} value={form.father_name || ''} onChange={(e) => setForm({ ...form, father_name: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Mother name"><input className={inputCls} value={form.mother_name || ''} onChange={(e) => setForm({ ...form, mother_name: e.target.value })} /></Field>
              <Field label="CNIC / B-Form"><input className={inputCls} value={form.cnic_bform || ''} onChange={(e) => setForm({ ...form, cnic_bform: e.target.value })} /></Field>
            </div>
            {form.$new && <>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Gender"><select className={selectCls} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}><option value="male">{label('male')}</option><option value="female">{label('female')}</option><option value="other">{label('other')}</option></select></Field>
                <Field label="Date of birth"><input type="date" className={inputCls} value={form.dob} onChange={(e) => setForm({ ...form, dob: e.target.value })} /></Field>
              </div>
            </>}
            {!form.$new && (
              <div className="grid grid-cols-2 gap-3">
                <Field label="Gender"><select className={selectCls} value={form.gender || 'male'} onChange={(e) => setForm({ ...form, gender: e.target.value })}><option value="male">{label('male')}</option><option value="female">{label('female')}</option><option value="other">{label('other')}</option></select></Field>
                <Field label="Date of birth"><input type="date" className={inputCls} value={form.dob || ''} onChange={(e) => setForm({ ...form, dob: e.target.value })} /></Field>
              </div>
            )}
            <div className="grid grid-cols-2 gap-3">
              <Field label="Guardian name"><input className={inputCls} value={form.guardian_name || ''} onChange={(e) => setForm({ ...form, guardian_name: e.target.value })} /></Field>
              <Field label="Guardian phone"><input className={inputCls} value={form.guardian_phone || ''} onChange={(e) => setForm({ ...form, guardian_phone: e.target.value })} placeholder="+92 300 1234567" /></Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="City"><input className={inputCls} value={form.city || ''} onChange={(e) => setForm({ ...form, city: e.target.value })} /></Field>
              <Field label="Roll no"><input className={inputCls} value={form.roll_no || ''} onChange={(e) => setForm({ ...form, roll_no: e.target.value })} /></Field>
            </div>
            <Field label="Address"><textarea className={`${inputCls} min-h-16`} value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Program">
                <select className={selectCls} value={form.program_id ?? ''} onChange={(e) => setForm({ ...form, program_id: e.target.value ? Number(e.target.value) : null })}>
                  <option value="">Unassigned</option>
                  {(programs?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </Field>
              <Field label="Class">
                <select className={selectCls} value={form.class_id ?? ''} onChange={(e) => setForm({ ...form, class_id: e.target.value ? Number(e.target.value) : null, section_id: null })}>
                  <option value="">Unassigned</option>
                  {(classes?.data ?? []).filter((c) => !form.program_id || c.program_id === form.program_id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
            </div>
            <Field label="Section">
              <select className={selectCls} value={form.section_id ?? ''} onChange={(e) => setForm({ ...form, section_id: e.target.value ? Number(e.target.value) : null })}>
                <option value="">Unassigned</option>
                {(sections?.data ?? []).filter((s) => !form.class_id || s.class_id === form.class_id).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <Field label="Notes"><textarea className={`${inputCls} min-h-16`} value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Roll no"><input className={inputCls} value={form.roll_no || ''} onChange={(e) => setForm({ ...form, roll_no: e.target.value })} /></Field>
              {!form.$new && (
                <Field label="Status">
                  <select className={selectCls} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                    {['active', 'inactive', 'graduated', 'transferred', 'suspended'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
                  </select>
                </Field>
              )}
            </div>
            {err && <ErrorNote text={err} className="mt-1" />}
          </div>
        </Modal>
      )}
    </div>
  )
}
