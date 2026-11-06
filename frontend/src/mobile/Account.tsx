import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../store/auth'
import { hasPerm } from '../lib/types'
import { label } from '../lib/labels'
import { Screen } from './MobileShell'
import { Row } from './shellParts'
import { askConfirm } from '../store/confirm'
import { Avatar, Badge, Card } from '../components/ui'
import { IconCampuses, IconLogout } from '../components/icons'
import { isHousehold } from './roles'

interface InstallPrompt extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: string }>
}

export default function Account() {
  const { user, logout } = useAuth()
  const nav = useNavigate()
  const [install, setInstall] = useState<InstallPrompt | null>(null)
  const [online, setOnline] = useState(navigator.onLine)

  // Chrome/Edge fire this once and only once; keeping the event lets the button install later.
  useEffect(() => {
    const capture = (e: Event) => { e.preventDefault(); setInstall(e as InstallPrompt) }
    const gone = () => setInstall(null)
    window.addEventListener('beforeinstallprompt', capture)
    window.addEventListener('appinstalled', gone)
    return () => {
      window.removeEventListener('beforeinstallprompt', capture)
      window.removeEventListener('appinstalled', gone)
    }
  }, [])

  useEffect(() => {
    const up = () => setOnline(true)
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down) }
  }, [])

  async function signOut() {
    if (!(await askConfirm({ title: 'Sign out of the app?', text: 'You will need your school password to come back in.' }))) return
    await logout()
    nav('/login', { replace: true })
  }

  async function installApp() {
    if (!install) return
    await install.prompt()
    await install.userChoice
    setInstall(null)
  }

  const campus = user?.campuses?.[0]

  return (
    <Screen heading="Account">
      <Card className="p-4">
        <div className="flex items-center gap-3">
          <Avatar name={user?.name ?? '?'} className="h-12 w-12 text-[15px]" />
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold text-slate-900">{user?.name}</p>
            <p className="truncate text-[11.5px] text-ink-soft">{user?.email}</p>
          </div>
        </div>
        <div className="mt-3 flex flex-wrap gap-1.5">
          {(user?.roles ?? []).map((r) => <Badge key={r} tone={r === 'guardian' ? 'gold' : 'green'}>{label(r)}</Badge>)}
          {!online && <Badge tone="rose">offline</Badge>}
        </div>
        <div className="mt-3 divide-y divide-line/70">
          <Row label="School" value={campus ? campus.name : (user?.is_head_office ? 'Head office' : '—')} icon={IconCampuses} />
          {campus?.city && <Row label="City" value={campus.city} />}
          <Row label="Permissions" value={`${hasPerm(user, '*') ? 'all' : (user?.permissions ?? []).length}`} />
        </div>
      </Card>

      <Card className="divide-y divide-line/70">
        {!isHousehold(user) && (
          <Link to="/" className="flex items-center justify-between px-4 py-3.5 text-[13.5px] font-semibold text-slate-800 transition active:bg-brand-50/60">
            Open the desktop workspace<span className="text-[11px] font-medium text-brand-700">full records</span>
          </Link>
        )}
        {install && (
          <button type="button" onClick={() => void installApp()} className="flex w-full items-center justify-between px-4 py-3.5 text-left text-[13.5px] font-semibold text-slate-800 transition active:bg-brand-50/60">
            Install on this phone<span className="text-[11px] font-medium text-brand-700">add to home screen</span>
          </button>
        )}
        {!install && (
          <div className="px-4 py-3.5 text-[11.5px] leading-5 text-ink-soft">
            To put this app on your home screen, open it in your browser and choose <span className="font-semibold text-slate-700">Add to home screen</span>.
            Signing in once keeps you signed in on this phone.
          </div>
        )}
        <button type="button" onClick={() => void signOut()}
          className="flex w-full items-center gap-2.5 px-4 py-3.5 text-left text-[13.5px] font-semibold text-rose-600 transition active:bg-rose-50">
          <IconLogout className="h-4 w-4" />Sign out
        </button>
      </Card>

      <p className="pt-1 text-center text-[11px] text-slate-400">Nation Campus · Pakistan time (Asia/Karachi)</p>
    </Screen>
  )
}
