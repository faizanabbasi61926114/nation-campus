import { Btn, Modal } from './ui'
import { IconAlert } from './icons'
import { useAsk } from '../store/confirm'

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
