import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { api, ApiError, type Paged, type Suppression } from '../api'
import { Empty, Modal, PageHead, REASONS, fmtDate, useToast } from '../components/ui'

const TONE: Record<string, string> = { bounce: 'err', complaint: 'warn', unsubscribe: '', manual: 'accent' }

export default function Blocked() {
  const qc = useQueryClient()
  const toast = useToast()
  const [search, setSearch] = useState('')
  const [qs, setQs] = useState('')
  const [page, setPage] = useState(1)
  const [adding, setAdding] = useState(false)
  const [email, setEmail] = useState('')
  const [err, setErr] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['suppressions', qs, page],
    queryFn: () => api<Paged<Suppression>>(`/suppressions?${new URLSearchParams({ ...(qs && { q: qs }), page: String(page) })}`),
    placeholderData: keepPreviousData,
  })
  const add = useMutation({
    mutationFn: () => api('/suppressions', { method: 'POST', body: { email } }),
    onSuccess: () => { setAdding(false); setEmail(''); toast('Адрес заблокирован'); qc.invalidateQueries({ queryKey: ['suppressions'] }) },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.email?.[0] ?? e.message) : 'Ошибка'),
  })
  const remove = useMutation({
    mutationFn: (s: Suppression) => api(`/suppressions/${s.id}`, { method: 'DELETE' }),
    onSuccess: () => { toast('Адрес разблокирован'); qc.invalidateQueries({ queryKey: ['suppressions'] }) },
  })
  const list = q.data?.data ?? []

  return (
    <main className="page">
      <PageHead title="Блокировки" sub="На эти адреса письма не отправляются"
        actions={<button className="btn primary" onClick={() => setAdding(true)}><Plus size={16} />Заблокировать адрес</button>} />
      <form className="search" style={{ marginBottom: 16 }} onSubmit={(e) => { e.preventDefault(); setPage(1); setQs(search.trim()) }}>
        <Search size={16} />
        <input className="input" placeholder="Поиск по адресу" aria-label="Поиск по адресу" value={search} onChange={(e) => setSearch(e.target.value)} />
      </form>

      {q.isSuccess && list.length === 0 ? <Empty title={qs ? 'Ничего не найдено' : 'Список пуст'} text="Сюда попадают адреса с отказами доставки, жалобами и добавленные вручную." /> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Адрес</th><th>Причина</th><th>Добавлен</th><th /></tr></thead>
            <tbody>
              {list.map((s) => (
                <tr key={s.id}>
                  <td className="mono">{s.email}</td>
                  <td><span className={`chip ${TONE[s.reason]}`}>{REASONS[s.reason] ?? s.reason}</span></td>
                  <td className="sub">{fmtDate(s.created_at)}</td>
                  <td className="r">{s.reason !== 'complaint' && <button className="btn sm text" onClick={() => remove.mutate(s)}>Разблокировать</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {q.data && q.data.meta.last_page > 1 && (
        <div className="pager">
          <span>Стр. {page} из {q.data.meta.last_page}</span>
          <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Назад</button>
          <button className="btn sm" disabled={page >= q.data.meta.last_page} onClick={() => setPage(page + 1)}>Дальше</button>
        </div>
      )}

      {adding && (
        <Modal title="Заблокировать адрес" onClose={() => setAdding(false)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); setErr(null); add.mutate() }}>
            <div className="field">
              <label htmlFor="bemail">Email</label>
              <input id="bemail" type="email" autoFocus className={`input mono${err ? ' err' : ''}`} placeholder="name@example.com" value={email} onChange={(e) => setEmail(e.target.value)} />
              {err && <div className="hint err">{err}</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setAdding(false)}>Отмена</button>
              <button className="btn primary" disabled={!email.trim() || add.isPending}>Заблокировать</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
