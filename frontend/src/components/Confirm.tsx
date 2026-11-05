import { create } from 'zustand'
import { Btn, Modal } from './ui'
import { IconAlert } from './icons'

interface Ask {
  title: string
  text?: string
  confirmLabel: string
  cancelLabel: string
  tone: 'danger' | 'primary'
  resolve: (confirmed: boolean) => void
}

const useAsk = create<{ ask: Ask | null }>(() => ({ ask: null }))

/** Awaitable replacement for window.confirm(), styled to match the app. */
export function askConfirm(opts: Partial<Ask> & { title: string; text?: string }): Promise<boolean> {
  return new Promise((resolve) => {
    useAsk.getState().ask?.resolve(false)
    useAsk.setState({
      ask: { confirmLabel: 'Confirm', cancelLabel: 'Cancel', tone: 'danger', ...opts, resolve },
    })
  })
}

export function ConfirmHost() {
  const ask = useAsk((s) => s.ask)
  if (!ask) return null
  const done = (confirmed: boolean) => {
    useAsk.setState({ ask: null })
    ask.resolve(confirmed)
  }
  // Above the z-50 page modals this dialog can be opened from inside.
  return (
    <div className="fixed inset-0 z-[65]">
      <Modal title={ask.title} onClose={() => done(false)} size="sm">
        <div className="flex items-start gap-3 px-5 py-5">
          <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-full ${ask.tone === 'danger' ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-700'}`}>
            <IconAlert className="h-[18px] w-[18px]" />
          </span>
          <p className="pt-1 text-sm leading-relaxed text-slate-700">{ask.text ?? 'This action cannot be undone.'}</p>
        </div>
        <div className="flex justify-end gap-2 border-t border-line bg-slate-50/60 px-5 py-3.5">
          <Btn variant="ghost" onClick={() => done(false)}>{ask.cancelLabel}</Btn>
          <Btn variant={ask.tone === 'danger' ? 'danger' : 'primary'} onClick={() => done(true)}>{ask.confirmLabel}</Btn>
        </div>
      </Modal>
    </div>
  )
}
