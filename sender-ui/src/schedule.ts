// Расписание плавной рассылки: та же логика, что в CampaignSchedule на сервере. Окно задаётся по Москве (UTC+3 круглый год).
const MSK = 3 * 3600_000
const MINUTE = 60_000

export type Schedule = {
  batch: number
  interval: number
  daily: number | null
  start: string | null
  end: string | null
  days: number[] | null
}

const msk = (t: number) => new Date(t + MSK)
const isoDay = (d: Date) => d.getUTCDay() || 7
const hhmm = (d: Date) => `${String(d.getUTCHours()).padStart(2, '0')}:${String(d.getUTCMinutes()).padStart(2, '0')}`
const dayAllowed = (s: Schedule, d: Date) => !s.days?.length || s.days.includes(isoDay(d))

export function inWindow(s: Schedule, t: number): boolean {
  const d = msk(t)
  const time = hhmm(d)
  return dayAllowed(s, d) && time >= (s.start ?? '00:00') && (!s.end || time < s.end)
}

export function nextOpening(s: Schedule, t: number): number {
  if (inWindow(s, t)) return t
  const d = msk(t)
  const [h, m] = (s.start ?? '00:00').split(':').map(Number)
  for (let k = 0; k <= 7; k++) {
    const candidate = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + k, h, m) - MSK
    if (candidate > t && dayAllowed(s, msk(candidate))) return candidate
  }
  return t
}

/** Когда уйдёт последняя пачка, если начать в from. */
export function estimateFinish(s: Schedule, total: number, from: number): number | null {
  if (total <= 0 || s.batch < 1) return null
  let t = from
  let left = total
  let day = ''
  let sentOnDay = 0
  for (let step = 0; step < 20000; step++) {
    t = nextOpening(s, t)
    const key = msk(t).toISOString().slice(0, 10)
    if (key !== day) { day = key; sentOnDay = 0 }
    const n = s.daily ? Math.min(s.batch, s.daily - sentOnDay) : s.batch
    if (n <= 0) {
      const d = msk(t)
      t = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + 1) - MSK
      continue
    }
    left -= n
    sentOnDay += n
    if (left <= 0) return t
    t += Math.max(1, s.interval) * MINUTE
  }
  return null
}

export function perHour(s: Schedule): number {
  return Math.round((s.batch * 60) / Math.max(1, s.interval))
}

export function fmtWhen(t: number): string {
  return new Date(t).toLocaleString('ru-RU', { weekday: 'short', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
}

export const WEEKDAYS: [number, string][] = [[1, 'пн'], [2, 'вт'], [3, 'ср'], [4, 'чт'], [5, 'пт'], [6, 'сб'], [7, 'вс']]
