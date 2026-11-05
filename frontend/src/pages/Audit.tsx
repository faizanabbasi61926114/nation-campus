import { useState } from 'react'
import { useApi } from '../lib/hooks'
import { IconAudit } from '../components/icons'
import { Empty, PageHeader, Pagination, SearchInput, Status, Table, Td, Th, Toolbar, inputCls } from '../components/ui'
import { label } from '../lib/labels'
import { fmtDateTime } from '../lib/types'

interface LogRow { id: number; action: string; description: string; auditable_type: string | null; user: { name: string; email: string } | null; ip_address: string | null; created_at: string; new_values?: Record<string, unknown> | null }

const C = 6
const PER_PAGE = 50

export default function Audit() {
  const [q, setQ] = useState({ action: '', from: '', to: '' })
  const [page, setPage] = useState(1)
  const qs = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page), ...(q.action ? { action: q.action } : {}), ...(q.from ? { from: q.from } : {}), ...(q.to ? { to: q.to } : {}) })
  const { data, loading, error, reload } = useApi<{ data: LogRow[]; current_page: number; last_page: number; total: number }>(`/audit-logs?${qs}`, [page, q])

  return (
    <div>
      <PageHeader title="Audit Log" subtitle="Immutable record of every sign-in and data change" icon={<IconAudit className="h-5 w-5" />} />
      <Toolbar>
        <SearchInput className="w-56" value={q.action} placeholder="action (login, updated…)"
          onChange={(v) => { setQ({ ...q, action: v }); setPage(1) }} />
        <div className="w-44"><input className={inputCls} type="date" value={q.from} onChange={(e) => { setQ({ ...q, from: e.target.value }); setPage(1) }} /></div>
        <div className="w-44"><input className={inputCls} type="date" value={q.to} onChange={(e) => { setQ({ ...q, to: e.target.value }); setPage(1) }} /></div>
      </Toolbar>
      <Table headers={<><Th>When</Th><Th>User</Th><Th>Action</Th><Th>Subject</Th><Th>Description</Th><Th>IP</Th></>}
        cols={C} loading={loading} error={error} onRetry={reload}>
        {!(data?.data ?? []).length && !loading && !error && (
          <tr><Td colSpan={C}><Empty text="No matching audit entries." hint="Widen the action or date range to see more of the trail." /></Td></tr>
        )}
        {(data?.data ?? []).map((l) => (
          <tr key={l.id}>
            <Td className="nums whitespace-nowrap text-xs">{fmtDateTime(l.created_at)}</Td>
            <Td>{l.user?.name ?? 'System'}</Td>
            <Td><Status value={l.action} /></Td>
            <Td>{label(l.auditable_type?.split('\\').pop())}</Td>
            <Td className="text-xs">{l.description}</Td>
            <Td className="nums text-xs">{l.ip_address ?? '—'}</Td>
          </tr>
        ))}
      </Table>
      {data && data.last_page > 1 && (
        <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} perPage={PER_PAGE} onPage={setPage} busy={loading} />
      )}
    </div>
  )
}
