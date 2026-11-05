import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDateTime, hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { IconMessages, IconPlus } from '../components/icons'
import { Avatar, Badge, Btn, Card, Empty, ErrorNote, Field, Loading, Modal, PageHeader, Tabs, inputCls, selectCls } from '../components/ui'

interface MessageRow {
  id: number
  subject: string
  body: string
  created_at: string
  sender_id: number
  recipient_id: number
  sender?: { id: number; name: string }
  recipient?: { id: number; name: string }
  party?: { id: number; name: string }
  replies_count: number
  unread: number
}

interface Contact { id: number; name: string; email: string; roles: string[] }
interface ThreadData { thread: MessageRow[]; root_id: number; other_party?: { id: number; name: string } }

const BOXES = [
  { key: 'inbox', label: 'Inbox' },
  { key: 'sent', label: 'Sent' },
  { key: 'unread', label: 'Unread' },
]

export default function Messages() {
  const { user } = useAuth()
  const canSend = hasPerm(user, 'communication.manage')
  const [box, setBox] = useState<'inbox' | 'sent' | 'unread'>('inbox')
  const { data, loading, reload } = useApi<{ data: { data: MessageRow[] } }>(`/messages?box=${box}`, [box])
  const { data: contacts } = useApi<{ data: Contact[] }>(canSend ? '/messages/contacts' : null)
  const [compose, setCompose] = useState(false)
  const [recipientId, setRecipientId] = useState('')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [thread, setThread] = useState<ThreadData | null>(null)
  const [reply, setReply] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function send() {
    setBusy(true)
    setError('')
    try {
      await api.post('/messages', { recipient_id: Number(recipientId), subject, body })
      setCompose(false)
      setRecipientId('')
      setSubject('')
      setBody('')
      reload()
    } catch (e) {
      setError(apiError(e))
    } finally {
      setBusy(false)
    }
  }

  async function openThread(id: number) {
    setError('')
    try {
      const response = await api.get<{ data: ThreadData }>(`/messages/${id}/thread`)
      setThread(response.data.data)
      setReply('')
      reload()
    } catch (e) {
      setError(apiError(e))
    }
  }

  async function sendReply() {
    if (!thread) return
    setBusy(true)
    setError('')
    try {
      await api.post(`/messages/${thread.root_id}/reply`, { body: reply })
      setReply('')
      await openThread(thread.root_id)
      reload()
    } catch (e) {
      setError(apiError(e))
    } finally {
      setBusy(false)
    }
  }

  const rows = data?.data.data ?? []

  return (
    <div>
      <PageHeader title="Messages" subtitle="Private conversations with people in your organization" icon={<IconMessages className="h-5 w-5" />}
        actions={canSend ? <Btn onClick={() => { setError(''); setCompose(true) }} icon={<IconPlus className="h-4 w-4" />}>New message</Btn> : undefined} />
      {error && <ErrorNote text={error} className="mb-4" />}
      <Tabs tabs={BOXES} active={box} onChange={(key) => setBox(key as 'inbox' | 'sent' | 'unread')} />
      <Card className="divide-y divide-line">
        {!rows.length && loading && <Loading label="Loading messages…" />}
        {rows.map((message) => (
          <button key={message.id} onClick={() => void openThread(message.id)} className="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-brand-50/60">
            <Avatar name={message.party?.name ?? 'U'} className="h-10 w-10" />
            <span className="min-w-0 flex-1">
              <span className="flex flex-wrap items-center gap-2">
                <span className="truncate text-sm font-semibold text-slate-800">{message.subject}</span>
                {!!message.unread && <Badge tone="blue" dot>{message.unread} unread</Badge>}
              </span>
              <span className="mt-1 block truncate text-xs text-ink-soft">{message.party?.name} · {message.body}</span>
            </span>
            <span className="nums shrink-0 text-xs text-slate-400">{message.replies_count ? `${message.replies_count} replies` : ''}</span>
          </button>
        ))}
        {!rows.length && !loading && <Empty text="No messages in this folder." hint="Start a new message to reach staff in your organization." />}
      </Card>

      {compose && (
        <Modal size="md" onClose={() => setCompose(false)} title="New message" subtitle="Sent privately to one person"
          footer={<>
            <Btn variant="ghost" onClick={() => setCompose(false)}>Cancel</Btn>
            <Btn onClick={() => void send()} disabled={busy || !recipientId || !subject.trim() || !body.trim()}>{busy ? 'Sending…' : 'Send message'}</Btn>
          </>}>
          <div className="space-y-4">
            <Field label="To">
              <select className={selectCls} value={recipientId} onChange={(e) => setRecipientId(e.target.value)} required>
                <option value="">Select a person</option>
                {(contacts?.data ?? []).filter((contact) => contact.id !== user?.id).map((contact) => (
                  <option key={contact.id} value={contact.id}>{contact.name} · {contact.roles.map(label).join(', ')}</option>
                ))}
              </select>
            </Field>
            <Field label="Subject"><input className={inputCls} value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={180} /></Field>
            <Field label="Message"><textarea className={`${inputCls} min-h-36`} value={body} onChange={(e) => setBody(e.target.value)} maxLength={8000} /></Field>
            {error && <ErrorNote text={error} />}
          </div>
        </Modal>
      )}

      {thread && (
        <Modal size="lg" onClose={() => setThread(null)}
          title={thread.thread[0]?.subject ?? 'Conversation'}
          subtitle={`With ${thread.other_party?.name ?? 'former user'}`}
          footer={canSend ? (
            <div className="w-full space-y-2">
              <textarea aria-label="Reply" className={`${inputCls} min-h-20`} value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Write a reply…" />
              <div className="flex justify-end"><Btn onClick={() => void sendReply()} disabled={busy || !reply.trim()}>{busy ? 'Sending…' : 'Reply'}</Btn></div>
            </div>
          ) : undefined}>
          <div className="scroll-slim max-h-[55vh] space-y-3 overflow-y-auto">
            {thread.thread.map((message) => {
              const mine = message.sender_id === user?.id
              return (
                <div key={message.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[85%] rounded-2xl px-4 py-3 ${mine ? 'bg-brand-700 text-white' : 'bg-canvas text-slate-800 ring-1 ring-inset ring-line'}`}>
                    <p className={`mb-1 text-xs font-semibold ${mine ? 'text-white/80' : 'text-ink-soft'}`}>{message.sender?.name ?? 'User'}</p>
                    <p className="whitespace-pre-wrap text-sm leading-6">{message.body}</p>
                    <p className={`nums mt-2 text-[10px] ${mine ? 'text-white/60' : 'text-slate-400'}`}>{fmtDateTime(message.created_at)}</p>
                  </div>
                </div>
              )
            })}
            {!thread.thread.length && <Empty text="This conversation is empty." />}
          </div>
        </Modal>
      )}
    </div>
  )
}
