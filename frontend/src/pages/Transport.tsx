import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { todayISO } from '../lib/dates'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { fmtPKR, hasPerm, type Paginated } from '../lib/types'
import { label } from '../lib/labels'
import { toast } from '../store/toasts'
import { askConfirm } from '../store/confirm'
import {
  IconCheck, IconEdit, IconFees, IconPlus, IconStudents, IconTrash, IconUsers, IconTransport,
} from '../components/icons'
import {
  Badge, Btn, Empty, ErrorNote, Field, Modal, PageHeader, StatCard, Status, Table, Tabs, Td, Th, Toolbar,
  inputCls, selectCls,
} from '../components/ui'

interface Vehicle {
  id: number; code: string; plate_no?: string | null; type: string; capacity: number
  driver_name?: string | null; driver_phone?: string | null; is_active: boolean; routes_count?: number
}
interface Stop { id: number; name: string; landmark?: string | null; order_no: number; pickup_time?: string | null; drop_time?: string | null }
interface RouteRow {
  id: number; code: string; name: string; transport_vehicle_id: number | null; pickup_time?: string | null
  drop_time?: string | null; monthly_fee: string | number; is_active: boolean; notes?: string | null
  vehicle?: Vehicle | null; stops: Stop[]; active_students_count: number
}
interface AssignRow {
  id: number; kind: string; from_date?: string | null; to_date?: string | null; status: string
  student?: { id: number; first_name: string; last_name: string; admission_no: string; school_class?: { name: string } | null; section?: { name: string } | null } | null
  route?: { name: string; code: string } | null
  stop?: { name: string } | null
}
interface Summary {
  routes: number; active_students: number; vehicles: number; seats: number; monthly_fee_total: number
  route_loads: { id: number; code: string; name: string; students: number; capacity: number; vehicle: string | null; overloaded: boolean }[]
}
const KINDS = ['pickup', 'drop', 'both']
const time = (v?: string | null) => (v ? v.slice(0, 5) : '—')

export default function Transport() {
  const { user } = useAuth()
  const canManage = hasPerm(user, 'transport.manage')
  const [tab, setTab] = useState<'routes' | 'vehicles' | 'students'>('routes')

  const { data: summaryData, reload: reloadSummary } = useApi<{ data: Summary }>('/transport/summary')
  const stats = summaryData?.data

  return (
    <div>
      <PageHeader title="Transport" subtitle="Vehicles, routes with ordered stops and student assignments" icon={<IconTransport className="h-5 w-5" />} />

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <StatCard label="Routes" value={stats?.routes ?? '—'} hint="Active transport routes" icon={<IconTransport className="h-4 w-4" />} tone="blue" />
        <StatCard label="On transport" value={stats?.active_students ?? '—'} hint="Students with an active seat" icon={<IconStudents className="h-4 w-4" />} tone="green" />
        <StatCard label="Vehicles" value={stats?.vehicles ?? '—'} hint="Fleet marked active" icon={<IconUsers className="h-4 w-4" />} tone="violet" />
        <StatCard label="Seats" value={stats?.seats ?? '—'} hint="Combined vehicle capacity" icon={<IconCheck className="h-4 w-4" />} tone="gold" />
        <StatCard label="Monthly total" value={fmtPKR(stats?.monthly_fee_total)} hint="Route fees shown for reference only" icon={<IconFees className="h-4 w-4" />} tone="amber" />
      </div>

      <Tabs active={tab} onChange={(k) => setTab(k as typeof tab)}
        tabs={[
          { key: 'routes', label: 'Routes', count: stats?.routes ?? 0 },
          { key: 'vehicles', label: 'Vehicles', count: stats?.vehicles ?? 0 },
          { key: 'students', label: 'Students', count: stats?.active_students ?? 0 },
        ]} />

      {tab === 'routes' && <RoutesPanel canManage={canManage} onChanged={() => { reloadSummary() }} />}
      {tab === 'vehicles' && <VehiclesPanel canManage={canManage} onChanged={() => { reloadSummary() }} />}
      {tab === 'students' && <StudentsPanel canManage={canManage} onChanged={() => { reloadSummary() }} />}
    </div>
  )
}

