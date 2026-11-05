import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import { useAuth } from '../store/auth'

export function useApi<T>(path: string | null, deps: unknown[] = []) {
  // Campus scope changes what every endpoint returns, so a switch must refetch rather
  // than leave a page showing another campus's figures.
  const campusId = useAuth((s) => s.campusId)
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(!!path)

  const reload = useCallback(() => {
    if (!path) return
    setLoading(true)
    api.get(path)
      .then((r) => setData(r.data))
      .catch((e) => setError(e.response?.data?.message || e.message))
      .finally(() => setLoading(false))
  }, [path, campusId, ...deps]) // eslint-disable-line

  useEffect(() => { reload() }, [reload])
  return { data, error, loading, reload }
}
