import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, type AuditEntry, type Paged } from '../api'
import { fmtDate } from '../components/ui'
import { SettingsHead } from '../components/SettingsParts'

// вкладка «Журнал действий» в настройках
export default function AuditLog() {
  const [page, setPage] = useState(1)
  const aq = useQuery({
    queryKey: ['audit-log', page],
    queryFn: () => api<Paged<AuditEntry>>(`/audit-log?page=${page}`),
    placeholderData: keepPreviousData,
  })
  const log = aq.data?.data ?? []

  return (
    <div>
      <SettingsHead title="Журнал действий" sub="Кто что менял в Sender" />
      {aq.isError ? (
        <p className="hint err">Не удалось загрузить журнал. Обновите страницу.</p>
      ) : aq.isSuccess && log.length === 0 ? (
        <p className="hint">Здесь появятся запуски и отмены рассылок, удаление баз и писем, новые ключи и домены, изменения в команде.</p>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Когда</th><th>Кто</th><th>Что сделал</th></tr></thead>
            <tbody>
              {log.map((e) => (
                <tr key={e.id}>
                  <td className="sub" style={{ whiteSpace: 'nowrap' }}>{fmtDate(e.created_at)}</td>
                  <td>{e.user}</td>
                  <td>{e.action}{e.subject && <> «{e.subject}»</>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {aq.data && aq.data.meta.last_page > 1 && (
        <div className="pager">
          <span>Стр. {page} из {aq.data.meta.last_page}</span>
          <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Назад</button>
          <button className="btn sm" disabled={page >= aq.data.meta.last_page} onClick={() => setPage(page + 1)}>Дальше</button>
        </div>
      )}
    </div>
  )
}
