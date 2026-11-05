import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { IconAcademics, IconPlus } from '../components/icons'
import { Badge, Btn, Empty, ErrorNote, Field, Modal, PageHeader, Table, Tabs, Td, Th, inputCls, selectCls } from '../components/ui'
import { label } from '../lib/labels'

interface Program { id: number; name: string; code: string; level: string; board?: string; duration_years: number; is_active?: boolean; classes: SchoolClass[] }
interface SchoolClass { id: number; name: string; level_no: number; campus_id: number; program_id: number; subjects?: Subject[]; sections?: Section[] }
interface Subject { id: number; name: string; code: string; group?: string; credit_hours?: number }
interface Section { id: number; name: string; capacity: number; students_count?: number; class_teacher?: { name: string } }

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

export default function Academics() {
  const [tab, setTab] = useState<'programs' | 'sections' | 'subjects'>('programs')
  const { data: pData, loading: pLoading, error: pError, reload: r1 } = useApi<{ data: Program[] }>(tab === 'programs' ? '/programs' : null)
  const { data: sData, loading: sLoading, error: sError, reload: r2 } = useApi<{ data: Section[] }>(tab === 'sections' ? '/sections' : null)
  const { data: subData, loading: subLoading, error: subError, reload: r3 } = useApi<{ data: Subject[] }>(tab === 'subjects' ? '/subjects' : null)
  const { data: classData, reload: r4 } = useApi<{ data: SchoolClass[] }>('/classes')
  const [form, setForm] = useState<Record<string, string> | null>(null)
  const [err, setErr] = useState('')

  async function save() {
    if (!form) return
    setErr('')
    const path = form.$t === 'program' ? '/programs'
      : form.$t === 'subject' ? '/subjects'
        : form.$t === 'class' ? '/classes'
          : form.$t === 'section' ? `/classes/${form.$parentId}/sections` : ''
    const updatePath = form.$t === 'program' ? `/programs/${form.$id}`
      : form.$t === 'subject' ? `/subjects/${form.$id}`
        : form.$t === 'class' ? `/classes/${form.$id}`
          : `/sections/${form.$id}`
    const body = { ...form }
    delete body.$t
    delete body.$id
    delete body.$parentId
    try {
      if (form.$id) await api.put(updatePath, body)
      else await api.post(path, body)
      setForm(null); r1(); r2(); r3(); r4()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div>
      <PageHeader title="Academics" subtitle="Programs, classes, sections and subjects" icon={<IconAcademics className="h-5 w-5" />}
        actions={<>
          <Btn variant="ghost" onClick={() => setForm({ $t: 'program', name: '', code: '', level: 'matric', board: 'BISE-LHR' })} icon={<IconPlus className="h-4 w-4" />}>Program</Btn>
          <Btn variant="ghost" onClick={() => setForm({ $t: 'class', name: '', program_id: pData?.data[0]?.id ? String(pData.data[0].id) : '', level_no: '9' })} icon={<IconPlus className="h-4 w-4" />}>Class</Btn>
          <Btn variant="ghost" onClick={() => setForm({ $t: 'subject', name: '', code: '', group: 'Science' })} icon={<IconPlus className="h-4 w-4" />}>Subject</Btn>
          {tab === 'sections' && <Btn variant="ghost" onClick={() => setForm({ $t: 'section', $parentId: String(classData?.data[0]?.id ?? ''), name: '', capacity: '30' })} icon={<IconPlus className="h-4 w-4" />}>Section</Btn>}
        </>} />
      <Tabs active={tab} onChange={(key) => setTab(key as typeof tab)}
        tabs={[
          { key: 'programs', label: 'Programs', count: pData?.data.length },
          { key: 'sections', label: 'Sections', count: sData?.data.length },
          { key: 'subjects', label: 'Subjects', count: subData?.data.length },
        ]} />

      {tab === 'programs' && (
        <Table headers={<><Th>Program</Th><Th>Code</Th><Th>Board</Th><Th>Level</Th><Th align="right">Years</Th><Th>Classes</Th><Th>Actions</Th></>}
          cols={7} loading={pLoading} error={pError} onRetry={r1}>
          {(pData?.data ?? []).map((p) => (
            <tr key={p.id}>
              <Td className="font-medium">
                {p.name}
                {p.is_active === false && <span className="ml-2 inline-flex"><Badge tone="slate">Inactive</Badge></span>}
                <p className="text-xs text-ink-soft">{p.duration_years} yrs · {p.classes.length} classes</p>
              </Td>
              <Td><Badge tone="blue">{p.code}</Badge></Td>
              <Td className="text-xs">{p.board ?? '—'}</Td>
              <Td>{label(p.level)}</Td>
              <Td className="nums text-right">{p.duration_years}</Td>
              <Td>
                <div className="flex flex-wrap gap-1">
                  {p.classes.map((c) => (
                    <Btn key={c.id} size="sm" variant="ghost" onClick={() => setForm({ $t: 'class', $id: String(c.id), name: c.name, program_id: String(c.program_id), level_no: String(c.level_no) })}>
                      {c.name} · {c.sections?.length ?? 0} sections
                    </Btn>
                  ))}
                  {!p.classes.length && <span className="text-xs text-ink-soft">No classes yet</span>}
                </div>
              </Td>
              <Td>
                <div className="flex flex-wrap gap-1">
                  <Btn size="sm" variant="soft" onClick={() => setForm({ $t: 'program', $id: String(p.id), name: p.name, code: p.code, level: p.level, board: p.board ?? '', duration_years: String(p.duration_years) })}>Edit</Btn>
                  <Btn size="sm" variant="ghost" onClick={() => setForm({ $t: 'class', program_id: String(p.id), name: '', level_no: '' })} icon={<IconPlus className="h-3.5 w-3.5" />}>Class</Btn>
                </div>
              </Td>
            </tr>
          ))}
          {!pData?.data.length && !pLoading && <tr><Td colSpan={7}><Empty text="No programmes defined for this organisation." hint="Add a Program to start building the academic structure." /></Td></tr>}
        </Table>
      )}
      {tab === 'sections' && (
        <Table headers={<><Th>Class</Th><Th>Section</Th><Th align="right">Students</Th><Th align="right">Capacity</Th><Th>Class Teacher</Th><Th>Actions</Th></>}
          cols={6} loading={sLoading} error={sError} onRetry={r2}>
          {(sData?.data ?? []).map((s) => (
            <tr key={s.id}>
              <Td>{(s as Section & { school_class?: { name: string } }).school_class?.name ?? '—'}</Td>
              <Td className="font-medium">{s.name}</Td>
              <Td className="nums text-right">{s.students_count ?? 0}</Td>
              <Td className="nums text-right">{s.capacity}</Td>
              <Td>{s.class_teacher?.name ?? 'Unassigned'}</Td>
              <Td><Btn size="sm" variant="ghost" onClick={() => setForm({ $t: 'section', $id: String(s.id), $parentId: '', name: s.name, capacity: String(s.capacity) })}>Edit</Btn></Td>
            </tr>
          ))}
          {!sData?.data.length && !sLoading && <tr><Td colSpan={6}><Empty text="No sections yet." hint="Create a class first, then add its sections." /></Td></tr>}
        </Table>
      )}
      {tab === 'subjects' && (
        <Table headers={<><Th>Subject</Th><Th>Code</Th><Th>Group</Th><Th align="right">Credit Hours</Th><Th>Actions</Th></>}
          cols={5} loading={subLoading} error={subError} onRetry={r3}>
          {(subData?.data ?? []).map((s) => (
            <tr key={s.id}>
              <Td className="font-medium">{s.name}</Td>
              <Td className="nums">{s.code}</Td>
              <Td>{s.group}</Td>
              <Td className="nums text-right">{s.credit_hours ?? '—'}</Td>
              <Td><Btn size="sm" variant="ghost" onClick={() => setForm({ $t: 'subject', $id: String(s.id), name: s.name, code: s.code, group: s.group ?? '', credit_hours: String(s.credit_hours ?? '') })}>Edit</Btn></Td>
            </tr>
          ))}
          {!subData?.data.length && !subLoading && <tr><Td colSpan={5}><Empty text="No subjects defined yet." /></Td></tr>}
        </Table>
      )}

      {form && (
        <Modal size="sm" onClose={() => setForm(null)}
          title={`${form.$id ? 'Edit' : 'New'} ${cap(form.$t)}`}
          subtitle={form.$id ? undefined : `Create a new ${form.$t}`}
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={save}>{form.$id ? 'Save changes' : 'Create'}</Btn>
          </>}>
          <div className="space-y-3">
            {(['name', 'code', 'group', 'board', 'level'] as const).filter((k) => form[k] !== undefined && (form.$t === 'program' ? ['name', 'code', 'level', 'board'].includes(k) : k !== 'level' || form.$t === 'program')).map((k) => (
              <Field key={k} label={label(k)}>
                {k === 'level' || k === 'group' ? (
                  <select className={selectCls} value={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.value })}>
                    {(k === 'level' ? ['primary', 'middle', 'matric', 'intermediate', 'undergrad'] : ['Compulsory', 'Science', 'Arts', 'Elective']).map((o) => <option key={o} value={o}>{label(o)}</option>)}
                  </select>
                ) : (
                  <input className={inputCls} value={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
                )}
              </Field>
            ))}
            {form.$t === 'class' && (
              <Field label="Program">
                <select className={selectCls} value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })}>
                  {(pData?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </Field>
            )}
            {form.$t === 'class' && <Field label="Level No"><input className={inputCls} value={form.level_no || ''} onChange={(e) => setForm({ ...form, level_no: e.target.value })} /></Field>}
            {(form.$t === 'program' || form.$t === 'subject' || form.$t === 'section') && (
              <Field label={form.$t === 'program' ? 'Duration (years)' : form.$t === 'subject' ? 'Credit hours' : 'Capacity'}>
                <input type="number" min="1" className={inputCls}
                  value={form[form.$t === 'program' ? 'duration_years' : form.$t === 'subject' ? 'credit_hours' : 'capacity'] ?? (form.$t === 'section' ? '30' : '')}
                  onChange={(e) => setForm({ ...form, [form.$t === 'program' ? 'duration_years' : form.$t === 'subject' ? 'credit_hours' : 'capacity']: e.target.value })} />
              </Field>
            )}
            {err && <ErrorNote text={err} className="mt-1" />}
          </div>
        </Modal>
      )}
    </div>
  )
}
