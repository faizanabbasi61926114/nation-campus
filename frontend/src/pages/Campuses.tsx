import { useState } from 'react'
import { api, apiError } from '../lib/api'
import { useApi } from '../lib/hooks'
import { IconCampuses, IconPlus } from '../components/icons'
import { Badge, Btn, Empty, ErrorNote, Field, Modal, PageHeader, Table, Td, Th, inputCls, selectCls } from '../components/ui'
import { SCHOOL_TYPES, schoolTypeName } from '../lib/schoolTypes'

interface Campus {
  id: number; name: string; code: string; school_type?: string; city?: string; address?: string; email?: string; phone?: string;
  is_head_office: boolean; is_active: boolean
}

interface CampusForm extends Partial<Campus> {
  initial_user?: { name: string; email: string; password: string }
}

const C = 5

export default function Campuses() {
  const { data, loading, error, reload } = useApi<{ data: Campus[] }>('/campuses')
  const rows = data?.data ?? []
  const [form, setForm] = useState<CampusForm | null>(null)
  const [err, setErr] = useState('')

  async function save() {
    if (!form) return
    setErr('')
    try {
      if (form.id) await api.put(`/campuses/${form.id}`, form)
      else await api.post('/campuses', form)
      setForm(null); reload()
    } catch (e) { setErr(apiError(e)) }
  }

  return (
    <div>
      <PageHeader title="Campuses" subtitle="All campuses in your organization" icon={<IconCampuses className="h-5 w-5" />}
        actions={<Btn onClick={() => setForm({
          name: '', code: '', city: '', address: '', school_type: 'secondary',
          initial_user: { name: '', email: '', password: '' },
        })} icon={<IconPlus className="h-4 w-4" />}>Add Campus</Btn>} />
      <Table headers={<><Th>Name</Th><Th>Code</Th><Th>School type</Th><Th>City</Th><Th>Status</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {!rows.length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No campuses yet." hint="Add a campus to start configuring its academics, fees and staff." /></Td></tr>
        )}
        {rows.map((c) => (
          <tr key={c.id} className="cursor-pointer" onClick={() => setForm(c)}>
            <Td>
              <span className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{c.name}</span>
                {c.is_head_office && <Badge tone="gold">Head Office</Badge>}
              </span>
            </Td>
            <Td className="nums">{c.code}</Td>
            <Td>{schoolTypeName(c.school_type)}</Td>
            <Td>{c.city || '—'}</Td>
            <Td><Badge tone={c.is_active ? 'green' : 'rose'} dot>{c.is_active ? 'Active' : 'Inactive'}</Badge></Td>
          </tr>
        ))}
      </Table>

      {form && (
        <Modal size="md" onClose={() => setForm(null)}
          title={form.id ? `Edit ${form.name}` : 'New Campus'}
          subtitle={form.id ? 'Update this campus and its status' : 'Create a campus and its principal login'}
          footer={<>
            <Btn variant="ghost" onClick={() => setForm(null)}>Cancel</Btn>
            <Btn onClick={save}>Save</Btn>
          </>}>
          <div className="space-y-3">
            <Field label="Name"><input className={inputCls} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Code"><input className={inputCls} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} /></Field>
            <Field label="School type" hint="Decides which modules this campus runs — for example, boarding is only offered from Secondary upwards.">
              <select className={selectCls} value={form.school_type || 'secondary'} onChange={(e) => setForm({ ...form, school_type: e.target.value })}>
                {SCHOOL_TYPES.map((t) => <option key={t} value={t}>{schoolTypeName(t)}</option>)}
              </select>
            </Field>
            <Field label="City"><input className={inputCls} value={form.city || ''} onChange={(e) => setForm({ ...form, city: e.target.value })} /></Field>
            <Field label="Address"><textarea className={`${inputCls} min-h-20`} value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} /></Field>
            <Field label="Email"><input className={inputCls} value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label="Phone"><input className={inputCls} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            {!form.id && form.initial_user && (
              <section className="space-y-3 rounded-xl border border-line bg-canvas p-4">
                <div>
                  <h4 className="text-sm font-semibold text-slate-800">Campus Principal Login</h4>
                  <p className="mt-1 text-xs leading-5 text-ink-soft">Create the account the principal will use to sign in to this campus. Share the temporary password securely.</p>
                </div>
                <Field label="Principal name">
                  <input className={inputCls} required value={form.initial_user.name} onChange={(e) => setForm({ ...form, initial_user: { ...form.initial_user!, name: e.target.value } })} autoComplete="name" />
                </Field>
                <Field label="Login email">
                  <input className={inputCls} type="email" required value={form.initial_user.email} onChange={(e) => setForm({ ...form, initial_user: { ...form.initial_user!, email: e.target.value } })} autoComplete="username" placeholder="principal@nationcampus.edu.pk" />
                </Field>
                <Field label="Temporary password">
                  <input className={inputCls} type="password" required minLength={8} value={form.initial_user.password} onChange={(e) => setForm({ ...form, initial_user: { ...form.initial_user!, password: e.target.value } })} autoComplete="new-password" />
                </Field>
              </section>
            )}
            {form.id !== undefined && (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="accent-brand-600" checked={!form.is_active} onChange={(e) => setForm({ ...form, is_active: !e.target.checked })} /> Inactive
              </label>
            )}
            {err && <ErrorNote text={err} />}
          </div>
        </Modal>
      )}
    </div>
  )
}
