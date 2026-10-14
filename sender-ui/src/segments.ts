import type { SegmentCondition } from './api'

/** Понятные названия дополнительных полей подписчика: остальные показываются как есть. */
export const FIELD_LABELS: Record<string, string> = { specialization: 'специальность', city: 'город', company: 'организация', position: 'должность' }

export const fieldLabel = (key: string) => FIELD_LABELS[key] ?? key
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

export const OP_LABELS: Record<SegmentCondition['op'], string> = {
  opened: 'открывали письма',
  not_opened: 'не открывали письма',
  in: 'любой из',
  not_in: 'ни один из',
  any: 'есть любой из',
  none: 'нет ни одного из',
}

/** Название условия: «Город», «Тег», «Активность». */
export const conditionTitle = (c: SegmentCondition) => (c.type === 'activity' ? 'Активность' : c.type === 'tag' ? 'Тег' : cap(fieldLabel(c.field)))

/** Значение условия коротко: «открывали письма за 90 дней», «Москва, Казань», «нет event-231». */
export function conditionValue(c: SegmentCondition): string {
  if (c.type === 'activity') return `${OP_LABELS[c.op]} за ${c.days} дн.`
  if (c.type === 'field') return `${c.op === 'not_in' ? 'не ' : ''}${c.values.join(', ')}`
  return `${c.op === 'none' ? 'нет ' : ''}${c.tags.join(', ')}`
}

export const describeConditions = (conditions: SegmentCondition[]) =>
  conditions.map((c) => `${conditionTitle(c).toLowerCase()}: ${conditionValue(c)}`).join(' · ')

/** Условие в редакторе: значения ещё могут быть не выбраны. key — для списка React. */
export type DraftCondition = SegmentCondition & { key: number }

/** Условие можно отправлять: значения выбраны, число дней задано. */
export const isComplete = (c: SegmentCondition) =>
  c.type === 'activity' ? c.days >= 1 : c.type === 'field' ? c.values.length > 0 : c.tags.length > 0
