import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { IconAnnouncements, IconPlus } from '../components/icons'
import { Btn, Card, Empty, ErrorNote, Field, Loading, Modal, PageHeader, Status, inputCls, selectCls } from '../components/ui'
import { label } from '../lib/labels'
import { askConfirm } from '../store/confirm'

interface Announcement {
  id: number
  title: string
  body: string
  audience: string
  priority: string
  status: string
  campus_id: number | null
  publish_at?: string | null
  expires_at?: string | null
  send_email: boolean
  send_sms: boolean
  is_mine: boolean
  campus?: { name: string; code: string } | null
  creator?: { name: string }
}

interface DraftForm {
  id?: number
  title: string
  body: string
  audience: string
  priority: string
  campus_id: string
  publish_at: string
  expires_at: string
  send_email: boolean
  send_sms: boolean
}

const audienceOptions = ['all', 'staff', 'guardians', 'students']
const priorityOptions = ['normal', 'high', 'urgent']

function localDateTime(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export default function Announcements() {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'communication.manage')
  const { data, loading, reload } = useApi<{ data: { data: Announcement[] } }>('/announcements?scope=manage&per_page=100')
  const { data: campuses } = useApi<{ data: { id: number; name: string; code: string }[] }>('/campuses')
  const [form, setForm] = useState<DraftForm | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const rows = data?.data.data ?? []

  function newDraft() {
    setError('')
    setForm({
      title: '', body: '', audience: 'all', priority: 'normal', campus_id: '',
      publish_at: '', expires_at: '', send_email: false, send_sms: false,
    })
  }

  function editDraft(row: Announcement) {
    setError('')
    setForm({
      id: row.id,
      title: row.title,
      body: row.body,
      audience: row.audience,
      priority: row.priority,
      campus_id: String(row.campus_id ?? ''),
      publish_at: localDateTime(row.publish_at),
      expires_at: localDateTime(row.expires_at),
      send_email: row.send_email,
      send_sms: row.send_sms,
    })
  }

  async function save(publish = false) {
    if (!form) return
    setBusy(true)
    setError('')
    const payload = {
      ...form,
      campus_id: form.campus_id ? Number(form.campus_id) : null,
      publish_at: form.publish_at || null,
      expires_at: form.expires_at || null,
      publish,
    }
    try {
      if (form.id) {
        await api.put(`/announcements/${form.id}`, payload)
        if (publish) await api.post(`/announcements/${form.id}/publish`)
      } else {
        await api.post('/announcements', payload)
      }
      setForm(null)
      reload()
    } catch (e) {
      setError(apiError(e))
    } finally {
      setBusy(false)
    }
  }

  async function publish(row: Announcement) {
    setError('')
    try {
      await api.post(`/announcements/${row.id}/publish`)
      reload()
    } catch (e) {
      setError(apiError(e))
    }
  }

  async function archive(row: Announcement) {
    setError('')
    try {
      await api.post(`/announcements/${row.id}/archive`)
      reload()
    } catch (e) {
      setError(apiError(e))
    }
  }

  async function remove(row: Announcement) {
    if (!await askConfirm({
      title: 'Delete this draft?',
      text: `“${row.title}” has not been published. Deleting it removes the draft permanently.`,
      confirmLabel: 'Delete draft',
    })) return
    setError('')
    try {
      await api.delete(`/announcements/${row.id}`)
      reload()
    } catch (e) {
      setError(apiError(e))
    }
  }

  return (
    <div>
      <PageHeader title="Announcements" subtitle="Create and manage notices for your campus community" icon={<IconAnnouncements className="h-5 w-5" />}
        actions={canManage ? <Btn onClick={newDraft} icon={<IconPlus className="h-4 w-4" />}>New announcement</Btn> : undefined} />
      {error && <ErrorNote text={error} className="mb-4" />}
      <div className="space-y-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="font-semibold text-slate-900">{row.title}</h2>
                  <Status value={row.status} />
                  <Status value={row.priority} />
                </div>
                <p className="mt-1 text-xs text-slate-500">
                  {label(row.audience)} · {row.campus?.name ?? 'All campuses'} · {row.creator?.name ?? 'Staff'}
                </p>
              </div>
              {canManage && (
                <div className="flex flex-wrap gap-2">
                  {row.status === 'draft' && <Btn variant="ghost" onClick={() => editDraft(row)}>Edit</Btn>}
                  {row.status === 'draft' && <Btn onClick={() => void publish(row)}>Publish</Btn>}
                  {row.status === 'published' && <Btn variant="ghost" onClick={() => void archive(row)}>Archive</Btn>}
                  {row.status !== 'published' && <Btn variant="danger" onClick={() => void remove(row)}>Delete</Btn>}
                </div>
              )}
            </div>
            <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-700">{row.body}</p>
          </Card>
        ))}
        {!rows.length && loading && <Loading label="Loading announcements…" />}
        {!rows.length && !loading && <Card className="p-2"><Empty text="No announcements yet." hint={canManage ? 'Draft a notice and publish it to your campus community.' : 'Notices published by your campus will appear here.'} /></Card>}
      </div>

      {form && (
        <Modal size="lg" onClose={() => setForm(null)}
          title={form.id ? 'Edit draft' : 'New announcement'}
          subtitle="Choose who receives it and when it goes live"
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn variant="ghost" onClick={() => void save(false)} disabled={busy}>{busy ? 'Saving…' : 'Save draft'}</Btn>
            {!form.publish_at && <Btn onClick={() => void save(true)} disabled={busy}>{busy ? 'Saving…' : 'Publish now'}</Btn>}
          </>}>
          <div className="space-y-4">
            <Field label="Title"><input className={inputCls} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} maxLength={160} /></Field>
            <Field label="Message"><textarea className={`${inputCls} min-h-36`} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} maxLength={8000} /></Field>
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Audience">
                <select className={selectCls} value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value })}>
                  {audienceOptions.map((option) => <option key={option} value={option}>{label(option)}</option>)}
                </select>
              </Field>
              <Field label="Priority">
                <select className={selectCls} value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                  {priorityOptions.map((option) => <option key={option} value={option}>{label(option)}</option>)}
                </select>
              </Field>
              <Field label="Campus">
                <select className={selectCls} value={form.campus_id} onChange={(e) => setForm({ ...form, campus_id: e.target.value })}>
                  <option value="">All campuses</option>
                  {(campuses?.data ?? []).map((campus) => <option key={campus.id} value={campus.id}>{campus.name}</option>)}
                </select>
              </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Publish at (optional)"><input type="datetime-local" className={inputCls} value={form.publish_at} onChange={(e) => setForm({ ...form, publish_at: e.target.value })} /></Field>
              <Field label="Expires at (optional)"><input type="datetime-local" className={inputCls} value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} /></Field>
            </div>
            <div className="flex flex-wrap gap-4 rounded-xl border border-line bg-canvas/60 px-3 py-2.5 text-sm text-slate-700">
              <label className="flex items-center gap-2 rounded-lg px-1.5 py-1 transition hover:bg-brand-50/70"><input type="checkbox" className="accent-brand-600" checked={form.send_email} onChange={(e) => setForm({ ...form, send_email: e.target.checked })} /> Email recipients</label>
              <label className="flex items-center gap-2 rounded-lg px-1.5 py-1 transition hover:bg-brand-50/70"><input type="checkbox" className="accent-brand-600" checked={form.send_sms} onChange={(e) => setForm({ ...form, send_sms: e.target.checked })} /> SMS recipients</label>
            </div>
            {error && <ErrorNote text={error} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
