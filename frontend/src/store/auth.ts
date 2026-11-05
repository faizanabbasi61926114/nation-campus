import { create } from 'zustand'
import { api } from '../lib/api'
import type { User } from '../lib/types'

/** Remembered so a multi-campus user is asked to pick a school once per sign-in, not on every reload. */
const SCHOOL_CHOICE = 'cms_school_choice'

interface AuthState {
  user: User | null
  loading: boolean
  /** Selected campus scope; pages use it as a fetch dependency so a switch reloads their data. */
  campusId: number | null
  login(email: string, password: string): Promise<void>
  logout(): Promise<void>
  fetchMe(): Promise<void>
  switchCampus(id: number | null): Promise<void>
}

const readCampusScope = (): number | null => Number(localStorage.getItem('cms_campus')) || null

export function needsSchoolChoice(user: User | null): boolean {
  return !!user && (user.campuses?.length ?? 0) > 1 && !localStorage.getItem(SCHOOL_CHOICE)
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  loading: true,
  campusId: readCampusScope(),
  async login(email, password) {
    const { data } = await api.post('/auth/login', { email, password })
    localStorage.setItem('cms_token', data.token)
    localStorage.setItem('cms_campus', data.user.is_head_office ? '' : String(data.user.campus_id ?? ''))
    localStorage.removeItem(SCHOOL_CHOICE)
    set({ user: data.user, loading: false, campusId: readCampusScope() })
  },
  async logout() {
    try { await api.post('/auth/logout') } catch { /* token already gone */ }
    localStorage.removeItem('cms_token')
    localStorage.removeItem(SCHOOL_CHOICE)
    set({ user: null, campusId: null })
  },
  async fetchMe() {
    if (!localStorage.getItem('cms_token')) return set({ user: null, loading: false })
    try {
      const { data } = await api.get('/auth/me')
      set({ user: data, loading: false })
    } catch {
      localStorage.removeItem('cms_token')
      set({ user: null, loading: false })
    }
  },
  async switchCampus(id) {
    localStorage.setItem('cms_campus', id ? String(id) : '')
    localStorage.setItem(SCHOOL_CHOICE, '1')
    set({ campusId: id })
    const { data } = await api.get('/auth/me')
    set({ user: data })
  },
}))
