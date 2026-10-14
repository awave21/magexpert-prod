import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Pencil, Search, Send, Trash2 } from 'lucide-react'
import { api, ApiError, type Paged, type SavedSegment, type SegmentContact } from '../api'
import { BackLink, Empty, Modal, PageHead, ago, fmtDate, useToast } from '../components/ui'
import { usePermissions } from '../auth'
import { conditionTitle, conditionValue, fieldLabel } from '../segments'

const n = (v: number) => v.toLocaleString('ru-RU')
const addresses = (v: number) => {
  const d = v % 10, h = v % 100
  return `${n(v)} ${d === 1 && h !== 11 ? 'адрес' : d >= 2 && d <= 4 && (h < 12 || h > 14) ? 'адреса' : 'адресов'}`
}
const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : '')

const CAMPAIGN_STATUS: Record<string, { label: (c: { scheduled_at: string | null; finished_at: string | null }) => string; tone: string }> = {
  draft: { label: () => 'Черновик', tone: 'neutral' },
  scheduled: { label: (c) => (c.scheduled_at ? `Запланирована на ${day(c.scheduled_at)}` : 'Запланирована'), tone: 'warn' },
  sending: { label: () => 'Отправляется', tone: 'warn' },
  paused: { label: () => 'На паузе', tone: 'warn' },
  cancelled: { label: () => 'Отменена', tone: 'neutral' },
  sent: { label: (c) => (c.finished_at ? `Отправлена ${day(c.finished_at)}` : 'Отправлена'), tone: 'ok' },
}

