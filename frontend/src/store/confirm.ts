import { create } from 'zustand'

export interface Ask {
  title: string
  text?: string
  confirmLabel: string
  cancelLabel: string
  tone: 'danger' | 'primary'
  resolve: (confirmed: boolean) => void
}

export const useAsk = create<{ ask: Ask | null }>(() => ({ ask: null }))

/** Awaitable replacement for window.confirm(), styled to match the app. */
export function askConfirm(opts: Partial<Ask> & { title: string; text?: string }): Promise<boolean> {
  return new Promise((resolve) => {
    useAsk.getState().ask?.resolve(false)
    useAsk.setState({
      ask: { confirmLabel: 'Confirm', cancelLabel: 'Cancel', tone: 'danger', ...opts, resolve },
    })
  })
}
