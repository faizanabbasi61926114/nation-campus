import { create } from 'zustand'

export type ToastTone = 'success' | 'error' | 'info'
export interface Toast { id: number; text: string; tone: ToastTone }

export const useToasts = create<{
  toasts: Toast[]
  push: (text: string, tone: ToastTone) => void
  dismiss: (id: number) => void
}>((set) => ({
  toasts: [],
  push: (text, tone) => set((s) => ({ toasts: [...s.toasts.slice(-3), { id: Date.now() + Math.random(), text, tone }] })),
  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),
}))

/** Non-blocking replacement for alert(); auto-dismisses after a few seconds. */
export const toast = {
  success: (text: string) => useToasts.getState().push(text, 'success'),
  error: (text: string) => useToasts.getState().push(text, 'error'),
  info: (text: string) => useToasts.getState().push(text, 'info'),
}
