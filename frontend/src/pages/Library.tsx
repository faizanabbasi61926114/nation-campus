import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../store/toasts'
import { askConfirm } from '../store/confirm'
import { IconAlert, IconCheck, IconClock, IconEdit, IconLibrary, IconPlus, IconTrash } from '../components/icons'
import {
  Badge, Btn, Empty, ErrorNote, Field, Modal, Notice, PageHeader, Pagination, SearchInput, StatCard, Status,
  Table, Tabs, Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

interface Book {
  id: number; campus_id: number | null; title: string; author?: string | null; isbn?: string | null
  category?: string | null; language?: string | null; shelf?: string | null
  total_copies: number; on_loan: number; available_copies: number; is_active: boolean
}

interface Loan {
  id: number; library_book_id: number; book?: { id: number; title: string; author?: string | null; total_copies?: number } | null
  copy_no: number; student_id?: number | null; staff_id?: number | null; borrower_name: string
  issued_on: string; due_on: string; returned_on?: string | null; days_late: number; is_overdue: boolean
  fine_amount: string | number; fine_paid: boolean; status: string; notes?: string | null
}

interface LibSummary {
  titles: number; copies: number; on_loan: number; overdue: number; lost: number
  fines_outstanding: number; fine_per_day: number
}

interface Person { id: number; first_name: string; last_name: string; roll_no?: string | null; employee_no?: string | null }
interface CampusOption { id: number; name: string; code: string; is_head_office?: boolean }

interface BookDraft {
  id?: number; title: string; author: string; isbn: string; category: string; language: string
  shelf: string; total_copies: string; is_active: boolean; campus_id: string
}

const CATEGORIES = ['Textbook', 'Reference', 'Story', 'Magazine']
const PER_PAGE = 25
const name = (p?: Person | null) => (p ? `${p.first_name} ${p.last_name}`.trim() : '—')
const bookTitle = (l: Loan) => l.book?.title ?? `#${l.library_book_id}`

export default function Library() {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'library.manage')
  const [tab, setTab] = useState<'catalogue' | 'loans' | 'overdue'>('catalogue')
  const [bookForm, setBookForm] = useState<BookDraft | null>(null)
  const [issue, setIssue] = useState<{ bookId: string } | null>(null)

  const { data: summaryData, reload: reloadSummary } = useApi<{ data: LibSummary }>('/library/summary')
  const stats = summaryData?.data

  const newBook = () => setBookForm({
    title: '', author: '', isbn: '', category: 'Textbook', language: 'English', shelf: '',
    total_copies: '1', is_active: true, campus_id: '',
  })

  return (
    <div>
      <PageHeader title="Library" subtitle="Catalogue, issue desk and overdue recovery" icon={<IconLibrary className="h-5 w-5" />}
        actions={canManage && (tab === 'catalogue'
          ? <Btn onClick={newBook} icon={<IconPlus className="h-4 w-4" />}>Add title</Btn>
          : <Btn onClick={() => setIssue({ bookId: '' })} icon={<IconLibrary className="h-4 w-4" />}>Issue a copy</Btn>)} />

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Titles" value={stats?.titles ?? '—'} hint={`${stats?.copies ?? 0} physical copies held`} icon={<IconLibrary className="h-4 w-4" />} tone="blue" />
        <StatCard label="On loan" value={stats?.on_loan ?? '—'} hint={`${stats?.lost ?? 0} lost or damaged`} icon={<IconCheck className="h-4 w-4" />} tone="green" />
        <StatCard label="Overdue" value={stats?.overdue ?? '—'} hint="Copies still out past their due date" icon={<IconClock className="h-4 w-4" />} tone="rose" />
        <StatCard label="Fines outstanding" value={fmtPKR(stats?.fines_outstanding)} hint={`Unpaid · Rs ${stats?.fine_per_day ?? 0} per day late`} icon={<IconAlert className="h-4 w-4" />} tone="amber" />
      </div>

      <Tabs active={tab} onChange={(k) => setTab(k as typeof tab)} tabs={[
        { key: 'catalogue', label: 'Catalogue', count: stats?.titles },
        { key: 'loans', label: 'Loans', count: stats?.on_loan },
        { key: 'overdue', label: 'Overdue', count: stats?.overdue },
      ]} />

      {tab === 'catalogue' && (
        <Catalogue canManage={canManage} onEdit={setBookForm} onIssue={(id) => setIssue({ bookId: String(id) })} onChanged={reloadSummary} />
      )}
      {tab === 'loans' && <LoansPanel variant="all" canManage={canManage} finePerDay={Number(stats?.fine_per_day ?? 5)} onChanged={reloadSummary} />}
      {tab === 'overdue' && <LoansPanel variant="overdue" canManage={canManage} finePerDay={Number(stats?.fine_per_day ?? 5)} onChanged={reloadSummary} />}

      {bookForm && <BookModal draft={bookForm} onClose={() => setBookForm(null)} onSaved={() => { setBookForm(null); reloadSummary() }} />}
      {issue && (
        <IssueModal
          seed={issue.bookId}
          onClose={() => setIssue(null)}
          onIssued={(message) => { setIssue(null); reloadSummary(); toast.success(message) }}
        />
      )}
    </div>
  )
}

