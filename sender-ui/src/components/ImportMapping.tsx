import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileUp, Loader2, X } from 'lucide-react'
import { ApiError, upload, type ImportPreview } from '../api'

export type Role = 'email' | 'name' | 'field' | 'skip'
export type ColumnChoice = { role: Role; key: string }
export type Mapping = { email: number; name: number | null; header: boolean; fields: Record<string, string> }

const KEY_RE = /^[a-z][a-z0-9_]{0,49}$/
const n = (v: number) => v.toLocaleString('ru-RU')

// ключ переменной из названия колонки, как на сервере: «Город» → gorod
const RU: Record<string, string> = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p',
  р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'kh', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'shch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
}
export function keyFromHeader(header: string, index: number): string {
  const key = [...header.toLowerCase()].map((ch) => RU[ch] ?? ch).join('').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 50)
  return KEY_RE.test(key) ? key : `field_${index + 1}`
}

/**
 * Черновик загрузки: адреса вручную или файл, просмотр колонок на сервере и выбор, что где.
 * Используется на странице новой базы и при дозагрузке в существующую.
 * defaultConsent — согласие по умолчанию; пока пользователь сам не трогал галочку, она следует за ним.
 */
export function useImportDraft(defaultConsent: boolean) {
  const [text, setText] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [debounced, setDebounced] = useState('')
  useEffect(() => { const t = window.setTimeout(() => setDebounced(text.trim()), 450); return () => window.clearTimeout(t) }, [text])

  const source = file ? `file:${file.name}:${file.size}:${file.lastModified}` : debounced ? `text:${debounced}` : null
  const preview = useQuery({
    queryKey: ['import-preview', source],
    enabled: source !== null,
    staleTime: Infinity,
    retry: false,
    // тело строится из того же, что и ключ: файл или текст после паузы, а не текущий ввод
    queryFn: () => upload<{ data: ImportPreview }>('/lists/import-preview', body()),
  })

  // согласие на рассылку уже есть: без него новым адресам уходит письмо с подтверждением подписки
  const [consent, setConsentState] = useState(defaultConsent)
  const [consentTouched, setConsentTouched] = useState(false)
  useEffect(() => { if (!consentTouched) setConsentState(defaultConsent) }, [defaultConsent, consentTouched])
  const setConsent = (v: boolean) => { setConsentTouched(true); setConsentState(v) }

  const p = preview.data?.data
  const [header, setHeaderState] = useState(false)
  const [choices, setChoices] = useState<ColumnChoice[]>([])
  useEffect(() => {
    if (!p) return
    setHeaderState(p.header)
    setChoices(p.columns.map((c) => ({ role: c.role, key: c.key })))
  }, [preview.data])

  // сервер не нашёл строку заголовка, а пользователь отметил: названия берутся из первой строки
  const namesFromFirstRow = Boolean(p && header && !p.header)
  const autoKey = (i: number, withHeader: boolean) => {
    if (!p) return ''
    return withHeader && !p.header ? keyFromHeader(p.sample_rows[0]?.[p.columns[i].index] ?? '', p.columns[i].index) : p.columns[i].key
  }
  const setHeader = (v: boolean) => {
    // подсказанные названия переменных меняются вместе с заголовком; свои названия пользователя не трогаем
    setChoices((prev) => prev.map((c, i) => (c.role === 'field' && c.key === autoKey(i, header) ? { ...c, key: autoKey(i, v) } : c)))
    setHeaderState(v)
  }

  function body(mapping?: Mapping): FormData {
    const b = new FormData()
    if (file) b.append('file', file)
    else b.append('text', debounced)
    if (mapping) {
      b.append('mapping', JSON.stringify(mapping))
      b.append('consent', consent ? '1' : '0')
    }
    return b
  }

  const columns = p?.columns ?? []
  const emailIndex = choices.findIndex((c) => c.role === 'email')
  const nameIndex = choices.findIndex((c) => c.role === 'name')
  const fieldKeys = choices.filter((c) => c.role === 'field').map((c) => c.key)
  const error = !columns.length ? null
    : emailIndex < 0 ? 'Выберите колонку с адресом почты'
    : fieldKeys.some((k) => !KEY_RE.test(k)) ? 'Название переменной: латиница, цифры и _, начинается с буквы'
    : new Set(fieldKeys).size !== fieldKeys.length ? 'Две колонки с одним названием переменной'
    : null
  const mapping: Mapping | null = columns.length && !error ? {
    email: emailIndex,
    name: nameIndex >= 0 ? nameIndex : null,
    header,
    fields: Object.fromEntries(choices.map((c, i) => [String(i), c]).filter(([, c]) => (c as ColumnChoice).role === 'field').map(([i, c]) => [i, (c as ColumnChoice).key])),
  } : null
  const rows = p ? p.rows + (p.header && !header ? 1 : 0) - (!p.header && header ? 1 : 0) : 0
  // пока ввод не успокоился или колонки читаются, сопоставление может относиться к прежнему тексту
  const settled = file ? true : text.trim() === debounced
  const ready = settled && !preview.isFetching && Boolean(mapping)

  const choose = (index: number, role: Role) => setChoices((prev) => prev.map((c, i) => {
    if (i === index) return { ...c, role }
    // адрес и имя — по одной колонке: прежняя становится переменной
    if ((role === 'email' || role === 'name') && c.role === role) return { ...c, role: 'field' }
    return c
  }))
  const rename = (index: number, key: string) => setChoices((prev) => prev.map((c, i) => (i === index ? { ...c, key } : c)))
  const reset = () => { setText(''); setFile(null); setDebounced(''); setChoices([]) }

  return {
    text, setText: (v: string) => { setText(v); if (v) setFile(null) },
    file, setFile: (f: File | null) => { setFile(f); if (f) { setText(''); setDebounced('') } },
    preview, columns, choices, choose, rename, header, setHeader, namesFromFirstRow, mapping, error, rows, body, reset, consent, setConsent,
    ready, settled,
    hasInput: Boolean(file || text.trim()),
  }
}

