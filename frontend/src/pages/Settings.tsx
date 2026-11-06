import { useMemo, useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { IconSettings } from '../components/icons'
import { Badge, Btn, Card, CheckPill, Empty, ErrorNote, Field, Loading, Notice, PageHeader, inputCls } from '../components/ui'
import { hasPerm } from '../lib/types'
import { label } from '../lib/labels'

interface SettingRow { value: unknown; scope: 'campus' | 'organization' }
type Kind = 'bool' | 'number' | 'list' | 'json' | 'text'

const GROUPS: Record<string, { title: string; hint: string }> = {
  academic: { title: 'Academic year', hint: 'Session dates, the boards results are submitted to, and the grading scale printed on reports cards.' },
  fees: { title: 'Fees', hint: 'Currency, the day invoices fall due, the late charge, and how families can pay.' },
  attendance: { title: 'Attendance', hint: 'The percentage below which a student is flagged for follow-up.' },
  hr: { title: 'Staff and leave', hint: 'Weekly day off and how many paid leave days staff earn each year.' },
  payroll: { title: 'Payroll and deductions', hint: 'EOBI and tax figures applied when a payroll run is calculated.' },
  communication: { title: 'Communication', hint: 'The sender name printed on SMS to parents and staff.' },
  library: { title: 'Library', hint: 'How long a student may keep a book and the fine charged for each day it is late.' },
  hostel: { title: 'Hostel', hint: 'Whether the mess charge is already inside a room’s monthly fee.' },
  transport: { title: 'Transport', hint: 'Default monthly fare applied when a new route is created.' },
}
const ORDER = ['academic', 'fees', 'attendance', 'hr', 'payroll', 'communication', 'library', 'hostel', 'transport']

const kindOf = (v: unknown): Kind =>
  typeof v === 'boolean' ? 'bool'
    : typeof v === 'number' ? 'number'
      : Array.isArray(v) ? 'list'
        : v !== null && typeof v === 'object' ? 'json'
          : 'text'

const enumish = (s: string) => /^[a-z][a-z0-9_]*$/.test(s)
const word = (s: string) => (enumish(s) ? label(s) : s)
const prefixOf = (key: string) => (key.includes('.') ? key.slice(0, key.indexOf('.')) : 'other')
const nameOf = (key: string) => label(key.includes('.') ? key.slice(key.indexOf('.') + 1) : key)

/** The editor holds text; this converts a stored value to that text, and back again on save. */
const toText = (v: unknown, kind: Kind): string =>
  kind === 'bool' ? String(v)
    : kind === 'list' ? (v as unknown[]).join(', ')
      : kind === 'json' ? JSON.stringify(v)
        : String(v ?? '')

function fromText(kind: Kind, text: string): unknown {
  if (kind === 'bool') return text === 'true'
  if (kind === 'number') return Number(text)
  if (kind === 'list') return text.split(',').map((s) => s.trim()).filter(Boolean)
  if (kind === 'json') return JSON.parse(text)
  return text
}

function Value({ value }: { value: unknown }) {
  const kind = kindOf(value)
  if (value === null || value === undefined || value === '') return <span className="text-sm text-ink-soft">Not set</span>
  if (kind === 'bool') return <Badge tone={value ? 'green' : 'slate'} dot>{value ? 'On' : 'Off'}</Badge>
  if (kind === 'number') return <span className="nums text-sm font-semibold text-slate-900">{Number(value).toLocaleString('en-PK')}</span>
  if (kind === 'text') return <span className="text-sm font-medium text-slate-800">{word(String(value))}</span>
  if (kind === 'list') return (
    <span className="flex flex-wrap gap-1.5">
      {(value as unknown[]).map((v, i) => <Badge key={i} tone="slate">{word(String(v))}</Badge>)}
    </span>
  )
  return (
    <span className="flex flex-wrap gap-x-5 gap-y-1">
      {Object.entries(value as Record<string, unknown>).map(([k, v]) => (
        <span key={k} className="inline-flex items-baseline gap-1.5">
          <span className="text-[13px] text-ink-soft">{word(k)}</span>
          <span className="nums text-[13px] font-semibold text-slate-800">
            {v !== null && typeof v === 'object'
              ? Object.entries(v as Record<string, unknown>).map(([a, b]) => `${word(a)} ${String(b)}`).join(' · ')
              : word(String(v))}
          </span>
        </span>
      ))}
    </span>
  )
}

/** Simple values get a control that matches their type, so nobody has to type JSON for a yes/no. */
function Editor({ kind, text, onChange }: { kind: Kind; text: string; onChange: (v: string) => void }) {
  if (kind === 'bool') return (
    <span className="flex gap-1.5">
      <CheckPill on={text === 'true'} onClick={() => onChange('true')}>On</CheckPill>
      <CheckPill on={text === 'false'} onClick={() => onChange('false')}>Off</CheckPill>
    </span>
  )
  if (kind === 'number') return (
    <input className={inputCls} type="number" step="any" value={text} onChange={(e) => onChange(e.target.value)} />
  )
  if (kind === 'json') return (
    <textarea className={`${inputCls} nums min-h-24 scroll-slim overflow-auto font-mono text-xs`} rows={3} value={text}
      onChange={(e) => onChange(e.target.value)} />
  )
  return (
    <input className={inputCls} value={text} placeholder={kind === 'list' ? 'cash, cheque' : 'Value'}
      onChange={(e) => onChange(e.target.value)} />
  )
}

export default function Settings() {
  const { user, campusId } = useAuth()
  const { data, loading, error, reload } = useApi<{ data: Record<string, SettingRow> }>('/settings')
  const [edits, setEdits] = useState<Record<string, string>>({})
  const [newKey, setNewKey] = useState('')
  const [newVal, setNewVal] = useState('')
  const [err, setErr] = useState('')
  // Tracking the load itself, rather than effecting on it, means a reloaded
  // settings table never renders one frame still showing the discarded edits.
  const [loaded, setLoaded] = useState(data)
  if (loaded !== data) {
    setLoaded(data)
    setEdits({})
  }

  const rows = useMemo(() => Object.entries(data?.data ?? {}) as Array<[string, SettingRow]>, [data])
  const canEdit = hasPerm(user, 'settings.manage') || hasPerm(user, 'settings.manage_campus')
  const campusName = user?.campuses?.find((c) => c.id === campusId)?.name

  // Dotted keys are how settings are namespaced; grouping on the prefix turns one long table
  // into sections a campus administrator can scan for the rule they came to change.
  const sections = useMemo(() => {
    const acc: Record<string, Array<[string, SettingRow]>> = {}
    for (const row of rows) (acc[prefixOf(row[0]) ] ||= []).push(row)
    return Object.entries(acc).sort((a, b) => {
      const rank = (g: string) => { const i = ORDER.indexOf(g); return i === -1 ? ORDER.length : i }
      return rank(a[0]) - rank(b[0]) || a[0].localeCompare(b[0])
    })
  }, [rows])

  const staged = rows.filter(([key, row]) => edits[key] !== undefined && edits[key] !== toText(row.value, kindOf(row.value)))
  const pendingNew = newKey.trim() !== '' && newVal.trim() !== ''
  const count = staged.length + (pendingNew ? 1 : 0)

  async function save() {
    setErr('')
    let settings: Array<{ key: string; value: unknown }>
    try {
      settings = staged.map(([key, row]) => ({ key, value: fromText(kindOf(row.value), edits[key]!) }))
      if (pendingNew) {
        let value: unknown = newVal.trim()
        try { value = JSON.parse(newVal.trim()) } catch { /* plain text is a valid setting value */ }
        settings.push({ key: newKey.trim(), value })
      }
    } catch {
      setErr('One of the values is not valid. Numbers need digits only, and structured settings need valid JSON.')
      return
    }
    if (!settings.length) return
    try {
      await api.put('/settings', { settings })
      setEdits({}); setNewKey(''); setNewVal(''); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div className={canEdit && count ? 'pb-24' : ''}>
      <PageHeader title="Settings" subtitle="Rules that apply across the organization, or to one campus" icon={<IconSettings className="h-5 w-5" />} />
      {campusName && (
        <Notice tone="blue" className="mb-4">
          You are editing for <strong>{campusName}</strong>. Values marked “Campus override” apply only here — everything else is the head-office default.
        </Notice>
      )}
      {loading && <Loading label="Loading settings…" />}
      {!loading && error && <ErrorNote text={error} onRetry={reload} />}
      {!loading && !error && !rows.length && (
        <Card className="p-6"><Empty text="No settings defined yet." hint="Add your first one below, such as the day fees fall due." /></Card>
      )}

      <div className="space-y-4">
        {sections.map(([group, list]) => (
          <Card key={group} className="overflow-hidden">
            <div className="border-b border-line bg-canvas/70 px-5 py-3.5">
              <h2 className="text-sm font-semibold text-slate-900">{GROUPS[group]?.title ?? label(group)}</h2>
              <p className="mt-0.5 max-w-2xl text-xs leading-5 text-ink-soft">{GROUPS[group]?.hint ?? 'Settings that do not belong to a section yet.'}</p>
            </div>
            <div className="divide-y divide-line/70">
              {list.map(([key, row]) => {
                const kind = kindOf(row.value)
                const text = edits[key] ?? toText(row.value, kind)
                const changed = edits[key] !== undefined && text !== toText(row.value, kind)
                return (
                  <div key={key} className="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] md:items-center md:gap-5">
                    <div className="min-w-0">
                      <p className="text-[13px] font-semibold text-slate-800">{nameOf(key)}</p>
                      <p className="nums mt-0.5 truncate font-mono text-[11px] text-ink-soft">{key}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 md:justify-between">
                      <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <Value value={row.value} />
                        {row.scope === 'campus' && <Badge tone="green">Campus override</Badge>}
                        {changed && <Badge tone="amber">Not saved</Badge>}
                      </div>
                      {canEdit && (
                        <div className={`w-full sm:w-60 ${kind === 'json' ? 'sm:w-80' : ''} ${changed ? 'rounded-xl ring-2 ring-amber-200' : ''}`}>
                          <Editor kind={kind} text={text} onChange={(v) => setEdits({ ...edits, [key]: v })} />
                        </div>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          </Card>
        ))}
      </div>

      {canEdit && !loading && !error && (
        <Card className="mt-4 p-5">
          <h2 className="text-sm font-semibold text-slate-900">Add a setting</h2>
          <p className="mt-0.5 max-w-2xl text-xs leading-5 text-ink-soft">
            Use a dotted key so it lands in the right section, for example <span className="font-mono">fees.grace_days</span>.
            A value can be text, a number, true or false, or JSON for lists and maps.
          </p>
          <div className="mt-3 flex flex-wrap gap-3">
            <Field label="Key"><div className="w-full sm:w-64"><input className={`${inputCls} font-mono text-xs`} placeholder="fees.grace_days" value={newKey} onChange={(e) => setNewKey(e.target.value)} /></div></Field>
            <Field label="Value"><div className="w-full sm:w-64"><input className={inputCls} placeholder="7" value={newVal} onChange={(e) => setNewVal(e.target.value)} /></div></Field>
          </div>
          {err && <ErrorNote text={err} className="mt-3" />}
        </Card>
      )}

      {canEdit && count > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-white/95 px-4 py-3 shadow-pop backdrop-blur sm:px-6">
          <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-slate-700">
              <span className="font-semibold">{count}</span> setting{count > 1 ? 's' : ''} ready to save{campusName ? ` for ${campusName}` : ' for the organization'}.
            </p>
            <div className="flex gap-2">
              <Btn variant="ghost" onClick={() => { setEdits({}); setNewKey(''); setNewVal('') }}>Discard</Btn>
              <Btn onClick={save}>Save settings</Btn>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
