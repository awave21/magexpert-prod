import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Mail, Pencil, Plus, RotateCw, Trash2, X } from 'lucide-react'
import { api, ApiError, type SenderAddress } from '../api'
import { Modal, ago, useToast } from './ui'

// Адреса отправителей домена: шаблоны выбирают отправителя из этого списка
export function SenderAddresses({ domain, verified }: { domain: string; verified: boolean }) {
  const qc = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: ['sender-addresses'], queryFn: () => api<{ data: SenderAddress[] }>('/sender-addresses') })
  const list = (q.data?.data ?? []).filter((a) => a.domain === domain)

  const [adding, setAdding] = useState(false)
  const [local, setLocal] = useState('')
  const [name, setName] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [removing, setRemoving] = useState<SenderAddress | null>(null)

  const refresh = () => qc.invalidateQueries({ queryKey: ['sender-addresses'] })
  const [formError, setFormError] = useState<string | null>(null)
  const add = useMutation({
    mutationFn: () => api<{ data: SenderAddress; warning: string | null }>('/sender-addresses', { method: 'POST', body: { email: `${local.trim()}@${domain}`, name: name.trim() } }),
    onSuccess: (r) => {
      setAdding(false)
      toast(r.warning ?? `Письмо со ссылкой отправлено на ${r.data.email}`, !!r.warning)
      setLocal(''); setName(''); setErrors({}); setFormError(null)
      refresh()
    },
    onError: (e) => {
      // ошибки полей показываем у полей, остальное — общим сообщением; список обновляем: адрес мог сохраниться
      const fields = e instanceof ApiError ? e.errors : {}
      setErrors(fields)
      setFormError(Object.keys(fields).length ? null : (e as Error).message || 'Не удалось добавить адрес')
      refresh()
    },
  })
  const rename = useMutation({
    mutationFn: (x: { id: number; name: string }) => api(`/sender-addresses/${x.id}`, { method: 'PUT', body: { name: x.name.trim() } }),
    onSuccess: () => { setEditing(null); toast('Имя изменено'); refresh() },
    onError: (e) => toast((e as Error).message, true),
  })
  const resend = useMutation({
    mutationFn: (a: SenderAddress) => api(`/sender-addresses/${a.id}/resend`, { method: 'POST' }),
    onSuccess: (_r, a) => { toast(`Письмо отправлено ещё раз на ${a.email}`); refresh() },
    onError: (e) => toast((e as Error).message, true),
  })
  const remove = useMutation({
    mutationFn: (a: SenderAddress) => api(`/sender-addresses/${a.id}`, { method: 'DELETE' }),
    onSuccess: () => { setRemoving(null); toast('Адрес удалён'); refresh() },
  })

  const submit = (e: FormEvent) => { e.preventDefault(); add.mutate() }

  return (
    <section className="addr">
      <div className="addr-head">
        <div>
          <h2>Адреса отправителей</h2>
          <p className="sub">С этих адресов уходят письма. На новый адрес придёт письмо со ссылкой: пока её не открыли, письма с адреса не отправляются.</p>
        </div>
        <button type="button" className="btn" onClick={() => { setErrors({}); setFormError(null); setAdding(true) }}><Plus size={15} />Добавить адрес</button>
      </div>

      {list.length === 0 ? (
        <div className="addr-empty"><Mail size={18} />Адресов пока нет. Добавьте, например, noreply@{domain} с именем вашей организации.</div>
      ) : (
        <div className="addr-list">
          {list.map((a) => (
            <div key={a.id} className="addr-row">
              <span className="avatar sm">{a.name.charAt(0).toUpperCase()}</span>
              {editing?.id === a.id ? (
                <form className="addr-edit" onSubmit={(e) => { e.preventDefault(); rename.mutate(editing) }}>
                  <input className="input sm" autoFocus aria-label="Имя отправителя" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })}
                    onKeyDown={(e) => e.key === 'Escape' && setEditing(null)} />
                  <button className="btn icon sm primary" aria-label="Сохранить имя" disabled={!editing.name.trim()}><Check size={15} /></button>
                  <button type="button" className="btn icon sm" aria-label="Отмена" onClick={() => setEditing(null)}><X size={15} /></button>
                </form>
              ) : (
                <div className="addr-who">
                  <b>{a.name}</b>
                  <span className="mono">{a.email}</span>
                </div>
              )}
              {editing?.id !== a.id && (a.confirmed
                ? <span className="status ok addr-status"><i />Подтверждён</span>
                : (
                  <div className="addr-pending">
                    <span className="status warn"><i />Ждёт подтверждения</span>
                    <span className="sub">{a.confirmation_sent_at ? `письмо отправлено ${ago(a.confirmation_sent_at)}` : 'письмо не отправлено'}</span>
                    <button type="button" className="btn sm text" disabled={resend.isPending} onClick={() => resend.mutate(a)}><RotateCw size={13} />Отправить ещё раз</button>
                  </div>
                ))}
              {editing?.id !== a.id && (
                <div className="addr-acts">
                  <button type="button" className="btn icon sm ghost" title="Изменить имя" aria-label="Изменить имя" onClick={() => setEditing({ id: a.id, name: a.name })}><Pencil size={15} /></button>
                  <button type="button" className="btn icon sm ghost danger-text" title="Удалить" aria-label="Удалить адрес" onClick={() => setRemoving(a)}><Trash2 size={15} /></button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
      {!verified && list.length > 0 && <div className="hint warn-text">Домен ещё не подтверждён: письма с этих адресов уйдут после проверки DNS-записей.</div>}

      {adding && (
        <Modal title="Новый адрес отправителя" onClose={() => setAdding(false)}>
          <form className="stack" onSubmit={submit}>
            <div className="field">
              <label htmlFor="addr-local">Адрес</label>
              <div className="addr-email">
                <input id="addr-local" autoFocus className={`input mono${errors.email ? ' err' : ''}`} placeholder="noreply" value={local}
                  onChange={(e) => setLocal(e.target.value.replace(/[@\s]/g, '').toLowerCase())} />
                <span className="mono">@{domain}</span>
              </div>
              {errors.email ? <div className="hint err">{errors.email[0]}</div> : <div className="hint">На этот ящик придёт письмо со ссылкой подтверждения, поэтому он должен принимать почту.</div>}
            </div>
            <div className="field">
              <label htmlFor="addr-name">Имя отправителя</label>
              <input id="addr-name" className={`input${errors.name ? ' err' : ''}`} placeholder="МедАльянсГрупп Expert" value={name} onChange={(e) => setName(e.target.value)} />
              {errors.name ? <div className="hint err">{errors.name[0]}</div> : <div className="hint">Так письмо подписано во входящих у получателя</div>}
            </div>
            {formError && <div className="callout err" role="alert"><div><b>Не получилось</b><span>{formError}</span></div></div>}
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setAdding(false)}>Отмена</button>
              <button className="btn primary" disabled={!local.trim() || !name.trim() || add.isPending}>{add.isPending ? 'Добавляем…' : 'Добавить'}</button>
            </div>
          </form>
        </Modal>
      )}

      {removing && (
        <Modal title="Удалить адрес?" onClose={() => setRemoving(null)}>
          <p style={{ color: 'var(--ink-2)', margin: 0 }}>Шаблоны с отправителем <span className="mono">{removing.email}</span> останутся без адреса, и письма по ним не уйдут, пока вы не выберете другой.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRemoving(null)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate(removing)}>Удалить</button>
          </div>
        </Modal>
      )}
    </section>
  )
}
