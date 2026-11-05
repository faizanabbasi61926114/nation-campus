import { useEffect, useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { useAuth } from '../store/auth'
import { IconPlus, IconUsers } from '../components/icons'
import { Avatar, Badge, Btn, Empty, ErrorNote, Field, Modal, PageHeader, Pagination, Status, Table, Td, Th, inputCls, selectCls } from '../components/ui'

interface Role { id: number; name: string; slug: string; is_system: boolean; permissions: { id: string }[] }
interface Campus { id: number; name: string; code: string }
interface UserRow { id: number; name: string; email: string; campus_id: number | null; is_active: boolean; role_assignments?: never; roleAssignments?: (Role & { pivot: { campus_id: number | null } })[] }

interface FormState {
  id?: number; name: string; email: string; password?: string; phone?: string; cnic?: string
  campus_id?: number | null; is_active?: boolean; roles: { role_id: number; campus_id: number | null }[]
}

const C = 5

export default function Users() {
  const { user: me } = useAuth()
  const { data: campusData } = useApi<{ data: Campus[] }>('/campuses')
  const { data: roleData } = useApi<{ data: Role[] }>('/roles')
  const [page, setPage] = useState(1)
  const { data, loading, error, reload } = useApi<{ data: UserRow[]; current_page: number; last_page: number; total: number }>(`/users?per_page=25&page=${page}`)
  const [form, setForm] = useState<FormState | null>(null)
  const [err, setErr] = useState('')
  const campuses = campusData?.data ?? []
  const roles = roleData?.data ?? []
  useEffect(() => { reload() }, [page]) // eslint-disable-line

  async function save() {
    if (!form) return
    setErr('')
    const payload: Record<string, unknown> = { ...form }
    if (!form.password) delete payload.password
    try {
      if (form.id) await api.put(`/users/${form.id}`, payload)
      else await api.post('/users', payload)
      setForm(null); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  function openEdit(u: UserRow) {
    setForm({
      id: u.id, name: u.name, email: u.email, campus_id: u.campus_id, is_active: u.is_active,
      roles: (u.roleAssignments || []).map((r) => ({ role_id: r.id, campus_id: r.pivot?.campus_id ?? null })),
    })
  }

  return (
    <div>
      <PageHeader title="Users & Roles" subtitle="Staff accounts across your campuses" icon={<IconUsers className="h-5 w-5" />}
        actions={<Btn onClick={() => setForm({ name: '', email: '', roles: [] })} icon={<IconPlus className="h-4 w-4" />}>Add User</Btn>} />
      <Table headers={<><Th>Name</Th><Th>Email</Th><Th>Roles</Th><Th>Campus</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {!(data?.data ?? []).length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No users yet." hint="Add a user to give staff access to their campus." /></Td></tr>
        )}
        {(data?.data ?? []).map((u) => (
          <tr key={u.id} className="cursor-pointer" onClick={() => openEdit(u)}>
            <Td><div className="flex items-center gap-2.5"><Avatar name={u.name} /><span className="font-medium">{u.name}</span></div></Td>
            <Td>{u.email}</Td>
            <Td><div className="flex flex-wrap items-center gap-1.5">{(u.roleAssignments || []).map((r) => <span key={r.id} className="inline-flex items-center gap-1"><Status value={r.slug} />{r.pivot?.campus_id ? <span className="text-[11px] text-ink-soft">@{campuses.find((c) => c.id === r.pivot?.campus_id)?.code ?? ''}</span> : <span className="text-[11px] text-ink-soft">(all)</span>}</span>)}</div></Td>
            <Td className="nums">{campuses.find((c) => c.id === u.campus_id)?.code ?? '—'}</Td>
            <Td>{u.is_active ? <Badge tone="green" dot>Active</Badge> : <Badge tone="rose" dot>Disabled</Badge>}</Td>
          </tr>
        ))}
      </Table>
      {data && data.last_page > 1 && (
        <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} perPage={25} onPage={setPage} busy={loading} />
      )}

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? `Edit ${form.name}` : 'New User'}
          subtitle="Account details and role assignments"
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={save}>Save</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Name"><input className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Email"><input className={inputCls} type="email" autoComplete="username" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label={form.id ? 'Reset password (optional)' : 'Password'}>
              <input className={inputCls} type="password" autoComplete={form.id ? 'new-password' : 'new-password'} value={form.password || ''} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder={form.id ? 'Leave blank to keep current password' : 'Minimum 8 characters'} required={!form.id} minLength={8} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Phone"><input className={inputCls} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+92…" /></Field>
              <Field label="CNIC"><input className={inputCls} value={form.cnic || ''} onChange={(e) => setForm({ ...form, cnic: e.target.value })} placeholder="12345-1234567-1" /></Field>
            </div>
            {me?.is_head_office && (
              <Field label="Home Campus">
                <select className={selectCls} value={form.campus_id ?? ''} onChange={(e) => setForm({ ...form, campus_id: e.target.value ? Number(e.target.value) : null })}>
                  <option value="">Head Office / All</option>
                  {campuses.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
            )}
            <div>
              <p className="mb-1 text-sm font-medium text-slate-700">Role assignments</p>
              <div className="scroll-slim max-h-40 space-y-0.5 overflow-y-auto rounded-xl border border-line bg-canvas/60 p-1.5">
                {roles.map((r) => {
                  const assigned = form.roles.find((x) => x.role_id === r.id)
                  return (
                    <label key={r.id} className={`flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm transition hover:bg-brand-50/70 ${assigned ? 'font-medium text-slate-800' : ''}`}>
                      <input type="checkbox" className="accent-brand-600" checked={!!assigned}
                        onChange={(e) => setForm({ ...form, roles: e.target.checked ? [...form.roles, { role_id: r.id, campus_id: form.campus_id ?? null }] : form.roles.filter((x) => x.role_id !== r.id) })} />
                      {r.name}
                      {assigned && campuses.length > 1 && (
                        <div className="ml-auto w-40 shrink-0">
                          <select className={selectCls} value={assigned.campus_id ?? ''}
                            onChange={(e) => setForm({ ...form, roles: form.roles.map((x) => x.role_id === r.id ? { ...x, campus_id: e.target.value ? Number(e.target.value) : null } : x) })}>
                            <option value="">All campuses</option>
                            {campuses.map((c) => <option key={c.id} value={c.id}>{c.code}</option>)}
                          </select>
                        </div>
                      )}
                    </label>
                  )
                })}
              </div>
            </div>
            {form.id && (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="accent-brand-600" checked={form.is_active ?? true} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Account active
              </label>
            )}
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
