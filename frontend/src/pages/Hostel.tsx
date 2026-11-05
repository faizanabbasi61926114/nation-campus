import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtDate, fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../components/Toast'
import { askConfirm } from '../components/Confirm'
import { IconAlert, IconArrowRight, IconCheck, IconEdit, IconHostel, IconPlus, IconStudents } from '../components/icons'
import {
  Badge, Btn, Card, Empty, ErrorNote, Field, Modal, Notice, PageHeader, SearchInput, StatCard, Status,
  Table, Tabs, Td, Th, Toolbar, inputCls, selectCls,
} from '../components/ui'

interface Block {
  id: number; campus_id: number; name: string; gender: string; is_active: boolean
  warden_user_id?: number | null; warden?: { id: number; name: string } | null
  rooms_count: number; beds: number; occupied: number; free: number
}

interface Bed {
  bed_no: number; taken: boolean; allotment_id?: number | null; student_id?: number | null
  student?: string | null; from_date?: string | null
}

interface Room {
  id: number; campus_id: number; hostel_block_id: number; number: string; floor?: string | null
  capacity: number; monthly_fee: string | number; status: string; occupied: number; free_beds: number
  block?: { id: number; name: string; gender: string; warden_user_id?: number | null } | null
  beds: Bed[]
}

interface Allot {
  id: number; student_id: number; student?: Person | null; student_name: string; hostel_room_id: number
  room?: { id: number; number: string; capacity: number; hostel_block_id: number; block?: { id: number; name: string; gender: string } | null } | null
  bed_no: number; from_date: string; to_date?: string | null; status: string; notes?: string | null
  approver?: { id: number; name: string } | null
}

interface Person { id: number; first_name: string; last_name: string; roll_no?: string | null; gender?: string }

interface HostelSummary {
  blocks: { id: number; name: string; gender: string; is_active: boolean; rooms: number; beds: number; occupied: number; free: number; maintenance: number }[]
  rooms: number; beds_total: number; beds_occupied: number; beds_free: number; occupancy_pct: number
  monthly_revenue: number; vacated: number; hall_charge_included: boolean
}

interface BlockDraft { id?: number; name: string; gender: string; warden_user_id: string; is_active: boolean }
interface RoomDraft { id?: number; hostel_block_id: string; number: string; floor: string; capacity: string; monthly_fee: string; status: string }

const GENDERS = ['boys', 'girls']
const name = (p?: Person | null) => (p ? `${p.first_name} ${p.last_name}`.trim() : '—')
const genderBadge = (g: string) => <Badge tone={g === 'girls' ? 'violet' : 'blue'}>{label(g)}</Badge>

