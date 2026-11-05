import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { IconPlus, IconTimetable } from '../components/icons'
import { Btn, Card, Empty, ErrorNote, Field, Loading, Modal, PageHeader, inputCls, selectCls } from '../components/ui'
import { askConfirm } from '../components/Confirm'

interface Slot {
  id: number; day_of_week: number; start_time: string; end_time: string; room?: string
  section: { id: number; name: string; school_class?: { name: string } }
  section_id?: number; subject_id?: number; teacher_id?: number | null
  subject: { name: string; code: string }
  teacher?: { name: string } | null
}

interface StaffOption { id: number; full_name: string; user_id: number | null }
interface SlotForm {
  id?: number
  section_id: string
  subject_id: string
  teacher_id: string
  day_of_week: string
  start_time: string
  end_time: string
  room: string
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const DAYS_SHOWN = [1, 2, 3, 4, 5, 6]
const GRID_COLS = DAYS_SHOWN.length + 1

export default function Timetable() {
  const { data: sections } = useApi<{ data: { id: number; name: string; school_class?: { name: string } }[] }>('/sections')
  const { data: subjects } = useApi<{ data: { id: number; name: string; code: string }[] }>('/subjects')
  const { data: staff } = useApi<{ data: { data: StaffOption[] } }>('/staff?per_page=100&status=active')
  const [sectionId, setSectionId] = useState<number | null>(null)
  const effective = sectionId ?? sections?.data[0]?.id ?? null
  const { data, loading, error: loadError, reload } = useApi<{ data: Slot[] }>(effective ? `/timetable?section_id=${effective}` : null, [effective])
  const slots = data?.data ?? []
  const [form, setForm] = useState<SlotForm | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const times = Array.from(new Set(slots.map((s) => s.start_time))).sort()

  function newSlot() {
    setError('')
    setForm({
      section_id: String(effective ?? ''),
      subject_id: String(subjects?.data[0]?.id ?? ''),
      teacher_id: '',
      day_of_week: '1',
      start_time: '08:00',
      end_time: '08:40',
      room: '',
    })
  }

  async function saveSlot() {
    if (!form) return
    setBusy(true)
    setError('')
    const { id, ...fields } = form
    const payload = {
      ...fields,
      section_id: Number(fields.section_id),
      subject_id: Number(fields.subject_id),
      day_of_week: Number(fields.day_of_week),
      teacher_id: fields.teacher_id ? Number(fields.teacher_id) : null,
    }
    try {
      if (id) await api.put(`/timetable/${id}`, payload)
      else await api.post('/timetable', payload)
      setForm(null)
      reload()
    } catch (e) {
      setError(apiError(e))
    } finally {
      setBusy(false)
    }
  }

  async function removeSlot() {
    if (!form?.id) return
    if (!await askConfirm({
      title: 'Remove this period?',
      text: `${DAYS[Number(form.day_of_week) - 1]} · ${form.start_time}–${form.end_time} will be deleted from the timetable. Attendance already taken is not affected.`,
      confirmLabel: 'Remove period',
    })) return
    setError('')
    try {
      await api.delete(`/timetable/${form.id}`)
      setForm(null)
      reload()
    } catch (e) {
      setError(apiError(e))
    }
  }

  return (
    <div>
      <PageHeader title="Timetable" subtitle="Weekly periods for the selected section" icon={<IconTimetable className="h-5 w-5" />}
        actions={
          <>
            <div className="w-52">
              <select aria-label="Select timetable section" className={selectCls} value={effective ?? ''} onChange={(e) => setSectionId(Number(e.target.value))}>
                {(sections?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.school_class?.name} · {s.name}</option>)}
              </select>
            </div>
            <Btn onClick={newSlot} disabled={!effective} icon={<IconPlus className="h-4 w-4" />}>Add Period</Btn>
          </>
        } />
      {loadError && <ErrorNote text={loadError} onRetry={reload} className="mb-4" />}
      {loading && !data
        ? <Loading label="Loading timetable…" />
        : (
          <Card className="p-0">
            <div className="scroll-slim overflow-x-auto">
              <table className="min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                  <tr>
                    <th scope="col" className="sticky left-0 z-20 border-b border-line bg-canvas px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Period</th>
                    {DAYS_SHOWN.map((d) => (
                      <th key={d} scope="col" className="w-40 border-b border-l border-line bg-canvas px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">{DAYS[d - 1]}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {times.map((t) => (
                    <tr key={t} className="group">
                      <th scope="row" className="nums sticky left-0 z-10 border-b border-line bg-white px-3 py-2 text-left text-xs font-semibold text-ink-soft transition-colors group-hover:bg-brand-50">{t}</th>
                      {DAYS_SHOWN.map((d) => {
                        const slot = slots.find((s) => s.day_of_week === d && s.start_time === t)
                        return (
                          <td key={d} className={`border-b border-l border-line p-1.5 align-top transition-colors ${slot ? '' : 'hover:bg-brand-50/40'}`}>
                            {slot ? (
                              <button className="w-full rounded-xl bg-brand-50 px-2.5 py-2 text-left text-brand-800 ring-1 ring-inset ring-brand-100 transition hover:bg-brand-100 hover:ring-brand-200 active:scale-[.99]" onClick={() => {
                                setError('')
                                setForm({
                                  id: slot.id,
                                  section_id: String(slot.section_id ?? effective ?? ''),
                                  subject_id: String(slot.subject_id ?? subjects?.data.find((s) => s.name === slot.subject.name)?.id ?? ''),
                                  teacher_id: String(slot.teacher_id ?? ''),
                                  day_of_week: String(slot.day_of_week),
                                  start_time: slot.start_time.slice(0, 5),
                                  end_time: slot.end_time.slice(0, 5),
                                  room: slot.room ?? '',
                                })
                              }}>
                                <p className="truncate text-[13px] font-semibold text-brand-900">{slot.subject.name}</p>
                                <p className="nums mt-0.5 text-[11px]">{slot.start_time.slice(0, 5)}–{slot.end_time.slice(0, 5)}</p>
                                <p className="mt-0.5 truncate text-[11px]">{slot.teacher?.name ?? 'Unassigned'}</p>
                                <p className="truncate text-[11px] opacity-80">{slot.room ? `Room ${slot.room}` : 'No room'}</p>
                              </button>
                            ) : <span className="text-[11px] text-slate-300">free</span>}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                  {!times.length && !loading && (
                    <tr><td colSpan={GRID_COLS} className="border-l border-line"><Empty text="No periods scheduled for this section yet." icon={<IconTimetable className="h-5 w-5" />} hint="Use Add Period to place the first class in the weekly grid." /></td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? 'Edit Period' : 'Add Period'}
          subtitle={`${DAYS[Number(form.day_of_week) - 1]} · ${form.start_time}–${form.end_time}`}
          footer={<>
            {form.id && <Btn variant="danger" onClick={removeSlot}>Remove</Btn>}
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={saveSlot} disabled={busy}>{busy ? 'Saving…' : 'Save period'}</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Section">
              <select className={selectCls} value={form.section_id} onChange={(e) => setForm({ ...form, section_id: e.target.value })}>
                {(sections?.data ?? []).map((section) => <option key={section.id} value={section.id}>{section.school_class?.name} · {section.name}</option>)}
              </select>
            </Field>
            <Field label="Subject">
              <select className={selectCls} value={form.subject_id} onChange={(e) => setForm({ ...form, subject_id: e.target.value })}>
                {(subjects?.data ?? []).map((subject) => <option key={subject.id} value={subject.id}>{subject.name} ({subject.code})</option>)}
              </select>
            </Field>
            <Field label="Teacher">
              <select className={selectCls} value={form.teacher_id} onChange={(e) => setForm({ ...form, teacher_id: e.target.value })}>
                <option value="">Not assigned</option>
                {(staff?.data.data ?? []).filter((person) => person.user_id).map((person) => <option key={person.id} value={person.user_id!}>{person.full_name}</option>)}
              </select>
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Day">
                <select className={selectCls} value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value })}>
                  {DAYS.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}
                </select>
              </Field>
              <Field label="Room"><input className={inputCls} value={form.room} onChange={(e) => setForm({ ...form, room: e.target.value })} /></Field>
              <Field label="Start time"><input type="time" className={inputCls} value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} /></Field>
              <Field label="End time"><input type="time" className={inputCls} value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} /></Field>
            </div>
            {error && <div role="alert"><ErrorNote text={error} /></div>}
          </div>
        </Modal>
      )}
    </div>
  )
}
