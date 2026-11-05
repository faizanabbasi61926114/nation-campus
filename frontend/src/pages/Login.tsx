import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../store/auth'
import { homePathFor } from '../mobile/roles'
import { apiError } from '../lib/api'
import { Btn, Field, inputCls } from '../components/ui'
import { IconEye, IconEyeOff, IconRoles, IconUsers } from '../components/icons'

export default function Login() {
  const login = useAuth((s) => s.login)
  const nav = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [reveal, setReveal] = useState(false)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    try {
      await login(email, password)
      // A family account has no campus workspace, so it opens the phone app.
      nav(homePathFor(useAuth.getState().user))
    } catch (e2) {
      setErr(apiError(e2))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-canvas p-4 sm:p-8">
      <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-line bg-white shadow-pop md:min-h-[560px] md:grid-cols-[1.05fr_0.95fr]">
        {/* Brand panel: what the product is for, while the form loads. */}
        <section className="relative flex min-h-56 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-800 via-brand-900 to-brand-950 p-7 text-white sm:p-10 md:p-12">
          <div aria-hidden="true" className="absolute -right-16 top-16 h-56 w-56 rounded-full border border-white/10" />
          <div aria-hidden="true" className="absolute -right-6 top-28 h-36 w-36 rounded-full border border-white/10" />
          <div aria-hidden="true" className="absolute -left-10 bottom-24 h-40 w-40 rounded-full bg-gold-300/8 blur-2xl" />

          <div className="relative">
            <div className="flex items-center gap-3">
              <span className="grid h-10 w-10 place-items-center rounded-lg bg-gold-300 text-lg font-bold text-brand-900 shadow-[0_0_0_5px_rgba(241,198,109,0.14)]">N</span>
              <span className="text-lg font-semibold tracking-tight">Nation Campus</span>
            </div>
            <div className="mt-12 max-w-sm md:mt-16">
              <p className="text-sm font-medium text-gold-300">A school day, better organised.</p>
              <h1 className="mt-3 text-3xl font-semibold leading-tight sm:text-4xl">More time for what matters.</h1>
              <p className="mt-4 max-w-xs text-sm leading-6 text-white/75">
                Admissions, attendance, examinations, fees, HR and accounts for every campus — in one workspace.
              </p>
            </div>
            <ul className="mt-8 hidden flex-wrap gap-2.5 sm:flex">
              <li className="inline-flex items-center gap-1.5 rounded-full bg-white/8 px-3 py-1.5 text-[11px] font-medium text-white/80 ring-1 ring-inset ring-white/10">
                <IconUsers className="h-3.5 w-3.5" />Multi-campus
              </li>
              <li className="inline-flex items-center gap-1.5 rounded-full bg-white/8 px-3 py-1.5 text-[11px] font-medium text-white/80 ring-1 ring-inset ring-white/10">
                <IconRoles className="h-3.5 w-3.5" />Role-based access
              </li>
            </ul>
          </div>

          <div className="relative mt-10 flex items-end justify-between">
            <p className="text-xs text-white/55">For school staff and campus administrators</p>
            <svg aria-hidden="true" viewBox="0 0 152 105" fill="none" className="hidden h-24 w-36 sm:block">
              <path d="M13 98h126" stroke="#f1c66d" strokeWidth="2" strokeLinecap="round" />
              <path d="M25 98V47h98v51" fill="#f5f1e5" />
              <path d="M19 48 74 14l55 34H19Z" fill="#f1c66d" />
              <path d="M59 98V71a15 15 0 0 1 30 0v27" fill="#397763" />
              <path d="M36 61h13v13H36zm66 0h13v13h-13z" fill="#9bb8a2" />
              <path d="M72 15v-9h16v19" stroke="#f5f1e5" strokeWidth="3" />
              <path d="M42 43v5m64-5v5" stroke="#397763" strokeWidth="2" />
            </svg>
          </div>
        </section>

        <section className="flex items-center justify-center px-6 py-10 sm:px-10 md:px-12 lg:px-14">
          <form onSubmit={submit} className="w-full max-w-sm animate-fade-up">
            <div className="mb-8 flex items-center gap-2.5 md:hidden">
              <span className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gold-300 font-bold text-brand-900">N</span>
              <span className="text-lg font-semibold tracking-tight text-brand-900">Nation Campus</span>
            </div>
            <p className="text-sm font-semibold text-brand-600">Welcome back</p>
            <h2 className="mt-1 text-2xl font-semibold text-slate-900">Sign in</h2>
            <p className="mt-2 text-sm leading-6 text-ink-soft">Use your school account to continue.</p>

            <div className="mt-7 space-y-5">
              <Field label="Email address">
                <input className={inputCls} type="email" autoComplete="username" required autoFocus value={email}
                  onChange={(e) => setEmail(e.target.value)} placeholder="name@school.edu" />
              </Field>

              <Field label="Password">
                <div className="relative">
                  <input className={`${inputCls} pr-11`} type={reveal ? 'text' : 'password'} autoComplete="current-password" required
                    value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Enter your password" />
                  <button type="button" onClick={() => setReveal((r) => !r)} aria-label={reveal ? 'Hide password' : 'Show password'}
                    className="absolute right-1.5 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    {reveal ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
                  </button>
                </div>
              </Field>

              {err && (
                <p role="alert" className="animate-fade-in rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-sm font-medium text-rose-700">
                  {err}
                </p>
              )}

              <Btn type="submit" loading={busy} className="mt-2 w-full py-2.5">
                {busy ? 'Signing in…' : 'Sign in to Nation Campus'}
              </Btn>
            </div>

            <p className="mt-8 border-t border-line pt-4 text-xs leading-5 text-ink-soft">
              Need access? Contact your campus administrator.
            </p>
          </form>
        </section>
      </div>
    </main>
  )
}