export default function Hostel() {
  const { user, campusId } = useAuth()
  const canManage = hasPerm(user, 'hostel.manage')
  const [tab, setTab] = useState<'blocks' | 'rooms' | 'allotments'>('rooms')
  const [blockForm, setBlockForm] = useState<BlockDraft | null>(null)
  const [roomForm, setRoomForm] = useState<RoomDraft | null>(null)
  const [allot, setAllot] = useState<{ roomId: string; bedNo: string } | null>(null)

  const { data: summaryData, reload: reloadSummary } = useApi<{ data: HostelSummary }>('/hostel/summary')
  const stats = summaryData?.data

  const refresh = () => { reloadSummary() }

  return (
    <div>
      <PageHeader title="Hostel" subtitle="Blocks, rooms, beds and allotments for the active campus" icon={<IconHostel className="h-5 w-5" />}
        actions={canManage && tab === 'allotments' && (
          <Btn onClick={() => setAllot({ roomId: '', bedNo: '' })} disabled={!campusId} icon={<IconPlus className="h-4 w-4" />}>Allot a bed</Btn>
        )} />

      {!campusId && <Notice tone="amber" title="Select a campus" className="mb-4">Blocks, rooms and beds belong to one campus — pick it in the header to add or change anything.</Notice>}

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Beds" value={stats?.beds_total ?? '—'} hint={`${stats?.rooms ?? 0} rooms across ${stats?.blocks?.length ?? 0} blocks`} icon={<IconHostel className="h-4 w-4" />} tone="blue" />
        <StatCard label="Occupied" value={stats?.beds_occupied ?? '—'} hint={`${stats?.vacated ?? 0} allotments closed out`} icon={<IconStudents className="h-4 w-4" />} tone="green" />
        <StatCard label="Free beds" value={stats?.beds_free ?? '—'} hint="Ready to allot right now" icon={<IconCheck className="h-4 w-4" />} tone="violet" />
        <StatCard label="Occupancy" value={stats ? `${stats.occupancy_pct}%` : '—'} hint={`${fmtPKR(stats?.monthly_revenue)} of room charges per month`} icon={<IconAlert className="h-4 w-4" />} tone={Number(stats?.occupancy_pct ?? 0) >= 90 ? 'amber' : 'gold'} />
      </div>

      <Tabs active={tab} onChange={(k) => setTab(k as typeof tab)} tabs={[
        { key: 'blocks', label: 'Blocks', count: stats?.blocks?.length },
        { key: 'rooms', label: 'Rooms and beds', count: stats?.rooms },
        { key: 'allotments', label: 'Allotments', count: stats?.beds_occupied },
      ]} />

      {tab === 'blocks' && (
        <BlocksPanel canManage={canManage} campusId={campusId} onEdit={setBlockForm} />
      )}
      {tab === 'rooms' && (
        <RoomsPanel canManage={canManage} campusId={campusId} onEdit={setRoomForm}
          onAllot={(roomId, bedNo) => setAllot({ roomId: String(roomId), bedNo: String(bedNo) })} onChanged={refresh} />
      )}
      {tab === 'allotments' && (
        <AllotmentsPanel canManage={canManage} onChanged={refresh} />
      )}

      {blockForm && <BlockModal draft={blockForm} onClose={() => setBlockForm(null)} onSaved={() => { setBlockForm(null); refresh() }} />}
      {roomForm && <RoomModal draft={roomForm} onClose={() => setRoomForm(null)} onSaved={() => { setRoomForm(null); refresh() }} />}
      {allot && (
        <AllotModal seed={allot} onClose={() => setAllot(null)}
          onAllotted={(message) => { setAllot(null); refresh(); toast.success(message) }} />
      )}
    </div>
  )
}

