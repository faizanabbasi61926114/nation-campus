/**
 * The five statuses a register accepts, in the order they are offered on a phone. Both the class
 * roll and the staff register paint their chips from this list, so the two screens read alike.
 */
export const STATUS_CHOICES: Array<{ key: string; label: string; tone: string }> = [
  { key: 'present', label: 'Present', tone: 'data-[on=true]:bg-brand-700 data-[on=true]:text-white data-[on=true]:ring-brand-700' },
  { key: 'late', label: 'Late', tone: 'data-[on=true]:bg-amber-500 data-[on=true]:text-white data-[on=true]:ring-amber-500' },
  { key: 'absent', label: 'Absent', tone: 'data-[on=true]:bg-rose-600 data-[on=true]:text-white data-[on=true]:ring-rose-600' },
  { key: 'leave', label: 'Leave', tone: 'data-[on=true]:bg-sky-600 data-[on=true]:text-white data-[on=true]:ring-sky-600' },
  { key: 'excused', label: 'Excused', tone: 'data-[on=true]:bg-slate-600 data-[on=true]:text-white data-[on=true]:ring-slate-600' },
]
