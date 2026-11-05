import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { fmtDate } from '../lib/types'
import { Screen } from './MobileShell'
import { DockBar, ListSkeleton, Segmented, useReloadOnRefresh } from './shellParts'
import { Btn, Card, Empty } from '../components/ui'
import { toast } from '../components/Toast'

interface ExamLite { id: number; name: string; status: string; start_date: string }
interface SubjectRow { id: number; max_marks: number; pass_marks: number; subject: { id: number; name: string } | null }
interface StudentRow {
  id: number; roll_no: string | null; first_name: string; last_name: string
  marks: Record<string, { marks_obtained: number | null; is_absent: boolean }>
}

export default function MarksEntry() {
  const [sp, setSp] = useSearchParams()
  const mine = useApi<{ data: { exams: ExamLite[]; sections: Array<{ id: number; name: string; class: string | null }> } }>('/teacher/my-class')
  useReloadOnRefresh(mine.reload)

  const exams = mine.data?.data.exams ?? []
  const sections = mine.data?.data.sections ?? []
  const examId = Number(sp.get('exam')) || exams[0]?.id || 0
  const sectionId = Number(sp.get('section')) || sections[0]?.id || 0

  const roster = useApi<{ data: { exam: { subjects: SubjectRow[] }; students: StudentRow[] } }>(
    examId && sectionId ? `/exams/${examId}/roster?section_id=${sectionId}` : null, [examId, sectionId])
  useReloadOnRefresh(roster.reload)

  const subjects = roster.data?.data.exam.subjects ?? []
  const students = roster.data?.data.students ?? []
  const [subjectKey, setSubjectKey] = useState('')
  const [values, setValues] = useState<Record<number, { marks: string; absent: boolean }>>({})
  const [saving, setSaving] = useState(false)

  const subject = subjects.find((s) => String(s.id) === subjectKey) ?? subjects[0]

  // Existing marks seed the inputs whenever the sheet changes.
  useEffect(() => {
    if (!subject) return setValues({})
    setValues(Object.fromEntries(students.map((st) => {
      const m = subject && st.marks[String(subject.id)]
      return [st.id, { marks: m?.marks_obtained != null ? String(m.marks_obtained) : '', absent: !!m?.is_absent }]
    })))
  }, [examId, sectionId, subject?.id, roster.data]) // eslint-disable-line

  async function save() {
    if (!subject) return
    setSaving(true)
    const rows = students
      .map((st) => ({
        student_id: st.id,
        exam_subject_id: subject.id,
        is_absent: values[st.id]?.absent ?? false,
        marks_obtained: values[st.id]?.absent ? null : (values[st.id]?.marks === '' ? null : Number(values[st.id]?.marks)),
      }))
      .filter((r) => r.marks_obtained !== null || r.is_absent)
    if (!rows.length) { setSaving(false); return toast.error('Enter at least one mark first.') }
    try {
      const { data } = await api.post(`/exams/${examId}/marks`, { marks: rows })
      toast.success(data.message ?? 'Marks saved.')
      roster.reload()
    } catch (e) {
      toast.error(apiError(e))
    } finally {
      setSaving(false)
    }
  }

  if (mine.loading) return <Screen><ListSkeleton rows={4} /></Screen>

  return (
    <Screen heading="Marks entry" sub={examId ? `${exams.find((e) => e.id === examId)?.name ?? ''} · ${fmtDate(exams.find((e) => e.id === examId)?.start_date ?? '')}` : undefined}
      error={roster.error || mine.error} onRetry={roster.reload}>
      {!exams.length && <Card><Empty text="No paper is waiting for marks." hint="Published results are locked, so they do not appear here." /></Card>}

      {exams.length > 0 && (
        <>
          <Segmented options={exams.map((e) => ({ key: String(e.id), label: e.name, note: e.status }))}
            value={String(examId)} onChange={(v) => setSp({ exam: v, section: String(sectionId) })} />
          {sections.length > 1 && (
            <Segmented options={sections.map((s) => ({ key: String(s.id), label: `${s.class ?? ''} ${s.name}` }))}
              value={String(sectionId)} onChange={(v) => setSp({ exam: String(examId), section: v })} />
          )}
          {roster.loading && <ListSkeleton rows={4} />}
          {!roster.loading && !!subject && (
            <>
              <Segmented options={subjects.map((s) => ({ key: String(s.id), label: s.subject?.name ?? 'Subject', note: `/${s.max_marks}` }))}
                value={String(subject.id)} onChange={setSubjectKey} />
              <p className="text-[11.5px] text-ink-soft">Pass mark {subject.pass_marks} of {subject.max_marks} · marks lock when the paper is published</p>

              <Card className="divide-y divide-line/70">
                {students.map((st) => {
                  const v = values[st.id] ?? { marks: '', absent: false }
                  return (
                    <div key={st.id} className="flex items-center gap-3 px-3.5 py-2.5">
                      <span className="nums w-8 shrink-0 text-[11px] font-semibold text-slate-400">{st.roll_no ?? '—'}</span>
                      <p className="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-slate-900">{st.first_name} {st.last_name}</p>
                      <button type="button" aria-pressed={v.absent} data-on={v.absent} onClick={() => setValues((p) => ({ ...p, [st.id]: { ...v, absent: !v.absent, marks: v.absent ? v.marks : '' } }))}
                        className="rounded-lg px-2 py-1.5 text-[11px] font-semibold ring-1 ring-inset ring-line-strong bg-white text-slate-500 transition active:scale-95 data-[on=true]:bg-slate-700 data-[on=true]:text-white data-[on=true]:ring-slate-700">A</button>
                      <input inputMode="decimal" disabled={v.absent} value={v.marks} placeholder="—"
                        aria-label={`Marks for ${st.first_name}`}
                        onChange={(e) => setValues((p) => ({ ...p, [st.id]: { ...v, marks: e.target.value.replace(/[^0-9.]/g, '') } }))}
                        className="nums w-16 rounded-lg border border-line-strong bg-white px-2 py-1.5 text-right text-[13px] font-semibold outline-none transition focus:border-brand-500 disabled:bg-slate-100 disabled:text-slate-300" />
                    </div>
                  )
                })}
                {!students.length && <Empty text="No active students in this section." />}
              </Card>

              <DockBar>
                <p className="min-w-0 flex-1 text-[12px] font-semibold text-slate-700">
                  {students.filter((st) => values[st.id]?.marks || values[st.id]?.absent).length} of {students.length} entered
                </p>
                <Btn size="sm" loading={saving} onClick={() => void save()}>Save {subject.subject?.name}</Btn>
              </DockBar>
            </>
          )}
          {!roster.loading && !subject && <Card><Empty text="This paper has no subjects yet." hint="Add subjects to the exam on the desktop workspace before entering marks." /></Card>}
        </>
      )}
    </Screen>
  )
}