function BlocksPanel({ canManage, campusId, onEdit }: {
  canManage: boolean; campusId: number | null; onEdit: (draft: BlockDraft) => void
}) {
  const [gender, setGender] = useState('')
  const [status, setStatus] = useState('')
  const qs = new URLSearchParams()
  if (gender) qs.set('gender', gender)
  if (status) qs.set('status', status)
  const { data, loading, error, reload } = useApi<{ data: Block[] }>(`/hostel/blocks?${qs}`, [gender, status])
  const rows = data?.data ?? []
  const C = 7

  const edit = (b: Block) => onEdit({
    id: b.id, name: b.name, gender: b.gender,
    warden_user_id: b.warden_user_id ? String(b.warden_user_id) : '', is_active: b.is_active,
  })

  return (
    <div>
      <Toolbar>
        <div className="w-36">
          <select className={selectCls} aria-label="Block gender" value={gender} onChange={(e) => setGender(e.target.value)}>
            <option value="">Boys and girls</option>
            {GENDERS.map((g) => <option key={g} value={g}>{label(g)}</option>)}
          </select>
        </div>
        <div className="w-36">
          <select className={selectCls} aria-label="Block status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All blocks</option>
            <option value="active">Active</option>
            <option value="inactive">Closed</option>
          </select>
        </div>
        {canManage && (
          <Btn className="ml-auto" disabled={!campusId} onClick={() => onEdit({ name: '', gender: 'boys', warden_user_id: '', is_active: true })}
            icon={<IconPlus className="h-4 w-4" />}>New block</Btn>
        )}
      </Toolbar>

      <Table headers={<><Th>Block</Th><Th>Gender</Th><Th>Warden</Th><Th align="right">Rooms</Th><Th align="right">Beds</Th><Th align="right">Free</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((b) => (
          <tr key={b.id} className={canManage ? 'cursor-pointer' : ''} onClick={() => canManage && edit(b)}>
            <Td className="font-medium">{b.name}</Td>
            <Td>{genderBadge(b.gender)}</Td>
            <Td className="text-xs">{b.warden?.name ?? 'No warden assigned'}</Td>
            <Td className="nums text-right">{b.rooms_count}</Td>
            <Td className="nums text-right">{b.beds}</Td>
            <Td className="nums text-right">
              {b.free > 0 ? <Badge tone="green">{b.free} free</Badge> : <Badge tone="rose" dot>Full</Badge>}
            </Td>
            <Td><Status value={b.is_active ? 'active' : 'inactive'} dot /></Td>
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No hostel blocks yet." hint="A block is a single-gender house with its own warden." /></Td></tr>
        )}
      </Table>
    </div>
  )
}

function BlockModal({ draft, onClose, onSaved }: { draft: BlockDraft; onClose: () => void; onSaved: () => void }) {
  const { user } = useAuth()
  const [form, setForm] = useState(draft)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const canPickWarden = hasPerm(user, 'users.view')
  const { data: usersData } = useApi<{ data: Paginated<{ id: number; name: string; email: string }> }>(
    canPickWarden ? '/users?per_page=100' : null, [])
  const wardens = usersData?.data.data ?? []
  const set = (patch: Partial<BlockDraft>) => setForm({ ...form, ...patch })

  async function save() {
    setErr('')
    if (!form.name.trim()) { setErr('Give the block a name.'); return }
    setSaving(true)
    const payload: Record<string, unknown> = {
      name: form.name.trim(), gender: form.gender, is_active: form.is_active,
      warden_user_id: form.warden_user_id ? Number(form.warden_user_id) : null,
    }
    try {
      if (form.id) await api.put(`/hostel/blocks/${form.id}`, payload)
      else await api.post('/hostel/blocks', payload)
      toast.success(form.id ? 'Block updated.' : 'Block created.')
      onSaved()
    } catch (e) { setErr(apiError(e)) } finally { setSaving(false) }
  }

  return (
    <Modal size="md" onClose={onClose} title={form.id ? `Edit ${form.name}` : 'New hostel block'}
      subtitle="A house is single-gender — students may only be allotted to a matching block"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save block'}</Btn>
      </>}>
      <div className="space-y-3">
        <Field label="Name"><input className={inputCls} value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="Jinnah House" /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Gender" hint={form.id ? 'Locked while beds are occupied.' : undefined}>
            <select className={selectCls} value={form.gender} onChange={(e) => set({ gender: e.target.value })}>
              {GENDERS.map((g) => <option key={g} value={g}>{label(g)}</option>)}
            </select>
          </Field>
          <Field label="Warden">
            <select className={selectCls} value={form.warden_user_id} onChange={(e) => set({ warden_user_id: e.target.value })}>
              <option value="">Unassigned</option>
              {wardens.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
          </Field>
        </div>
        {!canPickWarden && <p className="text-xs text-ink-soft">Warden picking needs the user list — ask an administrator to assign one.</p>}
        {form.id !== undefined && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="accent-brand-600" checked={!form.is_active} onChange={(e) => set({ is_active: !e.target.checked })} /> Close this block
          </label>
        )}
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function RoomsPanel({ canManage, campusId, onEdit, onAllot, onChanged }: {
  canManage: boolean; campusId: number | null; onEdit: (draft: RoomDraft) => void
  onAllot: (roomId: number, bedNo: number) => void; onChanged: () => void
}) {
  const [blockId, setBlockId] = useState('')
  const [status, setStatus] = useState('')
  const { data: blockData } = useApi<{ data: Block[] }>('/hostel/blocks')
  const blocks = blockData?.data ?? []

  const qs = new URLSearchParams()
  if (blockId) qs.set('block_id', blockId)
  if (status) qs.set('status', status)
  const { data, loading, error, reload } = useApi<{ data: Room[] }>(`/hostel/rooms?${qs}`, [blockId, status])
  const rooms = data?.data ?? []

  const edit = (r: Room) => onEdit({
    id: r.id, hostel_block_id: String(r.hostel_block_id), number: r.number, floor: r.floor ?? '',
    capacity: String(r.capacity), monthly_fee: r.monthly_fee === null ? '' : String(Number(r.monthly_fee)), status: r.status,
  })

  const vacate = async (room: Room, bed: Bed) => {
    if (!bed.allotment_id) return
    const ok = await askConfirm({
      title: `Vacate bed ${bed.bed_no}?`,
      text: `${bed.student ?? 'This student'} is released from room ${room.number} as of today, and the bed becomes available again.`,
      confirmLabel: 'Vacate bed',
      tone: 'primary',
    })
    if (!ok) return
    try {
      const r = await api.post(`/hostel/allotments/${bed.allotment_id}/vacate`, {})
      toast.success(r.data.message ?? 'Bed vacated.')
      reload(); onChanged()
    } catch (e) { toast.error(apiError(e)) }
  }

  return (
    <div>
      <Toolbar>
        <div className="w-48">
          <select className={selectCls} aria-label="Hostel block" value={blockId} onChange={(e) => setBlockId(e.target.value)}>
            <option value="">All blocks</option>
            {blocks.map((b) => <option key={b.id} value={b.id}>{b.name} ({label(b.gender)})</option>)}
          </select>
        </div>
        <div className="w-44">
          <select className={selectCls} aria-label="Room status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All rooms</option>
            <option value="available">Available</option>
            <option value="maintenance">Under maintenance</option>
          </select>
        </div>
        {canManage && (
          <Btn className="ml-auto" disabled={!campusId}
            onClick={() => onEdit({ hostel_block_id: blockId || String(blocks[0]?.id ?? ''), number: '', floor: '', capacity: '4', monthly_fee: '', status: 'available' })}
            icon={<IconPlus className="h-4 w-4" />}>New room</Btn>
        )}
      </Toolbar>

      {loading && <p className="text-sm text-ink-soft">Loading beds…</p>}
      {error && <ErrorNote text={error} onRetry={reload} className="mb-4" />}

      {!loading && !error && !rooms.length && (
        <Empty text="No rooms in this scope." hint="Add a room to a block, then allot beds to it." className="rounded-2xl border border-line bg-white" />
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {rooms.map((r) => (
          <Card key={r.id} className="flex flex-col p-4">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h3 className="truncate text-sm font-semibold text-slate-900">Room {r.number}</h3>
                <p className="mt-0.5 text-xs text-ink-soft">
                  {r.block?.name ?? '—'}{r.floor ? ` · floor ${r.floor}` : ''} · {fmtPKR(r.monthly_fee)} / month
                  {r.block ? ` ${label(r.block.gender)}` : ''}
                </p>
              </div>
              <div className="flex shrink-0 flex-col items-end gap-1">
                <Badge tone={r.occupied >= r.capacity ? 'rose' : r.free_beds > 0 ? 'green' : 'amber'} dot>
                  {r.occupied} / {r.capacity}
                </Badge>
                {r.status !== 'available' && <Status value={r.status} />}
              </div>
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2">
              {r.beds.map((bed) => {
                const occupied = bed.taken
                const cls = occupied
                  ? 'bg-brand-50 ring-brand-200 hover:border-brand-300'
                  : 'border-dashed bg-white hover:border-brand-300 hover:bg-brand-50/50'
                return (
                  <button key={bed.bed_no} type="button"
                    disabled={!canManage || (occupied && !bed.allotment_id) || (!occupied && (r.status !== 'available' || !campusId))}
                    onClick={() => (occupied ? void vacate(r, bed) : onAllot(r.id, bed.bed_no))}
                    title={occupied ? (canManage ? 'Vacate this bed' : bed.student ?? '') : (canManage ? 'Allot this bed' : 'Free bed')}
                    className={`flex flex-col items-start gap-0.5 rounded-xl border border-line-strong px-3 py-2 text-left transition disabled:cursor-default disabled:opacity-70 ${cls}`}>
                    <span className="nums text-[11px] font-semibold text-ink-soft">Bed {bed.bed_no}</span>
                    <span className={`truncate text-xs font-medium ${occupied ? 'text-slate-800' : 'text-slate-400'}`}>
                      {bed.student ?? 'Free'}
                    </span>
                    {occupied && bed.from_date && <span className="nums text-[10px] text-slate-400">since {fmtDate(bed.from_date)}</span>}
                  </button>
                )
              })}
            </div>

            {canManage && (
              <div className="mt-3 flex justify-end border-t border-line pt-3">
                <Btn size="sm" variant="ghost" onClick={() => edit(r)} icon={<IconEdit className="h-3.5 w-3.5" />}>Edit room</Btn>
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  )
}

function RoomModal({ draft, onClose, onSaved }: { draft: RoomDraft; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState(draft)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const { data: blockData } = useApi<{ data: Block[] }>('/hostel/blocks')
  const blocks = blockData?.data ?? []
  const set = (patch: Partial<RoomDraft>) => setForm({ ...form, ...patch })

  async function save() {
    setErr('')
    if (!form.hostel_block_id) { setErr('Choose the block this room sits in.'); return }
    if (!form.number.trim()) { setErr('Give the room a number.'); return }
    if (!Number(form.capacity)) { setErr('A room needs at least one bed.'); return }
    setSaving(true)
    const payload: Record<string, unknown> = {
      hostel_block_id: Number(form.hostel_block_id),
      number: form.number.trim(),
      floor: form.floor.trim() || null,
      capacity: Number(form.capacity),
      monthly_fee: form.monthly_fee === '' ? null : Number(form.monthly_fee),
      status: form.status,
    }
    try {
      if (form.id) await api.put(`/hostel/rooms/${form.id}`, payload)
      else await api.post('/hostel/rooms', payload)
      toast.success(form.id ? 'Room updated.' : 'Room added.')
      onSaved()
    } catch (e) { setErr(apiError(e)) } finally { setSaving(false) }
  }

  return (
    <Modal size="md" onClose={onClose} title={form.id ? `Edit room ${form.number}` : 'New room'}
      subtitle="Capacity sets how many beds may be allotted; the fee is charged monthly"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save room'}</Btn>
      </>}>
      <div className="space-y-3">
        <Field label="Block">
          <select className={selectCls} value={form.hostel_block_id} onChange={(e) => set({ hostel_block_id: e.target.value })}>
            <option value="">Select…</option>
            {blocks.map((b) => <option key={b.id} value={b.id}>{b.name} ({label(b.gender)})</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Room number"><input className={inputCls} value={form.number} onChange={(e) => set({ number: e.target.value })} placeholder="H-3" /></Field>
          <Field label="Floor"><input className={inputCls} value={form.floor} onChange={(e) => set({ floor: e.target.value })} placeholder="Ground" /></Field>
        </div>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Beds"><input className={inputCls} type="number" min="1" max="40" value={form.capacity} onChange={(e) => set({ capacity: e.target.value })} /></Field>
          <Field label="Monthly fee"><input className={inputCls} type="number" min="0" value={form.monthly_fee} onChange={(e) => set({ monthly_fee: e.target.value })} placeholder="Campus default" /></Field>
          <Field label="Status">
            <select className={selectCls} value={form.status} onChange={(e) => set({ status: e.target.value })}>
              {['available', 'maintenance'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
            </select>
          </Field>
        </div>
        <p className="text-xs text-ink-soft">A room cannot hold more beds than its capacity, and beds must be vacated before it goes for maintenance.</p>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function AllotModal({ seed, onClose, onAllotted }: {
  seed: { roomId: string; bedNo: string }; onClose: () => void; onAllotted: (message: string) => void
}) {
  const [form, setForm] = useState({ student_id: '', hostel_room_id: seed.roomId, bed_no: seed.bedNo, from_date: '', notes: '' })
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)

  const { data: roomsData } = useApi<{ data: Room[] }>('/hostel/rooms')
  const { data: studentData } = useApi<{ data: Person[] }>('/students?per_page=300&status=active')
  const rooms = roomsData?.data ?? []
  const students = studentData?.data ?? []
  const room = rooms.find((r) => String(r.id) === form.hostel_room_id)
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })

  async function save() {
    setErr('')
    if (!form.student_id) { setErr('Choose the student being allotted.'); return }
    if (!form.hostel_room_id) { setErr('Choose the room.'); return }
    setSaving(true)
    const payload: Record<string, unknown> = {
      student_id: Number(form.student_id), hostel_room_id: Number(form.hostel_room_id),
    }
    if (form.bed_no) payload.bed_no = Number(form.bed_no)
    if (form.from_date) payload.from_date = form.from_date
    if (form.notes.trim()) payload.notes = form.notes.trim()
    try {
      const r = await api.post('/hostel/allotments', payload)
      onAllotted(r.data.message ?? 'Bed allotted.')
    } catch (e) { setErr(apiError(e)); setSaving(false) }
  }

  return (
    <Modal size="md" onClose={onClose} title="Allot a bed"
      subtitle="Gender, capacity and double allotments are all refused with the reason"
      footer={<>
        <Btn variant="ghost" onClick={onClose}>Cancel</Btn>
        <Btn disabled={saving} onClick={() => void save()}>{saving ? 'Allotting…' : 'Allot bed'}</Btn>
      </>}>
      <div className="space-y-3">
        <Field label="Student">
          <select className={selectCls} value={form.student_id} onChange={(e) => set({ student_id: e.target.value })}>
            <option value="">Select…</option>
            {students.map((s) => <option key={s.id} value={s.id}>{s.roll_no ? `${s.roll_no} — ` : ''}{name(s)}</option>)}
          </select>
        </Field>
        <Field label="Room">
          <select className={selectCls} value={form.hostel_room_id} onChange={(e) => set({ hostel_room_id: e.target.value, bed_no: '' })}>
            <option value="">Select…</option>
            {rooms.map((r) => (
              <option key={r.id} value={r.id} disabled={r.status !== 'available' || r.free_beds === 0}>
                {r.block?.name ?? '—'} · {r.number} — {r.free_beds} free ({label(r.block?.gender ?? '')})
              </option>
            ))}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Bed" hint="Leave blank for the first free bed">
            <select className={selectCls} value={form.bed_no} onChange={(e) => set({ bed_no: e.target.value })}>
              <option value="">First free</option>
              {room ? Array.from({ length: room.capacity }, (_, i) => i + 1).map((bed) => {
                const held = room.beds.find((b) => b.bed_no === bed)?.taken
                return <option key={bed} value={String(bed)} disabled={held}>{`Bed ${bed}${held ? ' — taken' : ''}`}</option>
              }) : null}
            </select>
          </Field>
          <Field label="From"><input className={inputCls} type="date" value={form.from_date} onChange={(e) => set({ from_date: e.target.value })} /></Field>
        </div>
        {room && (
          <Notice tone={room.status === 'available' && room.free_beds > 0 ? 'blue' : 'amber'}>
            {room.status !== 'available'
              ? `Room ${room.number} is ${label(room.status)} — no bed can be allotted to it.`
              : `${room.block?.name ?? 'This block'} is a ${label(room.block?.gender ?? '')} hostel · ${room.free_beds} of ${room.capacity} bed(s) free · ${fmtPKR(room.monthly_fee)} per month.`}
          </Notice>
        )}
        <Field label="Notes"><input className={inputCls} value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Sibling of an existing resident" /></Field>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function AllotmentsPanel({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const [status, setStatus] = useState('active')
  const [blockId, setBlockId] = useState('')
  const [search, setSearch] = useState('')
  const { data: blockData } = useApi<{ data: Block[] }>('/hostel/blocks')
  const blocks = blockData?.data ?? []

  const qs = new URLSearchParams()
  if (status) qs.set('status', status)
  if (blockId) qs.set('block_id', blockId)
  const { data, loading, error, reload } = useApi<{ data: Allot[] }>(`/hostel/allotments?${qs}`, [status, blockId])
  const all = data?.data ?? []
  const needle = search.trim().toLowerCase()
  const rows = needle ? all.filter((a) => a.student_name.toLowerCase().includes(needle)) : all
  const C = canManage ? 8 : 6

  const vacate = async (a: Allot) => {
    const ok = await askConfirm({
      title: `Vacate bed ${a.bed_no}?`,
      text: `${a.student_name} in room ${a.room?.number ?? '—'} (${a.room?.block?.name ?? '—'}) is released as of today and the bed becomes free.`,
      confirmLabel: 'Vacate bed',
      tone: 'primary',
    })
    if (!ok) return
    try {
      const r = await api.post(`/hostel/allotments/${a.id}/vacate`, {})
      toast.success(r.data.message ?? 'Bed vacated.')
      reload(); onChanged()
    } catch (e) { toast.error(apiError(e)) }
  }

  return (
    <div>
      <Toolbar>
        <SearchInput className="w-64 max-w-full" value={search} onChange={setSearch} placeholder="Search student…" />
        <div className="w-40">
          <select className={selectCls} aria-label="Allotment status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">Occupied beds</option>
            <option value="vacated">Vacated</option>
            <option value="">Allotment history</option>
          </select>
        </div>
        <div className="w-48">
          <select className={selectCls} aria-label="Hostel block" value={blockId} onChange={(e) => setBlockId(e.target.value)}>
            <option value="">All blocks</option>
            {blocks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </div>
      </Toolbar>

      <Table headers={<>
        <Th>Student</Th><Th>Block</Th><Th>Room</Th><Th align="right">Bed</Th><Th>From</Th><Th>To</Th><Th>Status</Th>
        {canManage && <Th align="right">Actions</Th>}
      </>} cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((a) => (
          <tr key={a.id}>
            <Td className="font-medium">
              {a.student_name}
              <p className="text-xs text-slate-400">{a.student?.roll_no ?? '—'}{a.notes ? ` · ${a.notes}` : ''}</p>
            </Td>
            <Td>{a.room?.block?.name ?? '—'}</Td>
            <Td className="nums">{a.room?.number ?? '—'}</Td>
            <Td className="nums text-right">{a.bed_no}</Td>
            <Td className="nums text-xs">{fmtDate(a.from_date)}</Td>
            <Td className="nums text-xs">{a.to_date ? fmtDate(a.to_date) : '—'}</Td>
            <Td><Status value={a.status} dot /></Td>
            {canManage && (
              <Td className="text-right">
                {a.status === 'active'
                  ? <Btn size="sm" variant="ghost" onClick={() => void vacate(a)} icon={<IconArrowRight className="h-3.5 w-3.5" />}>Vacate</Btn>
                  : <span className="text-xs text-slate-400">Closed by {a.approver?.name ?? '—'}</span>}
              </Td>
            )}
          </tr>
        ))}
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No allotments for this filter." hint="Open the Rooms tab and click a free bed to allot one." /></Td></tr>
        )}
      </Table>
    </div>
  )
}
