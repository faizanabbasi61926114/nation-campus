import { useEffect, useState } from 'react'
import { IconAlert, IconCheck, IconClose, IconInfo } from './icons'
import { useToasts, type Toast, type ToastTone } from '../store/toasts'

const STYLE: Record<ToastTone, { ring: string; icon: typeof IconCheck }> = {
  success: { ring: 'border-brand-200 bg-brand-50 text-brand-800', icon: IconCheck },
  error: { ring: 'border-rose-200 bg-rose-50 text-rose-800', icon: IconAlert },
  info: { ring: 'border-slate-200 bg-white text-slate-700', icon: IconInfo },
}

function Row({ item }: { item: Toast }) {
  const dismiss = useToasts((s) => s.dismiss)
  const [leaving, setLeaving] = useState(false)
  useEffect(() => {
    const hide = setTimeout(() => setLeaving(true), 3800)
    const gone = setTimeout(() => dismiss(item.id), 4200)
    return () => { clearTimeout(hide); clearTimeout(gone) }
  }, [item.id, dismiss])
  const { ring, icon: Icon } = STYLE[item.tone]
  return (
    <div role="status" className={`pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm font-medium shadow-lift backdrop-blur transition-all duration-300 ${ring} ${leaving ? 'translate-y-1 opacity-0' : 'animate-toast-in'}`}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" />
      <span className="min-w-0 flex-1">{item.text}</span>
      <button type="button" onClick={() => dismiss(item.id)} aria-label="Dismiss" className="opacity-50 transition hover:opacity-100">
        <IconClose className="h-3.5 w-3.5" />
      </button>
    </div>
  )
}

export function Toaster() {
  const toasts = useToasts((s) => s.toasts)
  if (!toasts.length) return null
  return (
    <div className="pointer-events-none fixed bottom-5 right-5 z-[70] flex w-[min(24rem,calc(100vw-2.5rem))] flex-col gap-2.5">
      {toasts.map((t) => (
        <Row key={t.id} item={t} />
      ))}
    </div>
  )
}
