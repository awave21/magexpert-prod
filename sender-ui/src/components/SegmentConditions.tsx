import { useCallback, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Check, ChevronDown, Mail, Plus, Search, Tag, TextCursorInput, X } from 'lucide-react'
import type { ListField, ListTag, SegmentCondition } from '../api'
import { OP_LABELS, conditionTitle, fieldLabel, type DraftCondition } from '../segments'
import { useDismiss } from './useDismiss'

const n = (v: number) => v.toLocaleString('ru-RU')

const OPS: Record<SegmentCondition['type'], SegmentCondition['op'][]> = {
  activity: ['opened', 'not_opened'],
  field: ['in', 'not_in'],
  tag: ['any', 'none'],
}

const conditionKey = (c: SegmentCondition) => (c.type === 'field' ? `field:${c.field}` : c.type)

/**
 * Конструктор условий сегмента: условия добавляются по одному кнопкой «Условие», каждое один раз,
 * и складываются через «И». Значения полей и теги берутся из базы, с поиском.
 */
export function SegmentConditions({ conditions, onChange, fields, tags, errors, disabled }: {
  conditions: DraftCondition[]
  onChange: (next: DraftCondition[]) => void
  fields: ListField[]
  tags: ListTag[]
  errors: Record<string, string>
  disabled?: boolean
}) {
  const used = new Set(conditions.map(conditionKey))
  const add = (c: SegmentCondition) => onChange([...conditions, { ...c, key: Math.max(0, ...conditions.map((x) => x.key)) + 1 } as DraftCondition])
  const update = (i: number, c: SegmentCondition) => onChange(conditions.map((x, j) => (j === i ? ({ ...c, key: x.key } as DraftCondition) : x)))
  const remove = (i: number) => onChange(conditions.filter((_, j) => j !== i))

  return (
    <div className="seg-cond">
      {conditions.length === 0 && <div className="muted">Пока условий нет: добавьте первое.</div>}
      {conditions.map((c, i) => (
        <div key={c.key}>
          {i > 0 && <div className="seg-and"><span>И</span></div>}
          <div className="seg-r">
            <div className="seg-k">{conditionTitle(c)}</div>
            <select className="select seg-o" aria-label={`Условие «${conditionTitle(c)}»`} value={c.op} disabled={disabled}
              onChange={(e) => update(i, { ...c, op: e.target.value } as SegmentCondition)}>
              {OPS[c.type].map((op) => <option key={op} value={op}>{OP_LABELS[op]}</option>)}
            </select>
            <div className="seg-v">
              {c.type === 'activity' && (
                <div className="seg-days">
                  <span>за последние</span>
                  <input className="input num" type="number" min={1} max={3650} inputMode="numeric" aria-label="Дней" value={c.days || ''} disabled={disabled}
                    onChange={(e) => update(i, { ...c, days: Number(e.target.value) || 0 })} />
                  <span>дней</span>
                  {c.op === 'opened' && <span className="seg-note">новые подписчики сюда не попадут: о них ещё нечего знать</span>}
                </div>
              )}
              {c.type === 'field' && (
                <MultiSelect label={fieldLabel(c.field)} placeholder={`Найти: ${fieldLabel(c.field)}`} disabled={disabled} allowCustom
                  options={(fields.find((f) => f.key === c.field)?.values ?? []).map((v) => ({ value: v.value, count: v.count }))}
                  selected={c.values} onChange={(values) => update(i, { ...c, values })} />
              )}
              {c.type === 'tag' && (
                <MultiSelect label="Теги" placeholder="Найти тег" disabled={disabled}
                  options={tags.map((t) => ({ value: t.tag, count: t.count }))}
                  selected={c.tags} onChange={(next) => update(i, { ...c, tags: next })} />
              )}
              {(errors[`conditions.${i}.values`] ?? errors[`conditions.${i}.tags`] ?? errors[`conditions.${i}.days`] ?? errors[`conditions.${i}.type`]) && (
                <div className="hint err">{errors[`conditions.${i}.values`] ?? errors[`conditions.${i}.tags`] ?? errors[`conditions.${i}.days`] ?? errors[`conditions.${i}.type`]}</div>
              )}
            </div>
            {!disabled && <button type="button" className="btn icon ghost" onClick={() => remove(i)} aria-label={`Убрать условие «${conditionTitle(c)}»`} title="Убрать условие"><X size={16} /></button>}
          </div>
        </div>
      ))}
      {!disabled && <AddCondition fields={fields} tagsCount={tags.length} used={used} onAdd={add} />}
    </div>
  )
}

