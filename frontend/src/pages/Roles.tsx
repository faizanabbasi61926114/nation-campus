import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { IconPlus, IconRoles } from '../components/icons'
import { Badge, Btn, Empty, ErrorNote, Field, Modal, PageHeader, Table, Td, Th, inputCls } from '../components/ui'
import { label, permission, permissionGroup } from '../lib/labels'

interface Role { id: number; name: string; slug: string; is_system: boolean; permissions: { id: string }[] }

const C = 3

const slugOf = (v: string) => v.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')

export default function Roles() {
  const { data: roleData, loading, error, reload } = useApi<{ data: Role[] }>('/roles')
  const { data: permData } = useApi<{ data: { id: string; group: string }[] }>('/permissions')
  const [edit, setEdit] = useState<Role | null>(null)
  const [sel, setSel] = useState<string[]>([])
  const [newRole, setNewRole] = useState(false)
  const [name, setName] = useState('')
  const [err, setErr] = useState('')

  function open(r: Role) { setEdit(r); setSel(r.permissions.map((p) => p.id)); setErr(''); setNewRole(false) }

  async function save() {
    if (!edit) return
    setErr('')
    try {
      if (newRole) await api.post('/roles', { name, slug: slugOf(name), permissions: sel })
      else await api.put(`/roles/${edit.id}`, { name: edit.name, permissions: sel })
      setEdit(null); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  const groups = (permData?.data ?? []).reduce<Record<string, string[]>>((acc, p) => { (acc[p.group] ||= []).push(p.id); return acc }, {})

  return (
    <div>
      <PageHeader title="Roles" subtitle="Choose what each role is allowed to do, then assign it to staff accounts" icon={<IconRoles className="h-5 w-5" />}
        actions={<Btn onClick={() => { setEdit({ id: 0, name: '', slug: '', is_system: false, permissions: [] }); setNewRole(true); setSel([]); setName('') }} icon={<IconPlus className="h-4 w-4" />}>New Role</Btn>} />
      <Table headers={<><Th>Role</Th><Th align="right">Permissions</Th><Th>Type</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {!(roleData?.data ?? []).length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No roles yet." hint="Create a role to bundle permissions for staff accounts." /></Td></tr>
        )}
        {(roleData?.data ?? []).map((r) => (
          <tr key={r.id} className="cursor-pointer" onClick={() => open(r)}>
            <Td className="font-medium">{label(r.name)}</Td>
            <Td className="nums text-right">{r.permissions.length}</Td>
            <Td><Badge tone={r.is_system ? 'gold' : 'blue'} dot>{r.is_system ? 'Built-in' : 'Custom'}</Badge></Td>
          </tr>
        ))}
      </Table>

      {edit && (
        <Modal size="lg" onClose={() => setEdit(null)}
          title={newRole ? 'New Role' : `Edit ${edit.name}`}
          subtitle="Permissions grouped by module"
          footer={<>
            <Btn variant="ghost" onClick={() => setEdit(null)}>Cancel</Btn>
            <Btn onClick={save}>Save Role</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Name" hint={newRole && name ? `Stored internally as ${slugOf(name)}` : undefined}>
              <input className={inputCls} value={newRole ? name : edit.name}
                onChange={(e) => newRole ? setName(e.target.value) : setEdit({ ...edit, name: e.target.value })} />
            </Field>
            <div className="scroll-slim grid max-h-[45vh] gap-3 overflow-y-auto sm:grid-cols-2">
              {Object.entries(groups).map(([g, perms]) => {
                const all = perms.every((p) => sel.includes(p))
                return (
                  <div key={g} className="rounded-xl border border-line bg-canvas/60 p-2.5">
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <p className="text-xs font-semibold text-ink-soft">{permissionGroup(g)}</p>
                      <button type="button" onClick={() => setSel(all ? sel.filter((x) => !perms.includes(x)) : [...new Set([...sel, ...perms])])}
                        className="text-[11px] font-semibold text-brand-700 transition hover:text-brand-900 hover:underline">{all ? 'Clear all' : 'Select all'}</button>
                    </div>
                    {perms.map((p) => (
                      <label key={p} className="flex items-center gap-2 rounded-lg px-1.5 py-1 text-sm transition hover:bg-brand-50/70">
                        <input type="checkbox" className="accent-brand-600" checked={sel.includes(p)} disabled={p === '*'}
                          onChange={(e) => setSel(e.target.checked ? [...sel, p] : sel.filter((x) => x !== p))} />
                        {permission(p)}
                      </label>
                    ))}
                  </div>
                )
              })}
            </div>
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