function loadBadge(route: RouteRow) {
  const seats = route.vehicle?.capacity ?? 0
  const students = route.active_students_count
  if (!route.vehicle) return <Badge tone="slate">{students} riders</Badge>
  if (students > seats) return <Badge tone="rose" dot>{students} / {seats}</Badge>
  return <Badge tone="green" dot>{students} / {seats}</Badge>
}

function RoutesPanel({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const { data, loading, error, reload } = useApi<{ data: RouteRow[] }>('/transport/routes')
  const { data: vehiclesData } = useApi<{ data: Vehicle[] }>('/transport/vehicles')
  const vehicles = vehiclesData?.data ?? []
  const rows = data?.data ?? []
  const [drawer, setDrawer] = useState<RouteRow | null>(null)
  const [form, setForm] = useState<Partial<RouteRow> & { id?: number } | null>(null)
  const C = 7

  const refresh = () => { reload(); onChanged(); if (drawer) setDrawer(rows.find((r) => r.id === drawer.id) ?? drawer) }

  return (
    <div>
      <Toolbar>
        {canManage && <Btn className="ml-auto" onClick={() => setForm({ name: '', code: '', transport_vehicle_id: null, monthly_fee: '', pickup_time: '', drop_time: '', notes: '' })} icon={<IconPlus className="h-4 w-4" />}>New Route</Btn>}
      </Toolbar>

      <Table headers={<><Th>Code</Th><Th>Route</Th><Th>Stops</Th><Th>Pickup / Drop</Th><Th>Driver</Th><Th align="right">Monthly fee</Th><Th>Load</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((r) => (
          <tr key={r.id} className="cursor-pointer" onClick={() => setDrawer(r)}>
            <Td className="nums text-xs">{r.code}</Td>
            <Td className="font-medium">{r.name}<span className="ml-1 text-xs text-slate-400">{r.vehicle?.code ?? 'no vehicle'}</span></Td>
            <Td className="nums">{r.stops.length}</Td>
            <Td className="nums text-xs">{time(r.pickup_time)} · {time(r.drop_time)}</Td>
            <Td>{r.vehicle?.driver_name ?? '—'}</Td>
            <Td className="nums text-right">{fmtPKR(r.monthly_fee)}</Td>
            <Td>{loadBadge(r)}</Td>
          </tr>
        ))}
        {!rows.length && !loading && !error && <tr><Td colSpan={C}><Empty text="No routes yet." hint="Add a route, then attach ordered stops and students." /></Td></tr>}
      </Table>

      {form && <RouteFormModal form={form} vehicles={vehicles} onClose={() => setForm(null)} onSaved={() => { setForm(null); refresh() }} />}

      {drawer && (
        <RouteDrawerModal
          key={drawer.id}
          routeId={drawer.id}
          canManage={canManage}
          onClose={() => { setDrawer(null); reload(); onChanged() }}
          onChanged={() => { reload(); onChanged(); }}
          onEdit={(updated) => { setForm(updated); }}
          onDeleted={() => { setDrawer(null); reload(); onChanged(); }}
        />
      )}
    </div>
  )
}

function RouteFormModal({ form, vehicles, onClose, onSaved }: {
  form: Partial<RouteRow> & { id?: number }; vehicles: Vehicle[]; onClose: () => void; onSaved: () => void
}) {
  const [draft, setDraft] = useState({
    id: form.id,
    name: form.name ?? '',
    code: form.code ?? '',
    transport_vehicle_id: String(form.transport_vehicle_id ?? ''),
    pickup_time: (form.pickup_time ?? '').slice(0, 5),
    drop_time: (form.drop_time ?? '').slice(0, 5),
    monthly_fee: String(form.monthly_fee ?? ''),
    notes: form.notes ?? '',
    is_active: form.is_active ?? true,
  })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true); setErr('')
    const payload = {
      name: draft.name, code: draft.code,
      transport_vehicle_id: draft.transport_vehicle_id ? Number(draft.transport_vehicle_id) : null,
      pickup_time: draft.pickup_time || null, drop_time: draft.drop_time || null,
      monthly_fee: draft.monthly_fee === '' ? 0 : Number(draft.monthly_fee),
      notes: draft.notes || null, is_active: draft.is_active,
    }
    try {
      if (draft.id) await api.put(`/transport/routes/${draft.id}`, payload)
      else await api.post('/transport/routes', payload)
      onSaved()
    } catch (e) { setErr(apiError(e)); setBusy(false) }
  }

  return (
    <Modal title={draft.id ? `Edit route ${draft.code}` : 'New route'} subtitle="Monthly fee is for reference only — no invoices are generated"
      onClose={onClose} size="md"
      footer={<><Btn variant="ghost" onClick={onClose}>Cancel</Btn><Btn onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save route'}</Btn></>}>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Route name"><input className={inputCls} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></Field>
          <Field label="Code"><input className={inputCls} value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value.toUpperCase() })} placeholder="R-03" /></Field>
        </div>
        <Field label="Assigned vehicle">
          <select className={selectCls} value={draft.transport_vehicle_id} onChange={(e) => setDraft({ ...draft, transport_vehicle_id: e.target.value })}>
            <option value="">No vehicle</option>
            {vehicles.map((v) => <option key={v.id} value={v.id}>{v.code} · {label(v.type)} · seats {v.capacity}</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Pickup"><input type="time" className={inputCls} value={draft.pickup_time} onChange={(e) => setDraft({ ...draft, pickup_time: e.target.value })} /></Field>
          <Field label="Drop"><input type="time" className={inputCls} value={draft.drop_time} onChange={(e) => setDraft({ ...draft, drop_time: e.target.value })} /></Field>
          <Field label="Monthly fee (PKR)"><input type="number" min="0" className={inputCls} value={draft.monthly_fee} onChange={(e) => setDraft({ ...draft, monthly_fee: e.target.value })} /></Field>
        </div>
        <Field label="Notes"><textarea className={`${inputCls} min-h-16`} value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-brand-600" checked={draft.is_active} onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })} /> Active route</label>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function RouteDrawerModal({ routeId, canManage, onClose, onChanged, onEdit, onDeleted }: {
  routeId: number; canManage: boolean; onClose: () => void; onChanged: () => void
  onEdit: (route: Partial<RouteRow> & { id: number }) => void; onDeleted: () => void
}) {
  const { data: routesData, reload: reloadRoutes } = useApi<{ data: RouteRow[] }>('/transport/routes')
  const route = routesData?.data.find((r) => r.id === routeId) ?? null
  const { data: assignsData, reload: reloadAssigns } = useApi<{ data: AssignRow[] }>(`/transport/assignments?transport_route_id=${routeId}&status=active`)
  const students = assignsData?.data ?? []
  const [stopDraft, setStopDraft] = useState<Partial<Stop> & { id?: number } | null>(null)
  const [assignOpen, setAssignOpen] = useState(false)
  const [err, setErr] = useState('')

  const afterStop = () => { reloadRoutes(); onChanged() }

  async function saveStop() {
    if (!route || !stopDraft?.name) return
    setErr('')
    const payload = {
      name: stopDraft.name, landmark: stopDraft.landmark || null,
      order_no: Number(stopDraft.order_no || route.stops.length + 1),
      pickup_time: stopDraft.pickup_time || null, drop_time: stopDraft.drop_time || null,
    }
    try {
      if (stopDraft.id) await api.put(`/transport/stops/${stopDraft.id}`, payload)
      else await api.post(`/transport/routes/${route.id}/stops`, payload)
      setStopDraft(null); afterStop()
    } catch (e) { setErr(apiError(e)) }
  }

  async function removeStop(stop: Stop) {
    if (!await askConfirm({ title: 'Remove this stop?', text: `"${stop.name}" (stop #${stop.order_no}) will be deleted. Students still assigned to it must be moved first.`, confirmLabel: 'Remove stop' })) return
    setErr('')
    try { await api.delete(`/transport/stops/${stop.id}`); afterStop() } catch (e) { setErr(apiError(e)) }
  }

  async function discontinue(a: AssignRow) {
    if (!await askConfirm({ title: 'Discontinue this student?', text: `${a.student?.first_name ?? 'Student'} ${a.student?.last_name ?? ''} will be marked discontinued with today's date.`, confirmLabel: 'Discontinue' })) return
    setErr('')
    try { await api.post(`/transport/assignments/${a.id}/discontinue`); reloadAssigns(); afterStop() } catch (e) { setErr(apiError(e)) }
  }

  async function deleteRoute() {
    if (!route) return
    if (!await askConfirm({ title: `Delete route ${route.code}?`, text: 'This only works once every student on the route has been discontinued. Stops are removed with the route.', confirmLabel: 'Delete route' })) return
    setErr('')
    try { await api.delete(`/transport/routes/${route.id}`); onDeleted() } catch (e) { setErr(apiError(e)) }
  }

  const seats = route?.vehicle?.capacity ?? 0
  const overloaded = seats > 0 && students.length > seats

  return (
    <Modal title={route ? `${route.code} · ${route.name}` : 'Loading route…'}
      subtitle={route ? `${route.vehicle ? `${route.vehicle.code} · ${route.vehicle.driver_name ?? 'no driver'} · seats ${route.vehicle.capacity}` : 'No vehicle assigned'} · fee ${fmtPKR(route.monthly_fee)}` : ''}
      onClose={onClose} size="lg"
      footer={route && canManage ? <>
        <Btn variant="danger" onClick={deleteRoute}>Delete route</Btn>
        <Btn variant="ghost" onClick={() => onEdit(route)}>Edit route</Btn>
        <Btn variant="ghost" onClick={onClose}>Close</Btn>
      </> : <Btn variant="ghost" onClick={onClose}>Close</Btn>}>
      {!route ? <p className="text-sm text-ink-soft">Loading…</p> : (
        <div className="space-y-5">
          {err && <ErrorNote text={err} />}

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h4 className="text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">Stops in order</h4>
              {canManage && <Btn size="sm" variant="soft" onClick={() => setStopDraft({ name: '', landmark: '', order_no: route.stops.length + 1 })} icon={<IconPlus className="h-3.5 w-3.5" />}>Add stop</Btn>}
            </div>
            <Table headers={<><Th>#</Th><Th>Stop</Th><Th>Landmark</Th><Th>Pickup / Drop</Th>{canManage && <Th />}</>} cols={canManage ? 5 : 4}>
              {route.stops.map((s) => (
                <tr key={s.id}>
                  <Td className="nums text-xs">{s.order_no}</Td>
                  <Td className="font-medium">{s.name}</Td>
                  <Td className="text-ink-soft">{s.landmark ?? '—'}</Td>
                  <Td className="nums text-xs">{time(s.pickup_time)} · {time(s.drop_time)}</Td>
                  {canManage && (
                    <Td className="text-right whitespace-nowrap">
                      <Btn size="sm" variant="ghost" onClick={() => setStopDraft({ ...s })} icon={<IconEdit className="h-3.5 w-3.5" />}>Edit</Btn>
                      <Btn size="sm" variant="ghost" onClick={() => removeStop(s)} icon={<IconTrash className="h-3.5 w-3.5" />}>Remove</Btn>
                    </Td>
                  )}
                </tr>
              ))}
              {!route.stops.length && <tr><Td colSpan={canManage ? 5 : 4}><Empty text="No stops yet." hint="Add the first pickup point in order." /></Td></tr>}
            </Table>

            {stopDraft && (
              <div className="mt-3 grid grid-cols-2 gap-2 rounded-xl border border-line bg-canvas p-3 sm:grid-cols-5">
                <Field label="Name"><input className={inputCls} value={stopDraft.name ?? ''} onChange={(e) => setStopDraft({ ...stopDraft, name: e.target.value })} /></Field>
                <Field label="Order"><input type="number" min="1" className={inputCls} value={stopDraft.order_no ?? ''} onChange={(e) => setStopDraft({ ...stopDraft, order_no: Number(e.target.value) })} /></Field>
                <Field label="Landmark"><input className={inputCls} value={stopDraft.landmark ?? ''} onChange={(e) => setStopDraft({ ...stopDraft, landmark: e.target.value })} /></Field>
                <Field label="Pickup"><input type="time" className={inputCls} value={(stopDraft.pickup_time ?? '').slice(0, 5)} onChange={(e) => setStopDraft({ ...stopDraft, pickup_time: e.target.value })} /></Field>
                <Field label="Drop"><input type="time" className={inputCls} value={(stopDraft.drop_time ?? '').slice(0, 5)} onChange={(e) => setStopDraft({ ...stopDraft, drop_time: e.target.value })} /></Field>
                <div className="col-span-2 flex gap-2 sm:col-span-5">
                  <Btn size="sm" onClick={saveStop}>{stopDraft.id ? 'Save stop' : 'Add stop'}</Btn>
                  <Btn size="sm" variant="ghost" onClick={() => setStopDraft(null)}>Cancel</Btn>
                </div>
              </div>
            )}
          </section>

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h4 className="text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-soft">
                Students on route — <span className={overloaded ? 'text-rose-600' : ''}>{students.length}{seats ? ` / ${seats} seats` : ''}</span>
                {overloaded && <span className="ml-2 text-rose-600">Over capacity</span>}
              </h4>
              {canManage && <Btn size="sm" onClick={() => setAssignOpen(true)} icon={<IconPlus className="h-3.5 w-3.5" />}>Assign student</Btn>}
            </div>
            <Table headers={<><Th>Student</Th><Th>Class</Th><Th>Stop</Th><Th>Service</Th><Th>From</Th>{canManage && <Th />}</>} cols={canManage ? 6 : 5}>
              {students.map((a) => (
                <tr key={a.id}>
                  <Td className="font-medium">{a.student?.first_name} {a.student?.last_name}<span className="ml-1 nums text-xs text-slate-400">{a.student?.admission_no}</span></Td>
                  <Td className="text-xs">{a.student?.school_class?.name ?? '—'}{a.student?.section?.name ? ` · ${a.student.section.name}` : ''}</Td>
                  <Td>{a.stop?.name ?? '—'}</Td>
                  <Td>{label(a.kind)}</Td>
                  <Td className="nums text-xs">{a.from_date ?? '—'}</Td>
                  {canManage && <Td className="text-right"><Btn size="sm" variant="ghost" onClick={() => discontinue(a)}>Discontinue</Btn></Td>}
                </tr>
              ))}
              {!students.length && <tr><Td colSpan={canManage ? 6 : 5}><Empty text="No active students on this route yet." /></Td></tr>}
            </Table>
          </section>
        </div>
      )}

      {assignOpen && route && (
        <AssignStudentModal route={route} onClose={() => setAssignOpen(false)}
          onAssigned={() => { setAssignOpen(false); reloadAssigns(); afterStop() }} />
      )}
    </Modal>
  )
}

function AssignStudentModal({ route, onClose, onAssigned }: { route: RouteRow; onClose: () => void; onAssigned: () => void }) {
  const { data } = useApi<Paginated<{ id: number; first_name: string; last_name: string; admission_no: string }>>('/students?per_page=100&status=active')
  const students = data?.data ?? []
  const [form, setForm] = useState(() => ({ student_id: '', transport_stop_id: '', kind: 'both', from_date: todayISO() }))
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true); setErr('')
    try {
      await api.post('/transport/assignments', {
        student_id: Number(form.student_id), transport_route_id: route.id,
        transport_stop_id: form.transport_stop_id ? Number(form.transport_stop_id) : null,
        kind: form.kind, from_date: form.from_date || null,
      })
      onAssigned()
    } catch (e) { setErr(apiError(e)); setBusy(false) }
  }

  return (
    <Modal title="Assign a student" subtitle={`${route.code} · ${route.name}`} onClose={onClose} size="sm"
      footer={<><Btn variant="ghost" onClick={onClose}>Cancel</Btn><Btn onClick={save} disabled={busy || !form.student_id}>{busy ? 'Saving…' : 'Assign'}</Btn></>}>
      <div className="space-y-3">
        <Field label="Student">
          <select className={selectCls} value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })}>
            <option value="">Select an active student…</option>
            {students.map((s) => <option key={s.id} value={s.id}>{s.first_name} {s.last_name} — {s.admission_no}</option>)}
          </select>
        </Field>
        <Field label="Pickup stop">
          <select className={selectCls} value={form.transport_stop_id} onChange={(e) => setForm({ ...form, transport_stop_id: e.target.value })}>
            <option value="">No stop</option>
            {route.stops.map((s) => <option key={s.id} value={s.id}>#{s.order_no} {s.name}</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Service">
            <select className={selectCls} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              {KINDS.map((k) => <option key={k} value={k}>{label(k)}</option>)}
            </select>
          </Field>
          <Field label="From date"><input type="date" className={inputCls} value={form.from_date} onChange={(e) => setForm({ ...form, from_date: e.target.value })} /></Field>
        </div>
        <p className="text-xs text-ink-soft">A student may hold only one active route. The assigned vehicle must have a free seat.</p>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function VehiclesPanel({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const { data, loading, error, reload } = useApi<{ data: Vehicle[] }>('/transport/vehicles')
  const rows = data?.data ?? []
  const [form, setForm] = useState<Partial<Vehicle> & { id?: number } | null>(null)
  const C = 6

  return (
    <div>
      <Toolbar>
        {canManage && <Btn className="ml-auto" onClick={() => setForm({ code: '', type: 'van', capacity: 20, is_active: true })} icon={<IconPlus className="h-4 w-4" />}>Add vehicle</Btn>}
      </Toolbar>
      <Table headers={<><Th>Code</Th><Th>Type</Th><Th>Plate</Th><Th align="right">Capacity</Th><Th>Driver</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {rows.map((v) => (
          <tr key={v.id} className="cursor-pointer" onClick={() => canManage && setForm(v)}>
            <Td className="nums font-medium">{v.code}</Td>
            <Td>{label(v.type)}</Td>
            <Td className="nums text-xs">{v.plate_no ?? '—'}</Td>
            <Td className="nums text-right">{v.capacity}</Td>
            <Td>{v.driver_name ?? '—'}<span className="ml-1 nums text-xs text-slate-400">{v.driver_phone}</span></Td>
            <Td><Badge tone={v.is_active ? 'green' : 'slate'} dot>{v.is_active ? 'Active' : 'Inactive'}</Badge></Td>
          </tr>
        ))}
        {!rows.length && !loading && !error && <tr><Td colSpan={C}><Empty text="No vehicles in this campus yet." /></Td></tr>}
      </Table>
      {form && <VehicleFormModal form={form} onClose={() => setForm(null)} onSaved={() => { setForm(null); reload(); onChanged() }} />}
    </div>
  )
}

function VehicleFormModal({ form, onClose, onSaved }: { form: Partial<Vehicle> & { id?: number }; onClose: () => void; onSaved: () => void }) {
  const [draft, setDraft] = useState({
    id: form.id, code: form.code ?? '', plate_no: form.plate_no ?? '', type: form.type ?? 'van',
    capacity: String(form.capacity ?? 20), driver_name: form.driver_name ?? '', driver_phone: form.driver_phone ?? '', is_active: form.is_active ?? true,
  })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function save() {
    setBusy(true); setErr('')
    const payload = { ...draft, capacity: Number(draft.capacity) }
    delete (payload as Record<string, unknown>).id
    try {
      if (draft.id) await api.put(`/transport/vehicles/${draft.id}`, payload)
      else await api.post('/transport/vehicles', payload)
      onSaved()
    } catch (e) { setErr(apiError(e)); setBusy(false) }
  }

  return (
    <Modal title={draft.id ? `Edit ${draft.code}` : 'Add vehicle'} subtitle="Capacity drives the route overload warnings" onClose={onClose} size="md"
      footer={<><Btn variant="ghost" onClick={onClose}>Cancel</Btn><Btn onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save vehicle'}</Btn></>}>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Code"><input className={inputCls} value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value.toUpperCase() })} placeholder="VAN-01" /></Field>
          <Field label="Plate number"><input className={inputCls} value={draft.plate_no} onChange={(e) => setDraft({ ...draft, plate_no: e.target.value })} /></Field>
          <Field label="Type">
            <select className={selectCls} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
              {['van', 'coaster', 'bus'].map((t) => <option key={t} value={t}>{label(t)}</option>)}
            </select>
          </Field>
          <Field label="Capacity"><input type="number" min="1" className={inputCls} value={draft.capacity} onChange={(e) => setDraft({ ...draft, capacity: e.target.value })} /></Field>
          <Field label="Driver name"><input className={inputCls} value={draft.driver_name} onChange={(e) => setDraft({ ...draft, driver_name: e.target.value })} /></Field>
          <Field label="Driver phone"><input className={inputCls} value={draft.driver_phone} onChange={(e) => setDraft({ ...draft, driver_phone: e.target.value })} /></Field>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-brand-600" checked={draft.is_active} onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })} /> Active vehicle</label>
        {err && <ErrorNote text={err} />}
      </div>
    </Modal>
  )
}

function StudentsPanel({ canManage, onChanged }: { canManage: boolean; onChanged: () => void }) {
  const [status, setStatus] = useState('active')
  const { data, loading, error, reload } = useApi<{ data: AssignRow[] }>(`/transport/assignments${status ? `?status=${status}` : ''}`, [status])
  const rows = data?.data ?? []

  async function discontinue(a: AssignRow) {
    if (!await askConfirm({ title: 'Discontinue this student?', text: `${a.student?.first_name ?? 'Student'} ${a.student?.last_name ?? ''} will be marked discontinued.`, confirmLabel: 'Discontinue' })) return
    try { await api.post(`/transport/assignments/${a.id}/discontinue`); reload(); onChanged() } catch (e) { toast.error(apiError(e)) }
  }

  return (
    <div>
      <Toolbar>
        <select className={`${selectCls} w-40`} value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Assignment status">
          {['active', 'discontinued', ''].map((s) => <option key={s} value={s}>{s ? label(s) : 'All'}</option>)}
        </select>
      </Toolbar>
      <Table headers={<><Th>Student</Th><Th>Admission #</Th><Th>Route</Th><Th>Stop</Th><Th>Service</Th>{canManage && <Th />}</>}
        cols={canManage ? 6 : 5} loading={loading} error={error} onRetry={reload}>
        {rows.map((a) => (
          <tr key={a.id}>
            <Td className="font-medium">{a.student?.first_name} {a.student?.last_name}</Td>
            <Td className="nums text-xs">{a.student?.admission_no}</Td>
            <Td>{a.route ? `${a.route.code} · ${a.route.name}` : '—'}</Td>
            <Td>{a.stop?.name ?? '—'}</Td>
            <Td><span className="flex items-center gap-2"><Status value={a.kind} tone="blue" />{a.status !== 'active' && <Status value={a.status} />}</span></Td>
            {canManage && <Td className="text-right">{a.status === 'active' && <Btn size="sm" variant="ghost" onClick={() => discontinue(a)}>Discontinue</Btn>}</Td>}
          </tr>
        ))}
        {!rows.length && !loading && !error && <tr><Td colSpan={canManage ? 6 : 5}><Empty text="No transport assignments here." /></Td></tr>}
      </Table>
    </div>
  )
}
