import { useState } from 'react'
import dayjs from 'dayjs'
import { api } from '../lib/api'
import { useApi } from '../lib/hooks'
import { type Paginated } from '../lib/types'
import { Screen } from './MobileShell'
import { ListSkeleton } from './shellParts'
import { useReloadOnRefresh } from './refresh'
import { Badge, Card, Empty } from '../components/ui'
import { type Tone } from '../components/tones'
import { IconAnnouncements, IconChevronDown } from '../components/icons'

interface Notice {
  id: number; title: string; body: string; priority: string; audience: string
  published_at: string | null; is_read: boolean; visible_to_me: boolean
  campus: { id: number; name: string; code: string } | null
  creator: { id: number; name: string } | null
}

const PRIORITY: Record<string, Tone> = { urgent: 'rose', high: 'amber', normal: 'slate', low: 'blue' }

export default function MobileNotices() {
  const { data, loading, error, reload } = useApi<{ data: Paginated<Notice> }>('/announcements?scope=feed&per_page=30')
  useReloadOnRefresh(reload)
  const [open, setOpen] = useState<number | null>(null)
  const [read, setRead] = useState<number[]>([])
  const rows = (data?.data.data ?? []).filter((n) => n.visible_to_me !== false)
  const unreadCount = rows.filter((n) => !n.is_read || read.includes(n.id)).length

  function toggle(n: Notice) {
    const next = open === n.id ? null : n.id
    setOpen(next)
    if (next && !n.is_read && !read.includes(n.id)) {
      setRead((r) => [...r, n.id])
      void api.post(`/announcements/${n.id}/read`).catch(() => setRead((r) => r.filter((x) => x !== n.id)))
    }
  }

  return (
    <Screen heading="Notices" sub={loading ? undefined : `${unreadCount} you have not opened`} error={error} onRetry={reload}>
      {loading && <ListSkeleton rows={3} />}
      {!loading && !rows.length && (
        <Card><Empty text="No notices for you right now." icon={<IconAnnouncements />} hint="Announcements addressed to families and staff appear here." /></Card>
      )}
      <div className="space-y-3">
        {rows.map((n) => {
          const isOpen = open === n.id
          const seen = n.is_read || read.includes(n.id)
          return (
            <Card key={n.id} className="overflow-hidden">
              <button type="button" onClick={() => toggle(n)} className="flex w-full items-start gap-3 px-4 py-3.5 text-left transition active:bg-brand-50/50">
                {!seen && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-600" />}
                <span className="min-w-0 flex-1">
                  <span className={`block truncate text-[14px] ${seen ? 'font-medium' : 'font-bold'} text-slate-900`}>{n.title}</span>
                  <span className="mt-0.5 block truncate text-[11px] text-ink-soft">
                    {n.campus ? `${n.campus.name} · ` : ''}{n.creator?.name ?? 'School'} · {n.published_at ? dayjs(n.published_at).format('D MMM, h:mm a') : 'scheduled'}
                  </span>
                </span>
                <Badge tone={PRIORITY[n.priority] ?? 'slate'}>{n.priority}</Badge>
                <IconChevronDown className={`mt-1 h-4 w-4 shrink-0 text-slate-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
              </button>
              {isOpen && (
                <p className="animate-fade-in whitespace-pre-line border-t border-line/70 px-4 py-3 text-[13px] leading-6 text-slate-700">{n.body}</p>
              )}
            </Card>
          )
        })}
      </div>
    </Screen>
  )
}
