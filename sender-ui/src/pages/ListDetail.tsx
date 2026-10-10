import { useRef, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileUp, Loader2, Pencil, Plus, RefreshCw, Search, Send, Trash2, Upload, Wand2, X } from 'lucide-react'
import { api, ApiError, upload, type CheckStatus, type Contact, type ContactList, type ImportResult, type Paged } from '../api'
import { BackLink, Empty, Modal, PageHead, fmtDate, useToast } from '../components/ui'

const n = (v: number) => v.toLocaleString('ru-RU')
type StatusFilter = '' | 'subscribed' | 'unsubscribed'
type CheckFilter = '' | CheckStatus | 'unchecked'

// результат проверки адреса: что значит и уйдёт ли на него рассылка
const CHECKS: Record<CheckStatus | 'unchecked', { label: string; tone: 'ok' | 'warn' | 'err' | 'neutral'; text: string }> = {
  ok: { label: 'В порядке', tone: 'ok', text: 'Формат верный, домен принимает почту' },
  role: { label: 'Общий адрес', tone: 'warn', text: 'info@, support@ и подобные: читает не один человек, жалуются чаще. Письмо отправится' },
  typo: { label: 'Опечатка', tone: 'err', text: 'Домен похож на популярный, но с ошибкой. Не отправляем, пока не исправите' },
  disposable: { label: 'Одноразовый', tone: 'err', text: 'Временный ящик, который живёт минуты. Не отправляем' },
  no_mx: { label: 'Домен без почты', tone: 'err', text: 'У домена нет почтового сервера: письмо не дойдёт. Не отправляем' },
  invalid: { label: 'Ошибка в адресе', tone: 'err', text: 'Адрес записан с ошибкой. Не отправляем' },
  unchecked: { label: 'Проверяется', tone: 'neutral', text: 'Проверка идёт в фоне. Если DNS домена не ответил, повторим позже' },
}

