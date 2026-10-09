import { useRef, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileUp, Pencil, Plus, Search, Send, Trash2, Upload, X } from 'lucide-react'
import { api, ApiError, upload, type Contact, type ContactList, type ImportResult, type Paged } from '../api'
import { BackLink, Empty, Modal, PageHead, fmtDate, useToast } from '../components/ui'

const n = (v: number) => v.toLocaleString('ru-RU')
type StatusFilter = '' | 'subscribed' | 'unsubscribed'

export default function ListDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()

  const lq = useQuery({ queryKey: ['lists', id], queryFn: () => api<{ data: ContactList }>(`/lists/${id}`) })
  const list = lq.data?.data

  const [search, setSearch] = useState('')
  const [qs, setQs] = useState('')
  const [status, setStatus] = useState<StatusFilter>('')
  const [page, setPage] = useState(1)
  const cq = useQuery({
    queryKey: ['contacts', id, qs, status, page],
    queryFn: () => api<Paged<Contact>>(`/lists/${id}/contacts?${new URLSearchParams({ ...(qs && { q: qs }), ...(status && { status }), page: String(page) })}`),
    placeholderData: keepPreviousData,
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
          <button className="btn primary" onClick={() => setImportOpen(true)}><Upload size={16} />Загрузить из файла</button>
        </>} />

      {list && list.subscribed_count > 0 && (
        <div className="callout" style={{ marginBottom: 24 }}>
          <span className="ic"><Send size={15} /></span>
          <div><b>Можно отправлять</b><span>Создайте рассылку и выберите эту базу: письмо получат {n(list.subscribed_count)} подписчиков. <a href="#" onClick={(e) => { e.preventDefault(); nav(`/campaigns/new?list=${list.id}`) }}>Новая рассылка</a></span></div>
        </div>
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
        qs || status ? <Empty title="Ничего не найдено" /> : (
          <Empty title="В базе пока нет адресов" text="Загрузите файл CSV из Excel или вставьте адреса списком."
            action={<button className="btn primary" onClick={() => setImportOpen(true)}><Upload size={16} />Загрузить из файла</button>} />
        )
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Email</th><th>Имя</th>{columns.map((c) => <th key={c} className="mono" style={{ textTransform: 'none' }}>{c}</th>)}<th>Статус</th><th>Добавлен</th><th /></tr></thead>
            <tbody>
              {contacts.map((c) => (
                <tr key={c.id}>
                  <td className="mono">{c.email}</td>
                  <td>{c.name ?? <span className="muted">—</span>}</td>
                  {columns.map((k) => <td key={k} className="muted">{c.data?.[k] ?? '—'}</td>)}
                  <td>{c.unsubscribed_at ? <span className="status neutral"><i />Отписался</span> : <span className="status ok"><i />Подписан</span>}</td>
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

      {importOpen && id && <ImportModal listId={id} onClose={() => setImportOpen(false)} onDone={refresh} />}

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

function ImportModal({ listId, onClose, onDone }: { listId: string; onClose: () => void; onDone: () => void }) {
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
    onSuccess: (r) => { setResult(r.data); onDone() },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.file?.[0] ?? e.errors.text?.[0] ?? e.message) : 'Не удалось загрузить'),
  })

  const pick = (f: File | undefined) => { if (f) { setErr(null); setFile(f) } }

  if (result) {
    return (
      <Modal title="Адреса загружены" onClose={onClose}>
        <div className="import-result">
          <div><b>{n(result.added)}</b><span>новых</span></div>
          <div><b>{n(result.updated)}</b><span>обновлено</span></div>
          <div><b className={result.skipped ? 'warn-num' : ''}>{n(result.skipped)}</b><span>пропущено</span></div>
        </div>
        {result.skipped > 0 && <p className="hint">Пропущены строки без корректного адреса.</p>}
        {result.columns.length > 0 && (
          <p style={{ color: 'var(--ink-2)', margin: '12px 0 0' }}>
            Колонки из файла можно вставить в письмо как переменные: {result.columns.map((c, i) => <span key={c}>{i > 0 && ', '}<span className="chip mono">{`{{ ${c} }}`}</span></span>)}
          </p>
        )}
        <div className="row" style={{ justifyContent: 'flex-end', marginTop: 20 }}>
          <button className="btn primary" onClick={onClose}>Готово</button>
        </div>
      </Modal>
    )
  }

  return (
    <Modal title="Загрузить адреса" onClose={onClose}>
      <form className="stack" onSubmit={(e) => { e.preventDefault(); setErr(null); run.mutate() }}>
        <div className="seg" role="radiogroup" aria-label="Откуда загрузить">
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
            <div className="hint">Первая строка — названия колонок: <span className="mono">email</span>, <span className="mono">имя</span> и любые другие (город, специальность). Другие колонки станут переменными письма. Адреса, которые уже есть в базе, обновятся.</div>
          </>
        ) : (
          <div className="field">
            <label htmlFor="imp-text">Адреса</label>
            <textarea id="imp-text" className="textarea mono" rows={8} placeholder={'anna@example.com;Анна\npetr@example.com;Пётр'} value={text} onChange={(e) => setText(e.target.value)} />
            <div className="hint">По одному на строку. Через точку с запятой можно указать имя.</div>
          </div>
        )}
        {err && <div className="hint err">{err}</div>}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <button type="button" className="btn" onClick={onClose}>Отмена</button>
          <button className="btn primary" disabled={run.isPending || (mode === 'file' ? !file : !text.trim())}>{run.isPending ? 'Загружаем…' : 'Загрузить'}</button>
        </div>
      </form>
    </Modal>
  )
}
