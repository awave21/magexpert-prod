import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Plus } from 'lucide-react'
import { api, type ApiKey } from '../api'
import { CopyField, Empty, Modal, PageHead, ago, fmtDate, useToast } from '../components/ui'

export default function ApiKeys() {
  const qc = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: ['keys'], queryFn: () => api<{ data: ApiKey[] }>('/api-keys') })
  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')
  const [plain, setPlain] = useState<string | null>(null)
  const [revoke, setRevoke] = useState<ApiKey | null>(null)

  const create = useMutation({
    mutationFn: () => api<{ plain: string }>('/api-keys', { method: 'POST', body: { name } }),
    onSuccess: (r) => { setPlain(r.plain); setCreating(false); setName(''); qc.invalidateQueries({ queryKey: ['keys'] }) },
    onError: (e) => toast((e as Error).message, true),
  })
  const doRevoke = useMutation({
    mutationFn: (k: ApiKey) => api(`/api-keys/${k.id}`, { method: 'DELETE' }),
    onSuccess: () => { setRevoke(null); toast('Ключ отозван'); qc.invalidateQueries({ queryKey: ['keys'] }) },
  })
  const submit = (e: FormEvent) => { e.preventDefault(); create.mutate() }
  const list = q.data?.data ?? []

  return (
    <main className="page">
      <PageHead title="API-ключи" sub="Ключ нужен приложению, чтобы отправлять письма через Sender"
        actions={<button className="btn primary" onClick={() => setCreating(true)}><Plus size={16} />Создать ключ</button>} />

      {plain && (
        <div className="callout" style={{ marginBottom: 28, flexDirection: 'column', gap: 12 }}>
          <div className="row" style={{ flexWrap: 'nowrap' }}>
            <span className="ic"><Check size={15} /></span>
            <div><b>Ключ создан</b><span>Скопируйте его сейчас: потом он не покажется. В приложении укажите его в SENDER_API_KEY.</span></div>
          </div>
          <div style={{ width: '100%' }}><CopyField value={plain} /></div>
          <button className="btn sm" onClick={() => setPlain(null)} style={{ alignSelf: 'flex-start' }}>Я сохранил ключ</button>
        </div>
      )}

      {q.isSuccess && list.length === 0 ? <Empty title="Ключей пока нет" text="Создайте ключ и передайте его в настройки приложения." /> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Название</th><th>Ключ</th><th>Создан</th><th>Использован</th><th>Статус</th><th /></tr></thead>
            <tbody>
              {list.map((k) => (
                <tr key={k.id}>
                  <td style={{ fontWeight: 500 }}>{k.name}</td>
                  <td className="mono muted">{k.key_prefix}…</td>
                  <td className="sub">{fmtDate(k.created_at)}</td>
                  <td className="sub">{k.last_used_at ? ago(k.last_used_at) : 'ни разу'}</td>
                  <td>{k.revoked_at ? <span className="status neutral"><i />Отозван</span> : <span className="status ok"><i />Активен</span>}</td>
                  <td className="r">{!k.revoked_at && <button className="btn sm danger" onClick={() => setRevoke(k)}>Отозвать</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {creating && (
        <Modal title="Новый API-ключ" onClose={() => setCreating(false)}>
          <form className="stack" onSubmit={submit}>
            <div className="field">
              <label htmlFor="kname">Название</label>
              <input id="kname" autoFocus className="input" placeholder="Сайт MagExpert, продакшен" value={name} onChange={(e) => setName(e.target.value)} />
              <div className="hint">Видно только вам, чтобы отличать ключи</div>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setCreating(false)}>Отмена</button>
              <button className="btn primary" disabled={!name.trim() || create.isPending}>Создать</button>
            </div>
          </form>
        </Modal>
      )}
      {revoke && (
        <Modal title="Отозвать ключ?" onClose={() => setRevoke(null)}>
          <p style={{ color: 'var(--ink-2)' }}>Приложение с ключом «{revoke.name}» сразу перестанет отправлять письма. Отменить нельзя.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRevoke(null)}>Отмена</button>
            <button className="btn danger" onClick={() => doRevoke.mutate(revoke)}>Отозвать</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