function Catalogue({ canManage, onEdit, onIssue, onChanged }: {
  canManage: boolean; onEdit: (draft: BookDraft) => void; onIssue: (bookId: number) => void; onChanged: () => void
}) {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(0)

  const qs = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) })
  if (search) qs.set('search', search)
  if (category) qs.set('category', category)
  if (status) qs.set('status', status)

  const { data, loading, error, reload } = useApi<{ data: Paginated<Book> }>(`/library/books?${qs}`, [search, category, status, page])
  const rows = data?.data.data ?? []
  const C = canManage ? 8 : 5

  const edit = (b: Book) => onEdit({
    id: b.id, title: b.title, author: b.author ?? '', isbn: b.isbn ?? '', category: b.category ?? '',
    language: b.language ?? '', shelf: b.shelf ?? '', total_copies: String(b.total_copies),
    is_active: b.is_active, campus_id: b.campus_id ? String(b.campus_id) : '',
  })

  const remove = async (b: Book) => {
    const ok = await askConfirm({
      title: 'Remove this title?',
      text: `“${b.title}” and its issue history are deleted. Titles with copies still out cannot be removed.`,
      confirmLabel: 'Remove title',
    })
    if (!ok) return
    setBusy(b.id)
    try {
      const r = await api.delete(`/library/books/${b.id}`)
      toast.success(r.data.message ?? 'Title removed.')
      reload(); onChanged()
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  return (
    <div>
      <Toolbar>
        <SearchInput className="w-72 max-w-full" value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Search title, author or ISBN…" />
        <div className="w-36">
          <select className={selectCls} aria-label="Book category" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1) }}>
            <option value="">All categories</option>
            {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div className="w-36">
          <select className={selectCls} aria-label="Catalogue status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">Catalogue</option>
            <option value="active">In circulation</option>
            <option value="inactive">Withdrawn</option>
          </select>
        </div>
      </Toolbar>

      <Table headers={<>
        <Th>Title</Th><Th>Author</Th><Th>Category</Th><Th align="right">Copies</Th><Th align="right">On loan</Th><Th>Shelf</Th>
        {canManage && <Th>Status</Th>}
        {canManage && <Th align="right">Actions</Th>}
      </>} cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((b) => (
          <tr key={b.id}>
            <Td className="font-medium">
              {b.title}
              <p className="text-xs text-slate-400">
                {b.isbn ?? 'no ISBN'} · {b.campus_id ? 'this campus' : 'shared across campus'}
                {b.language ? ` · ${b.language}` : ''}
              </p>
            </Td>
            <Td>{b.author || '—'}</Td>
            <Td>{b.category || '—'}</Td>
            <Td className="nums text-right">{b.total_copies}</Td>
            <Td className="nums text-right">
              {b.on_loan > 0 ? <Badge tone={b.available_copies === 0 ? 'rose' : 'amber'}>{b.on_loan} out</Badge> : <span className="text-slate-400">0</span>}
            </Td>
            <Td className="nums text-xs">{b.shelf || '—'}</Td>
            {canManage && <Td><Status value={b.is_active ? 'active' : 'inactive'} dot /></Td>}
            {canManage && (
              <Td className="text-right whitespace-nowrap">
                <div className="flex flex-wrap justify-end gap-1">
                  <Btn size="sm" variant="soft" disabled={!b.available_copies || !b.is_active || busy === b.id}
                    onClick={() => onIssue(b.id)}>Issue</Btn>
                  <Btn size="sm" variant="ghost" onClick={() => edit(b)} icon={<IconEdit className="h-3.5 w-3.5" />}>Edit</Btn>
                  <Btn size="sm" variant="ghost" disabled={busy === b.id} onClick={() => void remove(b)} icon={<IconTrash className="h-3.5 w-3.5" />}>Remove</Btn>
                </div>
              </Td>
            )}
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No titles match this filter." hint="Add a title to start the catalogue, or clear the filters." /></Td></tr>
        )}
      </Table>
      {data && data.data.last_page > 1 && (
        <Pagination page={data.data.current_page} lastPage={data.data.last_page} total={data.data.total} perPage={PER_PAGE} onPage={setPage} busy={loading} />
      )}
    </div>
  )
}