export type ImportDraft = ReturnType<typeof useImportDraft>

/** Согласие на рассылку: если его нет, новым адресам уходит письмо со ссылкой «Подтвердить подписку». */
export function ConsentCheckbox({ draft }: { draft: ImportDraft }) {
  return (
    <label className="check-row nl-consent" title="Без отметки каждому новому адресу придёт письмо со ссылкой «Подтвердить подписку». Рассылки начнут приходить после подтверждения.">
      <input type="checkbox" checked={draft.consent} onChange={(e) => draft.setConsent(e.target.checked)} />
      <span>Согласие на рассылку уже есть</span>
    </label>
  )
}

/** Поле для адресов вручную и под ним загрузка файла; between — что показать между ними, off — поле и файл неактивны. */
export function ImportSource({ draft, between, off = false }: { draft: ImportDraft; between?: React.ReactNode; off?: boolean }) {
  const input = useRef<HTMLInputElement>(null)
  const [over, setOver] = useState(false)
  const pick = (f: File | undefined) => { if (f) draft.setFile(f) }

  return (
    <div className="import-source">
      <div className={`field${off ? ' nl-off' : ''}`} inert={off}>
        <label htmlFor="imp-text">Вставьте адреса</label>
        <textarea id="imp-text" className="textarea mono" rows={6} disabled={Boolean(draft.file)}
          placeholder={'anna@example.com;Анна;Москва\npetr@example.com;Пётр;Казань'}
          value={draft.text} onChange={(e) => draft.setText(e.target.value)} />
        <div className="hint">По одному на строку. Через точку с запятой — имя и другие данные, их можно будет разобрать по колонкам ниже.</div>
      </div>

      {between}

      <div className={`import-file${off ? ' nl-off' : ''}`} inert={off}>
      <div className="or-divider"><span>или загрузите файл</span></div>

      {draft.file ? (
        <div className="file-picked">
          <FileUp size={18} />
          <div className="grow"><b>{draft.file.name}</b><span>{Math.max(1, Math.round(draft.file.size / 1024))} КБ</span></div>
          <button type="button" className="btn sm ghost" onClick={() => input.current?.click()}>Другой файл</button>
          <button type="button" className="btn icon sm ghost" onClick={() => draft.setFile(null)} aria-label="Убрать файл" title="Убрать файл"><X size={15} /></button>
        </div>
      ) : (
        <button type="button" className={`dropzone${over ? ' over' : ''}`} onClick={() => input.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)}
          onDrop={(e) => { e.preventDefault(); setOver(false); pick(e.dataTransfer.files[0]) }}>
          <FileUp size={22} />
          <b>Перетащите файл сюда или нажмите</b>
          <span>CSV до 20 МБ. В Excel: Файл → Сохранить как → CSV</span>
        </button>
      )}
      <input ref={input} type="file" accept=".csv,.txt,text/csv,text/plain" hidden onChange={(e) => { pick(e.target.files?.[0]); e.target.value = '' }} />
      </div>
    </div>
  )
}

