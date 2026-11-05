import { useEffect, useRef, useState } from 'react'
import { Link, Route, Routes, useParams } from 'react-router-dom'
import dayjs from 'dayjs'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { hasPerm, type Paginated } from '../lib/types'
import { useAuth } from '../store/auth'
import { Screen } from './MobileShell'
import { DockBar, ListSkeleton, Segmented, useReloadOnRefresh } from './shellParts'
import { Btn, Card, Empty } from '../components/ui'
import { toast } from '../components/Toast'
import { IconChevronRight, IconInbox } from '../components/icons'

interface Thread {
  id: number; subject: string; body: string; created_at: string; last_activity: string
  unread: number; replies_count: number; party: { id: number; name: string } | null
}
interface Post { id: number; subject: string; body: string; created_at: string; sender_id: number; sender: { id: number; name: string } | null }

const BOXES = [
  { key: 'inbox', label: 'Inbox' },
  { key: 'unread', label: 'Unread' },
  { key: 'sent', label: 'Sent' },
]

export default function MobileMessages() {
  return (
    <Routes>
      <Route index element={<ThreadList />} />
      <Route path=":id" element={<ThreadView />} />
    </Routes>
  )
}

function ThreadList() {
  const [box, setBox] = useState('inbox')
  const { data, loading, error, reload } = useApi<{ data: Paginated<Thread> }>(`/messages?box=${box}&per_page=50`, [box])
  useReloadOnRefresh(reload)
  const rows = data?.data.data ?? []

  return (
    <Screen heading="Messages" sub={data ? `${data.data.total} in ${box}` : undefined} error={error} onRetry={reload}>
      <Segmented options={BOXES} value={box} onChange={setBox} />
      {loading && <ListSkeleton rows={4} />}
      <Card className="divide-y divide-line/70">
        {rows.map((t) => (
          <Link key={t.id} to={`/app/messages/${t.id}`} className="flex items-start gap-3 px-4 py-3 transition active:bg-brand-50/60">
            <span className="mt-0.5 min-w-0 flex-1">
              <span className="flex items-center gap-2">
                <span className={`truncate text-[13.5px] ${t.unread ? 'font-bold' : 'font-semibold'} text-slate-900`}>{t.party?.name ?? 'Message'}</span>
                {t.unread > 0 && <span className="nums shrink-0 rounded-full bg-brand-700 px-1.5 text-[10px] font-bold text-white">{t.unread}</span>}
              </span>
              <span className="mt-0.5 block truncate text-[12.5px] text-slate-600">{t.subject}</span>
              <span className="mt-0.5 block truncate text-[11.5px] text-ink-soft">{t.body.slice(0, 90)}</span>
              <span className="mt-1 block text-[10.5px] text-slate-400">
                {dayjs(t.last_activity).format('D MMM, h:mm a')}{t.replies_count > 0 ? ` · ${t.replies_count} repl${t.replies_count === 1 ? 'y' : 'ies'}` : ''}
              </span>
            </span>
            <IconChevronRight className="mt-1.5 h-4 w-4 shrink-0 text-slate-300" />
          </Link>
        ))}
        {!loading && !rows.length && (
          <Empty text={`Nothing in ${box}.`} icon={<IconInbox />} hint="Teachers and the school office write to family accounts here." />
        )}
      </Card>
    </Screen>
  )
}

function ThreadView() {
  const { id } = useParams()
  const { user } = useAuth()
  const { data, loading, error, reload } = useApi<{ data: { thread: Post[]; root_id: number; other_party: { id: number; name: string } | null } }>(
    `/messages/${id}/thread`)
  useReloadOnRefresh(reload)
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)
  const thread = data?.data.thread ?? []

  useEffect(() => { endRef.current?.scrollIntoView({ block: 'end' }) }, [thread.length])

  async function send() {
    if (!body.trim()) return
    setBusy(true)
    try {
      await api.post(`/messages/${thread[thread.length - 1]?.id ?? id}/reply`, { body: body.trim() })
      setBody('')
      toast.success('Reply sent.')
      reload()
    } catch (e) {
      toast.error(apiError(e))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Screen><ListSkeleton rows={4} /></Screen>

  return (
    <Screen heading={data?.data.other_party?.name ?? 'Message'} sub={thread[0]?.subject} error={error} onRetry={reload}>
      <div className="space-y-2.5 pb-24">
        {thread.map((p) => {
          const minePost = Number(p.sender_id) === Number(user?.id)
          return (
            <div key={p.id} className={`max-w-[88%] ${minePost ? 'ml-auto' : ''}`}>
              <div className={`rounded-2xl px-3.5 py-2.5 text-[13px] leading-6 shadow-card ring-1 ${
                minePost ? 'bg-brand-700 text-white ring-brand-700' : 'border-line bg-white text-slate-800 ring-line'
              }`}>
                {p.body}
              </div>
              <p className={`mt-1 text-[10.5px] text-slate-400 ${minePost ? 'text-right' : ''} nums`}>
                {dayjs(p.created_at).format('D MMM, h:mm a')} · {p.sender?.name ?? 'Former user'}
              </p>
            </div>
          )
        })}
        {!thread.length && <Card><Empty text="This thread is empty." /></Card>}
        <div ref={endRef} />
      </div>

      <DockBar direction="column">
        <textarea value={body} rows={2} placeholder="Write a reply…" aria-label="Reply"
          onChange={(e) => setBody(e.target.value)}
          className="w-full resize-none rounded-xl border border-line-strong bg-white px-3 py-2.5 text-sm outline-none transition focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15" />
        <div className="flex items-center justify-between gap-3">
          <Link to="/app/messages" className="text-[12px] font-semibold text-ink-soft underline">Back to inbox</Link>
          {(hasPerm(user, 'communication.reply') || hasPerm(user, 'communication.manage')) && (
            <Btn size="sm" loading={busy} disabled={!body.trim()} onClick={() => void send()}>Send reply</Btn>
          )}
        </div>
      </DockBar>
    </Screen>
  )
}