function BookModal({ draft, onClose, onSaved }: { draft: BookDraft; onClose: () => void; onSaved: () => void }) {
  const { user } = useAuth()
  const [form, setForm] = useState(draft)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const { data: campuses } = useApi<{ data: CampusOption[] }>(!form.id && user?.is_head_office ? '/campuses' : null)
  const options = (campuses?.data ?? []).filter((c) => !c.is_head_office)

  async function save() {
    setErr('')
    if (!form.title.trim()) { setErr('A title is required.'); return }
    if (!Number(form.total_copies)) { setErr('Record at least one physical copy.'); return }
    setSaving(true)
    const payload: Record<string, unknown> = {
      title: form.title.trim(),
      author: form.author.trim() || null,
      isbn: form.isbn.trim() || null,
      category: form.category || null,
      language: form.language.trim() || null,
      shelf: form.shelf.trim() || null,
      total_copies: Number(form.total_copies),
      is_active: form.is_active,
    }
    try {
      if (form.id) {
        await api.put(`/library/books/${form.id}`, payload)
        toast.success('Title updated.')
      } else {
        payload.campus_id = form.campus_id ? Number(form.campus_id) : null
        await api.post('/library/books', payload)
        toast.success('Title added to the catalogue.')
      }
      onSaved()
    } catch (e) { setErr(apiError(e)) } finally { setSaving(false) }
  }

  const set = (patch: Partial<BookDraft>) => setForm({ ...form, ...patch })

  return (
    <Modal size="md" onClose={onClose}
      title={form.id ? `Edit ${form.title}` : 'New title'}
      subtitle={form.id ? 'Copies out with readers cap how far the holding can fall' : 'Catalogue a title for this campus or for the whole organization'}
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save title'}</Btn>
      </>}>
      <div className="space-y-3">
        <Field label="Title"><input className={inputCls} value={form.title} onChange={(e) => set({ title: e.target.value })} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Author"><input className={inputCls} value={form.author} onChange={(e) => set({ author: e.target.value })} /></Field>
          <Field label="ISBN"><input className={inputCls} value={form.isbn} onChange={(e) => set({ isbn: e.target.value })} /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Category">
            <select className={selectCls} value={form.category} onChange={(e) => set({ category: e.target.value })}>
              <option value="">Uncategorised</option>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </Field>
          <Field label="Language"><input className={inputCls} value={form.language} onChange={(e) => set({ language: e.target.value })} /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Shelf"><input className={inputCls} value={form.shelf} onChange={(e) => set({ shelf: e.target.value })} placeholder="REF-04" /></Field>
          <Field label="Total copies" hint={form.id ? 'Cannot fall below the copies still out.' : undefined}>
            <input className={inputCls} type="number" min="1" max="2000" value={form.total_copies} onChange={(e) => set({ total_copies: e.target.value })} />
          </Field>
        </div>
        {!form.id && user?.is_head_office && (
          <Field label="Campus" hint="Leave as shared to let every campus issue this title.">
            <select className={selectCls} value={form.campus_id} onChange={(e) => set({ campus_id: e.target.value })}>
              <option value="">Shared across all campuses</option>
              {options.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
            </select>
          </Field>
        )}
        {form.id !== undefined && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="accent-brand-600" checked={!form.is_active} onChange={(e) => set({ is_active: !e.target.checked })} />
            Withdraw from circulation
          </label>
        )}
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function IssueModal({ seed, onClose, onIssued }: { seed: string; onClose: () => void; onIssued: (message: string) => void }) {
  const [form, setForm] = useState({
    library_book_id: seed, borrower: 'student', student_id: '', staff_id: '', copy_no: '', issued_on: '', due_on: '', notes: '',
  })
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const { data: booksData } = useApi<{ data: Paginated<Book> }>('/library/books?per_page=100&status=active')
  const { data: studentsData } = useApi<{ data: Person[] }>('/students?per_page=300&status=active')
  const { data: staffData } = useApi<{ data: { data: Person[] } }>('/staff?per_page=100&status=active')

  const books = booksData?.data.data ?? []
  const students = studentsData?.data ?? []
  const staff = staffData?.data?.data ?? []
  const chosen = books.find((b) => String(b.id) === form.library_book_id)

  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })

  async function save() {
    setErr('')
    if (!form.library_book_id) { setErr('Choose the title being issued.'); return }
    if (form.borrower === 'student' && !form.student_id) { setErr('Choose the student borrowing this copy.'); return }
    if (form.borrower === 'staff' && !form.staff_id) { setErr('Choose the staff member borrowing this copy.'); return }
    setSaving(true)
    const payload: Record<string, unknown> = {
      library_book_id: Number(form.library_book_id),
      student_id: form.borrower === 'student' ? Number(form.student_id) : null,
      staff_id: form.borrower === 'staff' ? Number(form.staff_id) : null,
    }
    if (form.copy_no) payload.copy_no = Number(form.copy_no)
    if (form.issued_on) payload.issued_on = form.issued_on
    if (form.due_on) payload.due_on = form.due_on
    if (form.notes.trim()) payload.notes = form.notes.trim()
    try {
      const r = await api.post('/library/loans', payload)
      onIssued(r.data.message ?? 'Copy issued.')
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  return (
    <Modal size="md" onClose={onClose} title="Issue a copy"
      subtitle="The due date defaults to this campus' loan period"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => void save()}>{saving ? 'Issuing…' : 'Issue copy'}</Btn>
      </>}>
      <div className="space-y-3">
        <Field label="Title">
          <select className={selectCls} value={form.library_book_id} onChange={(e) => set({ library_book_id: e.target.value })}>
            <option value="">Select a title…</option>
            {books.map((b) => (
              <option key={b.id} value={b.id}>{b.title} — {b.available_copies} of {b.total_copies} free</option>
            ))}
          </select>
        </Field>
        {chosen && (
          <Notice tone={chosen.available_copies > 0 ? 'blue' : 'rose'}>
            {chosen.available_copies > 0
              ? `${chosen.available_copies} of ${chosen.total_copies} copy(s) free${chosen.shelf ? ` · shelf ${chosen.shelf}` : ''}.`
              : `All ${chosen.total_copies} copy(s) are already out.`}
          </Notice>
        )}
        <Field label="Borrower">
          <div className="flex gap-2">
            {(['student', 'staff'] as const).map((kind) => (
              <Btn key={kind} size="sm" variant={form.borrower === kind ? 'primary' : 'ghost'}
                onClick={() => set({ borrower: kind, student_id: '', staff_id: '' })}>
                {label(kind)}
              </Btn>
            ))}
          </div>
        </Field>
        {form.borrower === 'student' ? (
          <Field label="Student">
            <select className={selectCls} value={form.student_id} onChange={(e) => set({ student_id: e.target.value })}>
              <option value="">Select…</option>
              {students.map((s) => <option key={s.id} value={s.id}>{s.roll_no ? `${s.roll_no} — ` : ''}{name(s)}</option>)}
            </select>
          </Field>
        ) : (
          <Field label="Staff member">
            <select className={selectCls} value={form.staff_id} onChange={(e) => set({ staff_id: e.target.value })}>
              <option value="">Select…</option>
              {staff.map((s) => <option key={s.id} value={s.id}>{s.employee_no ? `${s.employee_no} — ` : ''}{name(s)}</option>)}
            </select>
          </Field>
        )}
        <div className="grid grid-cols-3 gap-3">
          <Field label="Copy no" hint="Auto">
            <input className={inputCls} type="number" min="1" value={form.copy_no} onChange={(e) => set({ copy_no: e.target.value })}
              placeholder={chosen ? String(Math.min(chosen.total_copies, chosen.on_loan + 1)) : '1'} />
          </Field>
          <Field label="Issued on"><input className={inputCls} type="date" value={form.issued_on} onChange={(e) => set({ issued_on: e.target.value })} /></Field>
          <Field label="Due on"><input className={inputCls} type="date" value={form.due_on} onChange={(e) => set({ due_on: e.target.value })} /></Field>
        </div>
        <Field label="Notes"><input className={inputCls} value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Reference copy, return before the exam" /></Field>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function LoansPanel({ variant, canManage, finePerDay, onChanged }: {
  variant: 'all' | 'overdue'; canManage: boolean; finePerDay: number; onChanged: () => void
}) {
  const [status, setStatus] = useState(variant === 'overdue' ? 'overdue' : '')
  const [filter, setFilter] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(0)

  const qs = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) })
  if (status) qs.set('status', status)
  const { data, loading, error, reload } = useApi<{ data: Paginated<Loan> }>(`/library/loans?${qs}`, [status, page])
  const all = data?.data.data ?? []
  const needle = filter.trim().toLowerCase()
  const rows = needle
    ? all.filter((l) => l.borrower_name.toLowerCase().includes(needle) || bookTitle(l).toLowerCase().includes(needle))
    : all
  const C = canManage ? 8 : 6

  const done = (message?: string, fallback?: string) => {
    toast.success(message ?? fallback ?? 'Done.')
    reload(); onChanged()
  }

  const returnCopy = async (l: Loan) => {
    const rate = l.fine_paid ? ' fine already paid' : ''
    const ok = await askConfirm({
      title: 'Return this copy?',
      text: `Copy ${l.copy_no} of “${bookTitle(l)}” from ${l.borrower_name}. Due ${fmtDate(l.due_on)}`
        + `${l.days_late > 0 ? ` — ${l.days_late} day(s) late, fine ${fmtPKR(l.days_late * finePerDay)}${rate}.` : ', back on time so no fine is due.'}`,
      confirmLabel: 'Return copy',
      tone: 'primary',
    })
    if (!ok) return
    setBusy(l.id)
    try {
      const r = await api.post(`/library/loans/${l.id}/return`, {})
      done(r.data.message, 'Copy returned.')
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  const markLost = async (l: Loan) => {
    const ok = await askConfirm({
      title: 'Mark this copy lost?',
      text: `Copy ${l.copy_no} of “${bookTitle(l)}” with ${l.borrower_name}. The copy keeps counting against the holding and the accrued fine of ${fmtPKR(l.days_late * finePerDay)} stands.`,
      confirmLabel: 'Mark lost',
    })
    if (!ok) return
    setBusy(l.id)
    try {
      const r = await api.post(`/library/loans/${l.id}/lost`, {})
      done(r.data.message, 'Copy marked lost.')
    } catch (e) { toast.error(apiError(e)) } finally { setBusy(0) }
  }

  return (
    <div>
      <Toolbar>
        <SearchInput className="w-64 max-w-full" value={filter} onChange={setFilter} placeholder="Filter by reader or title…" />
        {variant === 'all' && (
          <div className="w-40">
            <select className={selectCls} aria-label="Loan status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">All loans</option>
              {['active', 'overdue', 'returned', 'lost'].map((s) => <option key={s} value={s}>{label(s === 'active' ? 'borrowed' : s)}</option>)}
            </select>
          </div>
        )}
      </Toolbar>

      {variant === 'overdue' && (
        <Notice tone="amber" className="mb-4">
          Overdue copies are still out and past their due date. Returning one charges Rs {finePerDay} for every day late.
        </Notice>
      )}

      <Table headers={<>
        <Th>Copy</Th><Th>Reader</Th><Th>Issued</Th><Th>Due</Th><Th>Returned</Th><Th align="right">Fine</Th><Th>Status</Th>
        {canManage && <Th align="right">Actions</Th>}
      </>} cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((l) => (
          <tr key={l.id}>
            <Td className="font-medium">
              {bookTitle(l)}
              <p className="text-xs text-slate-400">copy {l.copy_no}{l.book?.total_copies ? ` of ${l.book.total_copies}` : ''}{l.notes ? ` · ${l.notes}` : ''}</p>
            </Td>
            <Td>
              {l.borrower_name}
              <p className="text-xs text-slate-400">{l.student_id ? 'student' : l.staff_id ? 'staff' : '—'}</p>
            </Td>
            <Td className="nums text-xs">{fmtDate(l.issued_on)}</Td>
            <Td className="nums text-xs">
              {fmtDate(l.due_on)}
              {l.days_late > 0 && <Badge tone="rose" dot>{l.days_late}d late</Badge>}
            </Td>
            <Td className="nums text-xs">{l.returned_on ? fmtDate(l.returned_on) : '—'}</Td>
            <Td className="nums text-right">
              {Number(l.fine_amount) > 0
                ? <span className={l.fine_paid ? 'text-slate-500' : 'font-semibold text-rose-700'}>{fmtPKR(l.fine_amount)}</span>
                : '—'}
              {l.fine_paid && Number(l.fine_amount) > 0 && <span className="ml-1 text-xs text-brand-700">paid</span>}
            </Td>
            <Td><Status value={l.status} dot /></Td>
            {canManage && (
              <Td className="text-right whitespace-nowrap">
                <div className="flex flex-wrap justify-end gap-1">
                  {l.status !== 'returned' && (
                    <>
                      <Btn size="sm" disabled={busy === l.id} onClick={() => void returnCopy(l)} icon={<IconCheck className="h-3.5 w-3.5" />}>Return</Btn>
                      <Btn size="sm" variant="ghost" disabled={busy === l.id} onClick={() => void markLost(l)}>Mark lost</Btn>
                    </>
                  )}
                </div>
              </Td>
            )}
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text={variant === 'overdue' ? 'Nothing is overdue.' : 'No loans for this filter.'}
            hint={variant === 'overdue' ? 'Every copy out is still inside its due date.' : 'Issue a copy from the catalogue to open a loan.'} /></Td></tr>
        )}
      </Table>
      {data && data.data.last_page > 1 && (
        <Pagination page={data.data.current_page} lastPage={data.data.last_page} total={data.data.total} perPage={PER_PAGE} onPage={setPage} busy={loading} />
      )}
    </div>
  )
}