/**
 * Сопоставление колонок прямо на странице: колонки файла идут горизонтально, как в таблице,
 * над каждой выбирается, что в ней, ниже первые строки файла.
 */
export function ColumnMapping({ draft }: { draft: ImportDraft }) {
  const { preview, columns, choices } = draft
  if (!draft.hasInput) return null
  if (preview.isFetching && !columns.length) return <div className="mapping-loading"><Loader2 size={16} className="spin" />Читаем колонки…</div>
  if (preview.isError) {
    const e = preview.error
    return <div className="hint err">{e instanceof ApiError ? (e.errors.file?.[0] ?? e.errors.text?.[0] ?? e.message) : 'Не удалось прочитать файл'}</div>
  }
  if (!columns.length) return null

  const p = preview.data!.data
  // без строки заголовка названия колонок из файла становятся первой строкой данных,
  // а если заголовок отмечен вручную, первая строка примеров становится названиями
  const rows = !draft.header && p.header ? [columns.map((c) => c.header), ...p.sample_rows].slice(0, 5)
    : draft.namesFromFirstRow ? p.sample_rows.slice(1)
    : p.sample_rows
  const colName = (c: (typeof columns)[number]) => {
    const fromFile = draft.namesFromFirstRow ? p.sample_rows[0]?.[c.index] : draft.header ? c.header : ''
    return fromFile || `Колонка ${c.index + 1}`
  }

  return (
    <section className="mapping" aria-label="Колонки">
      <div className="mapping-head">
        <div>
          <b>Колонки</b>
          <span className="sub">{n(draft.rows)} {draft.rows === 1 ? 'строка' : 'строк'} · над каждой колонкой выберите, что в ней</span>
        </div>
        <label className="check-row mapping-header">
          <input type="checkbox" checked={draft.header} onChange={(e) => draft.setHeader(e.target.checked)} />
          <span>Первая строка — названия колонок</span>
        </label>
      </div>
      <div className="grid-wrap">
        <table className="map-grid">
          <thead>
            <tr>
              <th className="map-num" aria-hidden />
              {columns.map((c, i) => {
                const choice = choices[i] ?? { role: c.role, key: c.key }
                return (
                  <th key={c.index} className={`map-col role-${choice.role}`}>
                    <select className="select sm" aria-label={`Что в колонке ${c.index + 1}`} value={choice.role} onChange={(e) => draft.choose(i, e.target.value as Role)}>
                      <option value="email">Адрес почты</option>
                      <option value="name">Имя</option>
                      <option value="field">Переменная</option>
                      <option value="skip">Не загружать</option>
                    </select>
                    {choice.role === 'field' ? (
                      <div className="var-input"><span>{'{{'}</span>
                        <input className={`input sm mono${KEY_RE.test(choice.key) ? '' : ' err'}`} aria-label={`Переменная для колонки ${c.index + 1}`} value={choice.key}
                          onChange={(e) => draft.rename(i, e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))} />
                        <span>{'}}'}</span></div>
                    ) : <div className="var-note">{choice.role === 'email' ? '{{ email }}' : choice.role === 'name' ? '{{ name }}' : 'колонка пропускается'}</div>}
                  </th>
                )
              })}
            </tr>
            <tr className="map-names">
              <th className="map-num" aria-hidden />
              {columns.map((c, i) => <th key={c.index} className={`map-col role-${(choices[i] ?? c).role}`}>{colName(c)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, r) => (
              <tr key={r}>
                <td className="map-num">{r + 1}</td>
                {columns.map((c, i) => <td key={c.index} className={`map-col role-${(choices[i] ?? c).role}`} title={row[c.index] ?? ''}>{row[c.index] || <span className="muted">—</span>}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {draft.rows > rows.length && <div className="hint">Показаны первые {rows.length} из {n(draft.rows)} строк</div>}
      {draft.error && <div className="hint err">{draft.error}</div>}
    </section>
  )
}
