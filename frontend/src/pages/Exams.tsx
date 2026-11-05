import { useEffect, useMemo, useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import type { Paginated } from '../lib/types'
import { toast } from '../components/Toast'
import { IconExams, IconPlus } from '../components/icons'
import { label } from '../lib/labels'
import { Badge, Btn, Card, Empty, ErrorNote, Field, Loading, Modal, Notice, PageHeader, Status, Table, Tabs, Td, Th, Toolbar, inputCls, selectCls } from '../components/ui'

interface Exam {
  id: number; name: string; type: string; status: string; start_date: string; end_date?: string | null
  subjects_count?: number
}
interface ExamSubject { id: number; subject_id: number; max_marks: number; pass_marks: number; subject?: { name: string } }
interface Roster {
  exam: { id: number; name: string; status: string; subjects: ExamSubject[] }
  students: { id: number; roll_no?: string | null; first_name: string; last_name: string; marks: Record<string, { marks_obtained?: number | null; is_absent?: boolean }> }[]
}
interface CardData {
  exam: { name: string; status: string }
  student: { first_name: string; last_name: string; roll_no?: string; section?: string; class?: string }
  subjects: { subject: string; marks_obtained: number | null; max_marks: number; pass_marks: number; is_absent: boolean; passed: boolean; result?: string | null }[]
  total_marks: number; max_marks: number; percentage: number; grade?: string | null; result: string; published: boolean
}
interface MeritRow { id: number; first_name: string; last_name: string; roll_no?: string; total: number; out_of: number; percentage: number; position: number }

const NEXT: Record<string, string[]> = {
  draft: ['scheduled', 'archived'], scheduled: ['ongoing', 'draft', 'archived'], ongoing: ['completed', 'archived'],
  completed: ['published', 'ongoing'], published: ['archived'],
}
const TABS = [
  { key: 'exams', label: 'Exam list' },
  { key: 'marks', label: 'Marks entry' },
  { key: 'report', label: 'Report card' },
  { key: 'merit', label: 'Merit list' },
]

export default function Exams() {
  const { user } = useAuth()
  const can = (p: string) => !!user?.permissions?.includes(p) || !!user?.permissions?.includes('*')
  const [tab, setTab] = useState<'exams' | 'marks' | 'report' | 'merit'>('exams')
  const [examId, setExamId] = useState<number | null>(null)

  const { data: exams, loading, error, reload } = useApi<{ data: Exam[] }>('/exams')
  const list = exams?.data ?? []
  const effective = examId ?? list[0]?.id ?? null
  const exam = list.find((e) => e.id === effective) ?? null

  return (
    <div>
      <PageHeader title="Examinations" subtitle="Scheduling, marks entry, report cards and merit lists" icon={<IconExams className="h-5 w-5" />} />
      {!localStorage.getItem('cms_campus') && (
        <Notice tone="amber" className="mb-4">Select a campus to schedule exams.</Notice>
      )}
      <Tabs tabs={TABS} active={tab} onChange={(k) => setTab(k as typeof tab)} />
      {(tab !== 'exams' || can('exams.schedule')) && (
        <Toolbar>
          {tab !== 'exams' && (
            <div className="w-64">
              <select className={selectCls} aria-label="Examination" value={effective ?? ''} onChange={(e) => setExamId(Number(e.target.value))}>
                {list.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
              </select>
            </div>
          )}
          {tab === 'exams' && can('exams.schedule') && <NewExam onDone={reload} />}
        </Toolbar>
      )}

      {tab === 'exams' && (
        <Table headers={<><Th>Exam</Th><Th>Type</Th><Th>Dates</Th><Th align="right">Subjects</Th><Th>Status</Th>{can('exams.schedule') && <Th>Move to</Th>}</>}
          cols={6} loading={loading} error={error} onRetry={reload}>
          {list.map((e) => (
            <tr key={e.id}>
              <Td className="font-medium">{e.name}</Td>
              <Td><Status value={e.type} /></Td>
              <Td className="nums text-xs">{e.start_date}{e.end_date ? ` → ${e.end_date}` : ''}</Td>
              <Td className="nums text-right">{e.subjects_count ?? 0}</Td>
              <Td><Status value={e.status} dot /></Td>
              {can('exams.schedule') && (
                <Td>
                  <div className="flex flex-wrap gap-1">
                    {(NEXT[e.status] ?? []).map((s) => (
                      <Btn key={s} size="sm" variant="ghost"
                        onClick={() => api.put(`/exams/${e.id}`, { status: s }).then(reload).catch((err) => toast.error(apiError(err)))}>
                        {label(s)}
                      </Btn>
                    ))}
                  </div>
                </Td>
              )}
            </tr>
          ))}
          {!list.length && !loading && <tr><Td colSpan={6}><Empty text="No exams scheduled for this campus yet." hint="Use Schedule Exam to create a draft examination." /></Td></tr>}
        </Table>
      )}
      {tab === 'marks' && effective && <MarksEntry examId={effective} onChanged={reload} />}
      {tab === 'report' && effective && <ReportCard examId={effective} />}
      {tab === 'merit' && effective && <MeritList examId={effective} exam={exam} />}
    </div>
  )
}

function NewExam({ onDone }: { onDone: () => void }) {
  const { data: subjects } = useApi<{ data: { id: number; name: string; code: string }[] }>('/subjects')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', type: 'monthly', start_date: '', end_date: '' })
  const [picked, setPicked] = useState<Record<number, string>>({})
  const [err, setErr] = useState('')

  async function save() {
    setErr('')
    try {
      await api.post('/exams', {
        ...form,
        subjects: Object.entries(picked).map(([id, max]) => ({ subject_id: Number(id), max_marks: Number(max) || 100 })),
      })
      setOpen(false); setForm({ name: '', type: 'monthly', start_date: '', end_date: '' }); setPicked({}); onDone()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <>
      <Btn className="ml-auto" onClick={() => setOpen(true)} icon={<IconPlus className="h-4 w-4" />}>Schedule Exam</Btn>
      {open && (
        <Modal size="md" title="Schedule Examination" subtitle="Subjects and their maximum marks for this exam"
          onClose={() => setOpen(false)}
          footer={<>
            <Btn variant="ghost" onClick={() => setOpen(false)}>Cancel</Btn>
            <Btn onClick={save} disabled={!form.name || !form.start_date || !Object.keys(picked).length}>Create (draft)</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Name"><input className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Mid Term Examination" /></Field>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Type">
                <select className={selectCls} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                  {['unit_test', 'monthly', 'mid_term', 'annual', 'board_preview'].map((t) => <option key={t} value={t}>{label(t)}</option>)}
                </select>
              </Field>
              <Field label="Start"><input type="date" className={inputCls} value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></Field>
              <Field label="End"><input type="date" className={inputCls} value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></Field>
            </div>
            <Field label="Subjects & max marks">
              <div className="scroll-slim max-h-52 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                {(subjects?.data ?? []).map((s) => (
                  <label key={s.id} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="h-4 w-4 shrink-0 accent-brand-600" checked={picked[s.id] !== undefined}
                      onChange={(e) => { const n = { ...picked }; if (e.target.checked) n[s.id] = '100'; else delete n[s.id]; setPicked(n) }} />
                    <span className="min-w-0 flex-1 truncate">{s.name} <span className="text-xs text-slate-400">({s.code})</span></span>
                    {picked[s.id] !== undefined && (
                      <input className="nums w-16 shrink-0 rounded-lg border border-line-strong bg-white px-1.5 py-1 text-center text-xs outline-none transition focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15"
                        value={picked[s.id]} aria-label={`Max marks for ${s.name}`}
                        onChange={(e) => setPicked({ ...picked, [s.id]: e.target.value })} />
                    )}
                  </label>
                ))}
              </div>
            </Field>
            {err && <ErrorNote text={err} className="mt-1" />}
          </div>
        </Modal>
      )}
    </>
  )
}

function MarksEntry({ examId, onChanged }: { examId: number; onChanged: () => void }) {
  const { data: sections } = useApi<{ data: { id: number; name: string; school_class?: { name: string } }[] }>('/sections')
  const [sectionId, setSectionId] = useState<number | null>(null)
  const section = sectionId ?? sections?.data[0]?.id ?? null
  const key = section ? `/exams/${examId}/roster?section_id=${section}` : null
  const { data, loading, error, reload } = useApi<{ data: Roster }>(key, [key, examId])
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  useEffect(() => setDraft({}), [key])

  const roster = data?.data
  const subjects = roster?.exam.subjects ?? []
  const C = 2 + subjects.length
  const locked = roster ? ['published', 'archived'].includes(roster.exam.status) : false
  const unsaved = Object.keys(draft).length
  const summary = useMemo(() => {
    let filled = 0, absent = 0
    if (!roster) return { filled: 0, absent: 0, cells: 0 }
    for (const s of roster.students) {
      for (const es of roster.exam.subjects) {
        const existing = s.marks[String(es.id)]
        const v = draft[`${s.id}:${es.id}`] ?? (existing ? (existing.is_absent ? 'A' : String(existing.marks_obtained ?? '')) : '')
        if (v === 'A') { absent++; filled++ } else if (v !== '') filled++
      }
    }
    return { filled, absent, cells: roster.students.length * roster.exam.subjects.length }
  }, [roster, draft])

  async function save() {
    if (!roster) return
    const marks: { student_id: number; exam_subject_id: number; marks_obtained?: number; is_absent?: boolean }[] = []
    for (const s of roster.students) {
      for (const es of roster.exam.subjects) {
        const v = draft[`${s.id}:${es.id}`]
        if (v === undefined) continue
        if (v === 'A') marks.push({ student_id: s.id, exam_subject_id: es.id, marks_obtained: undefined, is_absent: true })
        else if (v !== '') marks.push({ student_id: s.id, exam_subject_id: es.id, marks_obtained: Number(v) })
      }
    }
    if (!marks.length) return
    setSaving(true)
    try { await api.post(`/exams/${examId}/marks`, { marks }); setDraft({}); reload(); onChanged() }
    catch (e) { toast.error(apiError(e)) } finally { setSaving(false) }
  }

  return (
    <div>
      <Toolbar>
        <div className="w-52">
          <select className={selectCls} aria-label="Section" value={section ?? ''} onChange={(e) => setSectionId(Number(e.target.value))}>
            {(sections?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.school_class?.name} · {s.name}</option>)}
          </select>
        </div>
        {locked && <Badge tone="green" dot>Published — marks locked</Badge>}
        {!locked && unsaved > 0 && <Badge tone="amber" dot>{unsaved} unsaved change(s)</Badge>}
        {!locked && canEdit() && (
          <Btn className="ml-auto" onClick={save} disabled={saving || !Object.keys(draft).length}>
            {saving ? 'Saving…' : `Save ${Object.keys(draft).length || ''} change(s)`}
          </Btn>
        )}
      </Toolbar>
      {error && <ErrorNote text={error} onRetry={reload} className="mb-3" />}
      {loading && !roster
        ? <Loading label="Loading roster…" />
        : (
          <Card className="p-0">
            <div className="scroll-slim max-h-[70vh] overflow-auto">
              <table className="min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                  <tr>
                    <th scope="col" className="sticky top-0 z-10 whitespace-nowrap border-b border-line bg-canvas px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Roll #</th>
                    <th scope="col" className="sticky top-0 z-10 whitespace-nowrap border-b border-line bg-canvas px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Student</th>
                    {subjects.map((es) => (
                      <th key={es.id} scope="col" className="nums sticky top-0 z-10 border-b border-l border-line bg-canvas px-2 py-2 text-center text-[11px] font-semibold text-slate-600">
                        {es.subject?.name}
                        <p className="text-[10px] font-normal text-ink-soft">/{es.max_marks} · pass {es.pass_marks}</p>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {(roster?.students ?? []).map((s, i) => (
                    <tr key={s.id} className={`transition-colors hover:bg-brand-50/60 ${i % 2 ? 'bg-canvas/40' : ''}`}>
                      <td className="nums border-b border-line px-3 py-1.5 text-xs">{s.roll_no ?? `#${s.id}`}</td>
                      <td className="border-b border-line px-3 py-1.5 font-medium">{s.first_name} {s.last_name}</td>
                      {subjects.map((es) => {
                        const existing = s.marks[String(es.id)]
                        const initial = existing ? (existing.is_absent ? 'A' : String(existing.marks_obtained ?? '')) : ''
                        const v = draft[`${s.id}:${es.id}`] ?? initial
                        const dirty = draft[`${s.id}:${es.id}`] !== undefined && draft[`${s.id}:${es.id}`] !== initial
                        return (
                          <td key={es.id} className={`border-b border-l border-line p-1 text-center ${dirty ? 'bg-gold-50' : ''}`}>
                            <span className="mx-auto block w-16">
                              <input className={`${inputCls} nums text-right`} value={v} disabled={locked}
                                onChange={(e) => setDraft({ ...draft, [`${s.id}:${es.id}`]: e.target.value })}
                                aria-label={`${s.first_name} ${s.last_name} — ${es.subject?.name}`}
                                title={`Max ${es.max_marks}; type A for absent`} />
                            </span>
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                  {!roster?.students.length && (
                    <tr><Td colSpan={C}><Empty text="No students in this section." hint="Enrol students before entering marks." /></Td></tr>
                  )}
                </tbody>
                {!!roster?.students.length && (
                  <tfoot>
                    <tr>
                      <td colSpan={2} className="border-t border-line-strong bg-canvas/70 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Summary</td>
                      <td colSpan={subjects.length} className="nums border-t border-line-strong bg-canvas/70 px-3 py-2 text-left text-xs text-ink-soft">
                        {roster.students.length} students · {summary.filled}/{summary.cells} cells filled · {summary.absent} absent
                        {unsaved > 0 && !locked ? ` · ${unsaved} unsaved` : ' · all saved'}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </Card>
        )}
      <p className="mt-2 text-xs text-ink-soft">Enter marks out of the maximum shown, or “A” for absent.</p>
    </div>
  )
}

function canEdit() {
  const perms = useAuth.getState().user?.permissions ?? []
  return perms.includes('exams.marks_entry') || perms.includes('*')
}

function ReportCard({ examId }: { examId: number }) {
  const { data: students } = useApi<Paginated<{ id: number; first_name: string; last_name: string; roll_no?: string }>>('/students?per_page=200')
  const [studentId, setStudentId] = useState<number | null>(null)
  const effective = studentId ?? students?.data[0]?.id ?? null
  const { data, loading } = useApi<{ data: CardData }>(effective ? `/exams/${examId}/report-card?student_id=${effective}` : null, [examId, effective])
  const card = data?.data

  return (
    <div>
      <Toolbar className="no-print">
        <div className="w-72">
          <select className={selectCls} aria-label="Student" value={effective ?? ''} onChange={(e) => setStudentId(Number(e.target.value))}>
            {(students?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.roll_no ? `${s.roll_no} — ` : ''}{s.first_name} {s.last_name}</option>)}
          </select>
        </div>
      </Toolbar>
      {loading && !card && <Loading label="Building report card…" />}
      {card && (
        <Card className="mx-auto max-w-2xl p-5 sm:p-6">
          <div data-print-block className="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3">
            <div className="min-w-0">
              <h3 className="text-[15px] font-semibold text-slate-900">{card.exam.name}</h3>
              <p className="mt-0.5 text-[13px] text-ink-soft">{card.student.first_name} {card.student.last_name} · {card.student.roll_no ?? '—'} · {card.student.class} {card.student.section}</p>
            </div>
            <Badge tone={card.published ? 'green' : 'amber'} dot>{card.published ? 'Published' : 'Draft / unpublished'}</Badge>
          </div>

          <div data-print-block className="mt-3">
            <table className="min-w-full text-[13px]">
              <thead>
                <tr className="border-b border-line-strong text-left text-[11px] uppercase tracking-[0.06em] text-ink-soft">
                  <th className="py-1.5 font-semibold">Subject</th>
                  <th className="nums py-1.5 text-right font-semibold">Obtained</th>
                  <th className="nums py-1.5 text-right font-semibold">Max</th>
                  <th className="py-1.5 text-right font-semibold">Grade</th>
                </tr>
              </thead>
              <tbody>
                {card.subjects.map((s, i) => (
                  <tr key={s.subject} className={i % 2 ? 'bg-canvas/40' : ''}>
                    <td className="py-1.5 pr-2">
                      {s.subject}
                      {!s.passed && !s.is_absent && <span className="ml-2 text-[11px] font-semibold uppercase tracking-wide">— fail</span>}
                      {s.is_absent && <span className="ml-2 text-[11px] font-semibold uppercase tracking-wide">— absent</span>}
                    </td>
                    <td className={`nums py-1.5 text-right font-medium ${s.is_absent ? 'text-rose-600' : ''}`}>{s.is_absent ? 'Absent' : s.marks_obtained ?? '—'}</td>
                    <td className="nums py-1.5 text-right text-ink-soft">{s.max_marks}</td>
                    <td className="py-1.5 text-right">{s.result ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div data-print-block className="mt-4 grid grid-cols-3 gap-3 text-center">
            <div className="rounded-xl border border-line bg-canvas/60 p-3">
              <p className="text-[11px] uppercase tracking-[0.06em] text-ink-soft">Total</p>
              <p className="nums mt-1 text-[15px] font-semibold text-slate-900">{card.total_marks} / {card.max_marks}</p>
            </div>
            <div className="rounded-xl border border-line bg-canvas/60 p-3">
              <p className="text-[11px] uppercase tracking-[0.06em] text-ink-soft">Percentage</p>
              <p className="nums mt-1 text-[15px] font-semibold text-slate-900">{card.percentage}%</p>
            </div>
            <div className="rounded-xl border border-line bg-canvas/60 p-3">
              <p className="text-[11px] uppercase tracking-[0.06em] text-ink-soft">Grade / Result</p>
              <p className="mt-1 text-[15px] font-semibold text-slate-900">{card.grade ?? '—'} · {card.result}</p>
            </div>
          </div>
        </Card>
      )}
    </div>
  )
}

function MeritList({ examId, exam }: { examId: number; exam: Exam | null }) {
  const { data: sections } = useApi<{ data: { id: number; name: string; school_class?: { name: string } }[] }>('/sections')
  const [sectionId, setSectionId] = useState('')
  const { data, loading, error, reload } = useApi<{ data: MeritRow[] }>(`/exams/${examId}/merit-list${sectionId ? `?section_id=${sectionId}` : ''}`, [examId, sectionId])
  const rows = useMemo(() => data?.data ?? [], [data])

  return (
    <div>
      <Toolbar>
        <div className="w-52">
          <select className={selectCls} aria-label="Section" value={sectionId} onChange={(e) => setSectionId(e.target.value)}>
            <option value="">All sections</option>
            {(sections?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.school_class?.name} · {s.name}</option>)}
          </select>
        </div>
        {exam?.status !== 'published' && <Badge tone="amber" dot>Results not published yet</Badge>}
      </Toolbar>
      <Table headers={<><Th>Position</Th><Th>Roll #</Th><Th>Student</Th><Th align="right">Total</Th><Th align="right">Percentage</Th></>}
        cols={5} loading={loading} error={error} onRetry={reload}>
        {rows.map((r) => (
          <tr key={r.id}>
            <Td>
              {r.position <= 3
                ? <Badge tone={r.position === 1 ? 'gold' : 'green'} dot>{r.position}</Badge>
                : <span className="nums text-sm font-semibold text-slate-500">{r.position}</span>}
            </Td>
            <Td className="nums text-xs">{r.roll_no ?? '—'}</Td>
            <Td className="font-medium">{r.first_name} {r.last_name}</Td>
            <Td className="nums text-right">{r.total} / {r.out_of}</Td>
            <Td className="nums text-right font-semibold">{r.percentage}%</Td>
          </tr>
        ))}
        {!rows.length && !loading && <tr><Td colSpan={5}><Empty text="No marks entered yet for this exam." hint="Use Marks entry to record results, then reload this list." /></Td></tr>}
      </Table>
    </div>
  )
}
