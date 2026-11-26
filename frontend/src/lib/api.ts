import axios from 'axios'

// In the browser the path is enough because Vite (and Caddy in production) proxy /api
// to Laravel from the same origin. The APK has no such proxy: its pages come from the
// bundled http://localhost, so it needs VITE_CMS_API_URL set to an absolute origin.
const apiBase = import.meta.env.VITE_CMS_API_URL ?? '/api/v1'

export const api = axios.create({
  baseURL: apiBase,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('cms_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  const campus = localStorage.getItem('cms_campus')
  if (campus) config.headers['X-Campus-Id'] = campus
  return config
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401 && window.location.pathname !== '/login') {
      localStorage.removeItem('cms_token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  },
)

export function apiError(err: unknown): string {
  const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string }
  if (e?.response?.data?.errors) {
    const first = Object.values(e.response.data.errors)[0]
    if (first?.[0]) return first[0]
  }
  return e?.response?.data?.message || e?.message || 'Something went wrong'
}