function AddCondition({ fields, tagsCount, used, onAdd }: { fields: ListField[]; tagsCount: number; used: Set<string>; onAdd: (c: SegmentCondition) => void }) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const ref = useRef<HTMLDivElement>(null)
  const close = useCallback(() => { setOpen(false); setQuery('') }, [])
  useDismiss(open, ref, close)

  const q = query.trim().toLowerCase()
  const match = (s: string) => !q || s.toLowerCase().includes(q)
  const pick = (c: SegmentCondition) => { onAdd(c); close() }
  const option = (key: string, icon: React.ReactNode, title: string, hint: string, c: SegmentCondition) => {
    const taken = used.has(key)
    return (
      <button key={key} type="button" role="menuitem" className="seg-opt" disabled={taken} onClick={() => pick(c)}>
        <span className="seg-opt-ic">{icon}</span>
        <span className="grow">{title}</span>
        <span className="sub">{taken ? 'уже добавлено' : hint}</span>
      </button>
    )
  }
  const common = [
    match('активность') && option('activity', <Mail size={14} />, 'Активность', 'открывали письма', { type: 'activity', op: 'opened', days: 90 }),
    match('тег') && option('tag', <Tag size={14} />, 'Тег', tagsCount ? `${n(tagsCount)} в базе` : 'в базе нет тегов', { type: 'tag', op: 'any', tags: [] }),
  ].filter(Boolean)
  const shownFields = fields.filter((f) => match(fieldLabel(f.key)) || match(f.key))

  return (
    <div className="seg-add" ref={ref}>
      <button type="button" className="btn sm" onClick={() => setOpen(!open)} aria-haspopup="menu" aria-expanded={open}><Plus size={15} />Условие</button>
      {open && (
        <div className="seg-pop up" role="menu">
          <label className="seg-find"><Search size={15} /><input autoFocus placeholder="Найти поле или тег" aria-label="Найти поле или тег" value={query} onChange={(e) => setQuery(e.target.value)} /></label>
          {common.length > 0 && <div className="seg-grp">Общие</div>}
          {common}
          {shownFields.length > 0 && <div className="seg-grp">Поля базы</div>}
          {shownFields.map((f) => option(`field:${f.key}`, <TextCursorInput size={14} />, fieldLabel(f.key), `${n(f.values.length)}${f.values.length >= 30 ? '+' : ''} значений`, { type: 'field', op: 'in', field: f.key, values: [] }))}
          {common.length === 0 && shownFields.length === 0 && <div className="seg-more">Ничего не нашлось</div>}
        </div>
      )}
    </div>
  )
}

/** Выбор нескольких значений с поиском: выбранные — плашками, остальные — в списке под полем. */
function MultiSelect({ label, placeholder, options, selected, onChange, allowCustom, disabled }: {
  label: string
  placeholder: string
  options: { value: string; count: number }[]
  selected: string[]
  onChange: (next: string[]) => void
  allowCustom?: boolean
  disabled?: boolean
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const ref = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const close = useCallback(() => { setOpen(false); setQuery('') }, [])
  useDismiss(open, ref, close)

  const q = query.trim().toLowerCase()
  const shown = useMemo(() => options.filter((o) => !q || o.value.toLowerCase().includes(q)), [options, q])
  const toggle = (value: string) => onChange(selected.includes(value) ? selected.filter((v) => v !== value) : [...selected, value])
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      const exact = shown.find((o) => o.value.toLowerCase() === q) ?? (shown.length === 1 ? shown[0] : null)
      const value = exact?.value ?? (allowCustom ? query.trim() : '')
      if (value && !selected.includes(value)) onChange([...selected, value])
      setQuery('')
    } else if (e.key === 'Backspace' && !query && selected.length) {
      onChange(selected.slice(0, -1))
    }
  }

  return (
    <div className="seg-pw" ref={ref}>
      <div className={`seg-ms${open ? ' open' : ''}`} onClick={() => { if (!disabled) { setOpen(true); input.current?.focus() } }}>
        <div className="chips">
          {selected.map((v) => (
            <span key={v} className="seg-tag">{v}
              {!disabled && <button type="button" aria-label={`Убрать ${v}`} onClick={(e) => { e.stopPropagation(); toggle(v) }}><X size={13} /></button>}
            </span>
          ))}
          {!disabled && <input ref={input} aria-label={label} placeholder={selected.length ? '' : placeholder} value={query}
            onChange={(e) => { setQuery(e.target.value); setOpen(true) }} onFocus={() => setOpen(true)} onKeyDown={onKey} />}
        </div>
        <ChevronDown size={14} className="seg-chev" />
      </div>
      {open && !disabled && (
        <div className="seg-pop" role="listbox" aria-multiselectable="true" aria-label={label}>
          {shown.slice(0, 50).map((o) => {
            const on = selected.includes(o.value)
            return (
              <button key={o.value} type="button" role="option" aria-selected={on} className={`seg-opt${on ? ' on' : ''}`} onClick={() => toggle(o.value)}>
                <span className="seg-bx">{on && <Check size={11} strokeWidth={3.2} />}</span>
                <span className="grow">{o.value}</span>
                <span className="sub">{n(o.count)}</span>
              </button>
            )
          })}
          <div className="seg-more">
            {shown.length === 0
              ? (allowCustom && q ? `Нет среди частых значений: нажмите Enter, чтобы добавить «${query.trim()}»` : 'Ничего не нашлось')
              : `Показаны ${n(Math.min(shown.length, 50))} из ${n(shown.length)}${allowCustom ? ' частых значений · другое значение введите и нажмите Enter' : ''}`}
          </div>
        </div>
      )}
    </div>
  )
}