/** Карточка сегмента: условия, где он используется, и кто в нём сейчас. Состав только для просмотра. */
export default function SegmentDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { canEdit } = usePermissions()

  const sq = useQuery({ queryKey: ['segment', id], queryFn: () => api<{ data: SavedSegment }>(`/segments/${id}`) })
  const segment = sq.data?.data

  const [search, setSearch] = useState('')
  const [qs, setQs] = useState('')
  const [page, setPage] = useState(1)
  const cq = useQuery({
    queryKey: ['segment-contacts', id, qs, page],
    queryFn: () => api<Paged<SegmentContact>>(`/segments/${id}/contacts?${new URLSearchParams({ ...(qs && { q: qs }), page: String(page) })}`),
  })
  const contacts = cq.data?.data ?? []
  const fieldKeys = (segment?.conditions ?? []).flatMap((c) => (c.type === 'field' ? [c.field] : []))

  const [removing, setRemoving] = useState(false)
  const destroy = useMutation({
    mutationFn: (campaigns?: 'cancel' | 'keep') => api<{ message: string }>(`/segments/${id}`, { method: 'DELETE', body: campaigns ? { campaigns } : {} }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['segments'] })
      qc.invalidateQueries({ queryKey: ['campaigns'] })
      toast(r.message)
      nav('/subscribers/segments')
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Не удалось удалить', true),
  })

  if (sq.isError) return <main className="page"><BackLink to="/subscribers/segments">Все сегменты</BackLink><div className="callout err"><div><b>Сегмент не найден</b></div></div></main>

  const campaigns = segment?.campaigns ?? []
  const affected = campaigns.filter((c) => c.status === 'draft')
  // письма запущенных рассылок подготовлены при старте: удаление их не меняет, пока подготовка не закончилась — удалить нельзя
  const running = campaigns.filter((c) => ['scheduled', 'sending', 'paused'].includes(c.status))
  const preparing = running.filter((c) => !c.prepared)

  return (
    <main className="page">
      <BackLink to="/subscribers/segments">Все сегменты</BackLink>
      <PageHead title={segment?.name ?? '…'}
        sub={segment ? `${segment.description ? segment.description + ' · ' : ''}Сегмент базы «${segment.list.name}» · состав обновляется сам перед каждой рассылкой` : undefined}
        actions={canEdit && segment && <>
          <button className="btn icon" onClick={() => setRemoving(true)} aria-label="Удалить сегмент" title="Удалить сегмент"><Trash2 size={15} /></button>
          <button className="btn" onClick={() => nav(`/subscribers/segments/${segment.id}/edit`)}><Pencil size={15} />Изменить условия</button>
          {segment.count > 0 && <button className="btn primary" onClick={() => nav(`/campaigns/new?segment=${segment.id}`)}><Send size={15} />Новая рассылка</button>}
        </>} />

      {segment && (
        <section className="check-summary" aria-label="Условия сегмента">
          <div className="check-head">
            <div>
              <b>Рассылку получат {n(segment.count)} из {n(segment.list_count)} адресов базы</b>
              <span className="sub">Отписавшиеся и адреса, не прошедшие проверку, не считаются.</span>
            </div>
          </div>
          <div className="check-chips">
            {segment.conditions.map((c, i) => <span key={i} className="check-chip seg-chip">{conditionTitle(c)}: <b>{conditionValue(c)}</b></span>)}
          </div>
          {campaigns.length > 0 && (
            <div className="seg-used">
              <span className="seg-used-title">Используется в рассылках</span>
              {campaigns.map((c) => {
                const s = CAMPAIGN_STATUS[c.status] ?? { label: () => c.status, tone: 'neutral' }
                return <div key={c.id} className="row" style={{ gap: 12 }}><Link to={`/campaigns/${c.id}`}>{c.name}</Link><span className={`status ${s.tone}`}><i />{s.label(c)}</span></div>
              })}
            </div>
          )}
        </section>
      )}

      <div className="row" style={{ justifyContent: 'space-between', marginBottom: 16 }}>
        <h2 className="seg-h2">Адреса в сегменте</h2>
        <form className="search" onSubmit={(e) => { e.preventDefault(); setPage(1); setQs(search.trim()) }}>
          <Search size={16} />
          <input className="input" placeholder="Поиск по адресу или имени" aria-label="Поиск по адресу или имени" value={search} onChange={(e) => setSearch(e.target.value)} />
        </form>
      </div>

      {cq.isSuccess && contacts.length === 0 ? (
        <Empty title={qs ? 'Ничего не найдено' : 'Сейчас под условия никто не подходит'} text={qs ? undefined : 'Состав пересчитается сам, когда в базе появятся подходящие адреса.'} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Email</th><th>Имя</th>{fieldKeys.map((k) => <th key={k}>{fieldLabel(k)}</th>)}<th>Теги</th><th>Последнее открытие</th><th>Добавлен</th></tr></thead>
            <tbody>
              {contacts.map((c) => (
                <tr key={c.id}>
                  <td className="mono">{c.email}</td>
                  <td>{c.name ?? <span className="muted">—</span>}</td>
                  {fieldKeys.map((k) => <td key={k} className="muted">{c.data?.[k] ?? '—'}</td>)}
                  <td className="muted">{c.tags.length ? c.tags.join(', ') : '—'}</td>
                  <td className="sub">{c.last_opened_at ? ago(c.last_opened_at) : '—'}</td>
                  <td className="sub">{fmtDate(c.created_at)}</td>
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

      {removing && segment && (
        <Modal title="Удалить сегмент?" onClose={() => setRemoving(false)}>
          <div className="stack">
            <p style={{ margin: 0, color: 'var(--ink-2)' }}>Адреса останутся в базе «{segment.list.name}», удалятся только условия отбора.</p>
            {running.length > 0 && (
              <div className="callout warn">
                <span className="ic"><AlertTriangle size={15} /></span>
                <div>
                  <b>{running.length === 1 ? 'Сегмент выбран в запущенной рассылке' : `Сегмент выбран в ${n(running.length)} запущенных рассылках`}</b>
                  <span>
                    {running.map((c) => `«${c.name}» — ${(CAMPAIGN_STATUS[c.status]?.label(c) ?? c.status).toLowerCase()}${c.recipients_count ? `, ${addresses(c.recipients_count)}` : ''}`).join('; ')}.{' '}
                    {preparing.length > 0
                      ? 'Письма ещё готовятся: оставить рассылку без сегмента пока нельзя, только отменить.'
                      : 'Если оставить, письма уйдут тем же адресам, что были в сегменте при запуске.'}
                  </span>
                </div>
              </div>
            )}
            {affected.length > 0 && (
              <div className="callout">
                <span className="ic"><AlertTriangle size={15} /></span>
                <div>
                  <b>{affected.length === 1 ? 'Сегмент выбран в черновике рассылки' : `Сегмент выбран в ${n(affected.length)} черновиках рассылок`}</b>
                  <span>{affected.map((c) => `«${c.name}»`).join(', ')} {affected.length === 1 ? 'останется' : 'останутся'} без получателей, пока вы не выберете другую базу или сегмент.</span>
                </div>
              </div>
            )}
            {running.length > 0 ? (
              // три исхода не помещаются в строку: столбиком, опасное действие сверху
              <div className="stack" style={{ gap: 8 }}>
                <button type="button" className="btn danger" disabled={destroy.isPending} onClick={() => destroy.mutate('cancel')}>
                  {running.length === 1 ? 'Отменить рассылку и удалить сегмент' : 'Отменить рассылки и удалить сегмент'}
                </button>
                <button type="button" className="btn" disabled={destroy.isPending || preparing.length > 0} onClick={() => destroy.mutate('keep')}
                  title={preparing.length > 0 ? 'Письма ещё готовятся' : undefined}>Удалить только сегмент, рассылку оставить</button>
                <button type="button" className="btn ghost" onClick={() => setRemoving(false)}>Отмена</button>
              </div>
            ) : (
              <div className="row" style={{ justifyContent: 'flex-end' }}>
                <button type="button" className="btn" onClick={() => setRemoving(false)}>Отмена</button>
                <button type="button" className="btn danger" disabled={destroy.isPending} onClick={() => destroy.mutate(undefined)}>Удалить</button>
              </div>
            )}
          </div>
        </Modal>
      )}
    </main>
  )
}
