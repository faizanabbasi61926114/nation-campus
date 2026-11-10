import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { toast } from '../store/toasts'
import { Screen } from './MobileShell'
import { ListSkeleton, Metric, Segmented } from './shellParts'
import { useReloadOnRefresh } from './refresh'
import { Badge, Btn, Card, Empty, Notice, SearchInput } from '../components/ui'
import { IconGate } from '../components/icons'

interface Tap {
  id: number; student_id: number; name: string; roll_no: string | null; class: string | null
  direction: string; time: string; gate: string | null; source: string; notified: boolean
}
interface GateSummary {
  date: string; arrivals: number; departures: number; inside: number
  expected: number; untapped: number; families_alerted: number
  by_gate: Array<{ gate: string; taps: number }>
  recent: Tap[]
}
interface Pick { id: number; first_name: string; last_name: string; roll_no: string | null }

const GATES = ['Main gate', 'Back gate', 'Bus bay', 'Reception']

/**
 * The reception desk on a phone: search a child, tap In or Out. The first arrival and the first
 * departure of a day each send the family one in-app alert, so tapping twice is harmless.
 */
export default function GateDesk() {
  const { user, campusId } = useAuth()
  const canTap = hasPerm(user, 'frontdesk.manage')
  const [gate, setGate] = useState(GATES[0])
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState(0)

  const summary = useApi<{ data: GateSummary }>('/entries/summary')
  useReloadOnRefresh(summary.reload)
  const s = summary.data?.data
  const term = q.trim()
  const picks = useApi<{ data: Pick[] }>(
    term ? `/students?status=active&per_page=25&search=${encodeURIComponent(term)}` : null, [term])

  async function tap(student: Pick, direction: 'in' | 'out') {
    setBusy(student.id)
    try {
      const { data } = await api.post('/entries', { student_id: student.id, direction, gate })
      toast.success(data.message ?? 'Recorded.')
      summary.reload()
    } catch (e) {
      toast.error(apiError(e))
    } finally {
      setBusy(0)
    }
  }

  return (
    <Screen heading="Gate desk" sub={s ? `${s.arrivals} in · ${s.inside} still inside · ${s.expected} enrolled` : undefined}
      error={summary.error} onRetry={summary.reload}>
      {!campusId && <Notice tone="amber" title="Select a campus">Gate taps belong to one campus. Choose it in the header first.</Notice>}

      {summary.loading && !s && <ListSkeleton rows={4} />}

      {s && (
        <div className="grid grid-cols-2 gap-2">
          <Metric label="Arrivals" value={s.arrivals} sub={`${s.departures} gone out`} tone="green" />
          <Metric label="Inside now" value={s.inside} sub="last tap was In" tone="blue" />
          <Metric label="Family alerts" value={s.families_alerted} sub="sent in-app today" />
          <Metric label="Not tapped" value={s.untapped} sub="still unaccounted" tone={s.untapped ? 'amber' : 'slate'} />
        </div>
      )}

      {canTap && (
        <Card className="p-3.5">
          <p className="mb-2.5 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">
            <IconGate className="h-4 w-4 text-brand-500" />Tap a child
          </p>
          <SearchInput value={q} onChange={setQ} placeholder="Name, roll no or admission no…" />
          <div className="mt-3">
            <Segmented options={GATES.map((g) => ({ key: g, label: g }))} value={gate} onChange={setGate} />
          </div>

          <div className="mt-3 space-y-2.5">
            {!term && <p className="text-[11.5px] text-ink-soft">Type a name to pull up a child, then tap In or Out.</p>}
            {term && picks.loading && <ListSkeleton rows={2} />}
            {term && !picks.loading && !(picks.data?.data ?? []).length && (
              <Empty text="No active student matches that." hint="Check the spelling or the campus in the header." />
            )}
            {(picks.data?.data ?? []).map((p) => (
              <div key={p.id} className="flex items-center gap-2.5 rounded-xl border border-line bg-canvas/60 px-3 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13.5px] font-semibold text-slate-900">{p.first_name} {p.last_name}</span>
                  <span className="nums block text-[11px] text-ink-soft">{p.roll_no ?? '—'}</span>
                </span>
                <Btn size="sm" disabled={busy === p.id || !campusId} onClick={() => void tap(p, 'in')}>In</Btn>
                <Btn size="sm" variant="ghost" disabled={busy === p.id || !campusId} onClick={() => void tap(p, 'out')}>Out</Btn>
              </div>
            ))}
          </div>
        </Card>
      )}

      {!canTap && (
        <Notice tone="blue" title="Read-only">Your role can follow the gate log but not tap a child in or out — that takes front desk permission.</Notice>
      )}

      <section className="space-y-2.5">
        <h3 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-soft">Taps today</h3>
        {s && !s.recent.length && <Card><Empty text="Nobody has been tapped in yet today." /></Card>}
        {s?.recent.length ? (
          <Card className="divide-y divide-line/70">
            {s.recent.map((t) => (
              <div key={t.id} className="flex items-center gap-3 px-4 py-2.5">
                <Badge tone={t.direction === 'in' ? 'green' : 'slate'} dot>{t.direction === 'in' ? 'In' : 'Out'}</Badge>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13.5px] font-semibold text-slate-900">{t.name}</span>
                  <span className="block truncate text-[11px] text-ink-soft">
                    {t.class ?? '—'} · {t.gate ?? 'gate not set'}{t.source === 'roll_call' ? ' · from register' : ''}
                  </span>
                </span>
                <span className="nums shrink-0 text-[11.5px] font-semibold text-slate-500">{t.time}</span>
              </div>
            ))}
          </Card>
        ) : null}
        {s && s.recent.length > 0 && s.arrivals + s.departures > s.recent.length && (
          <p className="text-center text-[11px] text-slate-400">
            {s.arrivals + s.departures - s.recent.length} earlier tap{ s.arrivals + s.departures - s.recent.length === 1 ? '' : 's' } today are on the website log.
          </p>
        )}
      </section>
    </Screen>
  )
}
