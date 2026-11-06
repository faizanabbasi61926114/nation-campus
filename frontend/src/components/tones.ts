/** The shared palette: every coloured surface in the kit resolves through these two maps. */
export type Tone = 'slate' | 'green' | 'amber' | 'rose' | 'blue' | 'gold' | 'violet'

export const TONES: Record<Tone, string> = {
  slate: 'bg-slate-100 text-slate-700 ring-slate-200',
  green: 'bg-brand-50 text-brand-700 ring-brand-200',
  amber: 'bg-amber-50 text-amber-800 ring-amber-200',
  rose: 'bg-rose-50 text-rose-700 ring-rose-200',
  blue: 'bg-sky-50 text-sky-800 ring-sky-200',
  gold: 'bg-gold-100 text-gold-500 ring-gold-200',
  violet: 'bg-violet-50 text-violet-800 ring-violet-200',
}

export const DOTS: Record<Tone, string> = {
  slate: 'bg-slate-400', green: 'bg-brand-500', amber: 'bg-amber-500',
  rose: 'bg-rose-500', blue: 'bg-sky-500', gold: 'bg-gold-400', violet: 'bg-violet-500',
}

/** For screens that paint a chip outside the kit's own components. */
export const toneFor = (tone: Tone) => TONES[tone]