export default function ListDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()

  // пока адреса проверяются в фоне, сводка и таблица обновляются сами
  const lq = useQuery({
    queryKey: ['lists', id],
    queryFn: () => api<{ data: ContactList }>(`/lists/${id}`),
    refetchInterval: (query) => ((query.state.data?.data.checks.unchecked ?? 0) > 0 ? 2500 : false),
  })
  const list = lq.data?.data
  const checking = (list?.checks.unchecked ?? 0) > 0

  const [search, setSearch] = useState('')
  const [qs, setQs] = useState('')
  const [status, setStatus] = useState<StatusFilter>('')
  const [check, setCheck] = useState<CheckFilter>('')
  const [page, setPage] = useState(1)
  const cq = useQuery({
    queryKey: ['contacts', id, qs, status, check, page],
    queryFn: () => api<Paged<Contact>>(`/lists/${id}/contacts?${new URLSearchParams({ ...(qs && { q: qs }), ...(status && { status }), ...(check && { check }), page: String(page) })}`),
    placeholderData: keepPreviousData,
    refetchInterval: checking ? 2500 : false,
  })
  const contacts = cq.data?.data ?? []
  const columns = [...new Set(contacts.flatMap((c) => Object.keys(c.data ?? {})))].slice(0, 3)

  const refresh = () => { qc.invalidateQueries({ queryKey: ['lists'] }); qc.invalidateQueries({ queryKey: ['contacts', id] }) }

  // загрузка адресов
  const importOpen = params.get('import') === '1'
  const setImportOpen = (v: boolean) => setParams(v ? { import: '1' } : {})

  // добавить одного
  const [adding, setAdding] = useState(false)
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [addErr, setAddErr] = useState<string | null>(null)
  const add = useMutation({
    mutationFn: () => api(`/lists/${id}/contacts`, { method: 'POST', body: { email, name: name || null } }),
    onSuccess: () => { setAdding(false); setEmail(''); setName(''); toast('Подписчик добавлен'); refresh() },
    onError: (e) => setAddErr(e instanceof ApiError ? (e.errors.email?.[0] ?? e.message) : 'Ошибка'),
  })
  const remove = useMutation({
    mutationFn: (c: Contact) => api(`/lists/${id}/contacts/${c.id}`, { method: 'DELETE' }),
    onSuccess: () => { toast('Подписчик удалён'); refresh() },
  })
  const fix = useMutation({
    mutationFn: (c: Contact) => api(`/lists/${id}/contacts/${c.id}/fix`, { method: 'POST' }),
    onSuccess: (_r, c) => { toast(`Исправлено: ${c.check_hint}`); refresh() },
    onError: (e) => toast(e instanceof Error ? e.message : 'Ошибка', true),
  })
  const fixAll = useMutation({
    mutationFn: () => api<{ data: { fixed: number } }>(`/lists/${id}/fix-typos`, { method: 'POST' }),
    onSuccess: (r) => { toast(`Исправлено адресов: ${n(r.data.fixed)}`); refresh() },
  })
  const recheck = useMutation({
    mutationFn: () => api(`/lists/${id}/check`, { method: 'POST' }),
    onSuccess: () => { toast('Проверяем адреса заново'); refresh() },
  })

  // база: переименовать, удалить
  const [editing, setEditing] = useState<{ name: string; description: string } | null>(null)
  const [editErr, setEditErr] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => api(`/lists/${id}`, { method: 'PUT', body: { name: editing?.name, description: editing?.description || null } }),
    onSuccess: () => { setEditing(null); toast('База сохранена'); refresh() },
    onError: (e) => setEditErr(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const [removing, setRemoving] = useState(false)
  const destroy = useMutation({
    mutationFn: () => api(`/lists/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['lists'] }); toast('База удалена'); nav('/subscribers') },
  })

  if (lq.isError) return <main className="page"><BackLink to="/subscribers">Все базы</BackLink><div className="callout err"><div><b>База не найдена</b></div></div></main>

  const unsubscribed = list ? list.contacts_count - list.subscribed_count : 0
  const filters: [StatusFilter, string, number | undefined][] = [['', 'Все', list?.contacts_count], ['subscribed', 'Подписаны', list?.subscribed_count], ['unsubscribed', 'Отписались', unsubscribed]]

  return (
    <main className="page">
      <BackLink to="/subscribers">Все базы</BackLink>
      <PageHead title={list?.name ?? '…'} sub={list?.description ?? (list ? `${n(list.subscribed_count)} подписчиков` : undefined)}
        actions={<>
          <button className="btn icon" onClick={() => { setEditErr(null); list && setEditing({ name: list.name, description: list.description ?? '' }) }} aria-label="Переименовать базу" title="Переименовать базу"><Pencil size={15} /></button>
          <button className="btn icon" onClick={() => setRemoving(true)} aria-label="Удалить базу" title="Удалить базу"><Trash2 size={15} /></button>
          <button className="btn" onClick={() => { setAddErr(null); setAdding(true) }}><Plus size={16} />Добавить адрес</button>
          <button className="btn primary" onClick={() => setImportOpen(!importOpen)} aria-expanded={importOpen}><Upload size={16} />Загрузить из файла</button>
        </>} />

      {id && list && (importOpen || list.contacts_count === 0) && (
        <ImportPanel listId={id} onDone={refresh} onClose={list.contacts_count > 0 ? () => setImportOpen(false) : undefined} />
      )}

      {list && list.contacts_count > 0 && (
        <section className="check-summary" aria-label="Проверка адресов">
          <div className="check-head">
            <div>
              <b>{checking ? <><Loader2 size={15} className="spin" />Проверяем адреса: осталось {n(list.checks.unchecked)}</> : `Рассылку получат ${n(list.deliverable_count)} из ${n(list.contacts_count)}`}</b>
              <span className="sub">Формат, опечатки, одноразовые ящики и почтовый сервер домена. Существует ли сам ящик, покажет первая отправка.</span>
            </div>
            <div className="row">
              {list.checks.typo > 0 && <button className="btn sm" disabled={fixAll.isPending} onClick={() => fixAll.mutate()}><Wand2 size={14} />Исправить опечатки ({n(list.checks.typo)})</button>}
              <button className="btn sm ghost" disabled={recheck.isPending || checking} onClick={() => recheck.mutate()} title="Проверить все адреса заново"><RefreshCw size={14} />Проверить заново</button>
              {list.deliverable_count > 0 && <button className="btn sm primary" onClick={() => nav(`/campaigns/new?list=${list.id}`)}><Send size={14} />Новая рассылка</button>}
            </div>
          </div>
          <div className="check-chips">
            {(['ok', 'role', 'typo', 'disposable', 'no_mx', 'invalid', 'unchecked'] as const).filter((k) => k === 'ok' || list.checks[k] > 0).map((k) => (
              <button key={k} type="button" className={`check-chip ${CHECKS[k].tone}${check === k ? ' on' : ''}`} title={CHECKS[k].text} aria-pressed={check === k}
                onClick={() => { setCheck(check === k ? '' : k); setPage(1) }}>
                <i />{CHECKS[k].label}<b>{n(list.checks[k])}</b>
              </button>
            ))}
            {check && <button type="button" className="btn sm text" onClick={() => setCheck('')}>Показать все</button>}
          </div>
        </section>
      )}

      <div className="row" style={{ justifyContent: 'space-between', marginBottom: 16 }}>
        <div className="seg" role="radiogroup" aria-label="Кого показать">
          {filters.map(([v, label, count]) => (
            <button key={v} type="button" role="radio" aria-checked={status === v} className={status === v ? 'on' : ''} onClick={() => { setStatus(v); setPage(1) }}>
              {label}{count !== undefined && <span className="muted" style={{ marginLeft: 6 }}>{n(count)}</span>}
            </button>
          ))}
        </div>
        <form className="search" onSubmit={(e) => { e.preventDefault(); setPage(1); setQs(search.trim()) }}>
          <Search size={16} />
          <input className="input" placeholder="Поиск по адресу или имени" aria-label="Поиск по адресу или имени" value={search} onChange={(e) => setSearch(e.target.value)} />
        </form>
      </div>

      {cq.isSuccess && contacts.length === 0 ? (
        qs || status || check ? <Empty title="Ничего не найдено" /> : (
          <Empty title="В базе пока нет адресов" text="Загрузите файл CSV из Excel или вставьте адреса списком в блоке выше." />
        )
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Email</th><th>Имя</th>{columns.map((c) => <th key={c} className="mono" style={{ textTransform: 'none' }}>{c}</th>)}<th>Подписка</th><th>Проверка</th><th>Добавлен</th><th /></tr></thead>
            <tbody>
              {contacts.map((c) => (
                <tr key={c.id}>
                  <td className="mono">{c.email}</td>
                  <td>{c.name ?? <span className="muted">—</span>}</td>
                  {columns.map((k) => <td key={k} className="muted">{c.data?.[k] ?? '—'}</td>)}
                  <td>{c.unsubscribed_at ? <span className="status neutral"><i />Отписался</span> : <span className="status ok"><i />Подписан</span>}</td>
                  <td>
                    {(() => {
                      const k = c.check_status ?? 'unchecked'
                      return (
                        <div className="check-cell">
                          <span className={`status ${CHECKS[k].tone}`} title={CHECKS[k].text}><i />{CHECKS[k].label}</span>
                          {k === 'typo' && c.check_hint && (
                            <button className="btn sm text" onClick={() => fix.mutate(c)} disabled={fix.isPending} title={`Заменить на ${c.check_hint}`}>→ {c.check_hint.split('@')[1]}</button>
                          )}
                        </div>
                      )
                    })()}
                  </td>
                  <td className="sub">{fmtDate(c.created_at)}</td>
                  <td className="r"><button className="btn icon sm ghost" onClick={() => remove.mutate(c)} aria-label={`Удалить ${c.email}`} title="Удалить из базы"><X size={15} /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {cq.data && cq.data.meta.last_page > 1 && (
        <div className="pager">
          <span>Стр. {page} из {cq.data.meta.last_page}</span>
          <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Назад</button>
          <button className="btn sm" disabled={page >= cq.data.meta.last_page} onClick={() => setPage(page + 1)}>Дальше</button>
        </div>
      )}


      {adding && (
        <Modal title="Добавить адрес" onClose={() => setAdding(false)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); setAddErr(null); add.mutate() }} noValidate>
            <div className="field">
              <label htmlFor="c-email">Email</label>
              <input id="c-email" type="email" autoFocus className={`input mono${addErr ? ' err' : ''}`} placeholder="name@example.com" value={email} onChange={(e) => setEmail(e.target.value)} />
              {addErr && <div className="hint err">{addErr}</div>}
            </div>
            <div className="field">
              <label htmlFor="c-name">Имя <span className="muted">· необязательно</span></label>
              <input id="c-name" className="input" placeholder="Анна Иванова" value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setAdding(false)}>Отмена</button>
              <button className="btn primary" disabled={!email.trim() || add.isPending}>Добавить</button>
            </div>
          </form>
        </Modal>
      )}

      {editing && (
        <Modal title="База" onClose={() => setEditing(null)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); save.mutate() }} noValidate>
            <div className="field">
              <label htmlFor="l-name">Название</label>
              <input id="l-name" autoFocus className={`input${editErr ? ' err' : ''}`} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
              {editErr && <div className="hint err">{editErr}</div>}
            </div>
            <div className="field">
              <label htmlFor="l-desc">Описание</label>
              <input id="l-desc" className="input" value={editing.description} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setEditing(null)}>Отмена</button>
              <button className="btn primary" disabled={!editing.name.trim() || save.isPending}>Сохранить</button>
            </div>
          </form>
        </Modal>
      )}

      {removing && list && (
        <Modal title="Удалить базу?" onClose={() => setRemoving(false)}>
          <p style={{ color: 'var(--ink-2)' }}>База «{list.name}» и {n(list.contacts_count)} адресов в ней будут удалены. Уже отправленные письма останутся в журнале.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRemoving(false)}>Отмена</button>
            <button className="btn danger" disabled={destroy.isPending} onClick={() => destroy.mutate()}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}

function ImportPanel({ listId, onClose, onDone }: { listId: string; onClose?: () => void; onDone: () => void }) {
  const [mode, setMode] = useState<'file' | 'text'>('file')
  const [file, setFile] = useState<File | null>(null)
  const [text, setText] = useState('')
  const [over, setOver] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const input = useRef<HTMLInputElement>(null)

  const run = useMutation({
    mutationFn: () => {
      const body = new FormData()
      if (mode === 'file' && file) body.append('file', file)
      else body.append('text', text)
      return upload<{ data: ImportResult }>(`/lists/${listId}/import`, body)
    },
    onSuccess: (r) => { setResult(r.data); setFile(null); setText(''); onDone() },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.file?.[0] ?? e.errors.text?.[0] ?? e.message) : 'Не удалось загрузить'),
  })
  const pick = (f: File | undefined) => { if (f) { setErr(null); setFile(f) } }

  return (
    <section className="import-panel" aria-label="Загрузка адресов">
      <div className="import-head">
        <b>{result ? 'Адреса загружены' : 'Загрузка адресов'}</b>
        {onClose && <button className="btn icon sm ghost" onClick={onClose} aria-label="Скрыть загрузку" title="Скрыть"><X size={16} /></button>}
      </div>

      {result ? (
        <>
          <div className="import-result">
            <div><b>{n(result.rows)}</b><span>строк в файле</span></div>
            <div className="ok"><b>{n(result.added)}</b><span>новых адресов</span></div>
            <div><b>{n(result.updated)}</b><span>уже были в базе, обновлены</span></div>
            <div className={result.duplicates ? 'warn' : ''}><b>{n(result.duplicates)}</b><span>дублей в файле</span></div>
            <div className={result.skipped ? 'err' : ''}><b>{n(result.skipped)}</b><span>строк без адреса</span></div>
          </div>
          <p className="hint" style={{ margin: 0 }}>Адреса сохраняются в нижнем регистре, поэтому Anna@Mail.ru и anna@mail.ru — один адрес. Из повторов остаётся последняя строка.</p>
          {result.columns.length > 0 && (
            <p style={{ color: 'var(--ink-2)', margin: 0 }}>
              Колонки из файла можно вставить в письмо: {result.columns.map((c, i) => <span key={c}>{i > 0 && ' '}<span className="chip mono">{`{{ ${c} }}`}</span></span>)}
            </p>
          )}
          {result.skipped_rows.length > 0 && (
            <details className="import-details">
              <summary>Строки без корректного адреса: {n(result.skipped)}</summary>
              <table className="table"><thead><tr><th style={{ width: 90 }}>Строка</th><th>Содержимое</th></tr></thead>
                <tbody>{result.skipped_rows.map((r) => <tr key={r.line}><td className="mono">{r.line}</td><td className="mono muted">{r.value || '—'}</td></tr>)}</tbody></table>
              {result.skipped > result.skipped_rows.length && <div className="hint">Показаны первые {result.skipped_rows.length}</div>}
            </details>
          )}
          {result.duplicate_rows.length > 0 && (
            <details className="import-details">
              <summary>Дубли в файле: {n(result.duplicates)}</summary>
              <table className="table"><thead><tr><th style={{ width: 90 }}>Строка</th><th>Адрес</th><th>Впервые в строке</th></tr></thead>
                <tbody>{result.duplicate_rows.map((r) => <tr key={r.line}><td className="mono">{r.line}</td><td className="mono">{r.email}</td><td className="mono muted">{r.first_line}</td></tr>)}</tbody></table>
              {result.duplicates > result.duplicate_rows.length && <div className="hint">Показаны первые {result.duplicate_rows.length}</div>}
            </details>
          )}
          <div className="row">
            <button className="btn" onClick={() => setResult(null)}><Upload size={15} />Загрузить ещё</button>
            {onClose && <button className="btn ghost" onClick={onClose}>Скрыть</button>}
          </div>
        </>
      ) : (
        <form className="stack" onSubmit={(e) => { e.preventDefault(); setErr(null); run.mutate() }}>
          <div className="seg" role="radiogroup" aria-label="Откуда загрузить" style={{ alignSelf: 'flex-start' }}>
            <button type="button" role="radio" aria-checked={mode === 'file'} className={mode === 'file' ? 'on' : ''} onClick={() => setMode('file')}>Файл</button>
            <button type="button" role="radio" aria-checked={mode === 'text'} className={mode === 'text' ? 'on' : ''} onClick={() => setMode('text')}>Вставить списком</button>
          </div>
          {mode === 'file' ? (
            <>
              <button type="button" className={`dropzone${over ? ' over' : ''}${file ? ' has' : ''}`} onClick={() => input.current?.click()}
                onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)}
                onDrop={(e) => { e.preventDefault(); setOver(false); pick(e.dataTransfer.files[0]) }}>
                <FileUp size={22} />
                {file ? <><b>{file.name}</b><span>{Math.max(1, Math.round(file.size / 1024))} КБ · нажмите, чтобы выбрать другой</span></>
                  : <><b>Перетащите файл сюда или нажмите</b><span>CSV до 20 МБ. В Excel: Файл → Сохранить как → CSV</span></>}
              </button>
              <input ref={input} type="file" accept=".csv,.txt,text/csv,text/plain" hidden onChange={(e) => { pick(e.target.files?.[0]); e.target.value = '' }} />
              <div className="hint">Первая строка — названия колонок: <span className="mono">email</span>, <span className="mono">имя</span> и любые другие (город, специальность), они станут переменными письма. Адреса приводятся к нижнему регистру, дубли склеиваются, адреса, которые уже есть в базе, обновляются.</div>
            </>
          ) : (
            <div className="field">
              <label htmlFor="imp-text">Адреса</label>
              <textarea id="imp-text" className="textarea mono" rows={8} placeholder={'anna@example.com;Анна\npetr@example.com;Пётр'} value={text} onChange={(e) => setText(e.target.value)} />
              <div className="hint">По одному на строку. Через точку с запятой можно указать имя.</div>
            </div>
          )}
          {err && <div className="hint err">{err}</div>}
          <div className="row">
            <button className="btn primary" disabled={run.isPending || (mode === 'file' ? !file : !text.trim())}>{run.isPending ? <><Loader2 size={15} className="spin" />Загружаем…</> : <><Upload size={15} />Загрузить</>}</button>
            {onClose && <button type="button" className="btn ghost" onClick={onClose}>Отмена</button>}
          </div>
        </form>
      )}
    </section>
  )
}
