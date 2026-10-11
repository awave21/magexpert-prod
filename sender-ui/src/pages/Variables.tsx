import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError, type CustomVariable, type Variables } from '../api'
import { Empty, Modal, PageHead, useToast } from '../components/ui'

type Draft = { id?: number; key: string; label: string; default_value: string }
const EMPTY: Draft = { key: '', label: '', default_value: '' }

export default function VariablesPage() {
  const qc = useQueryClient()
  const toast = useToast()
  const [draft, setDraft] = useState<Draft | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [removing, setRemoving] = useState<CustomVariable | null>(null)

  const q = useQuery({ queryKey: ['variables'], queryFn: () => api<Variables>('/variables') })
  const base = q.data?.base ?? []
  const custom = q.data?.custom ?? []

  const save = useMutation({
    mutationFn: (d: Draft) => api(d.id ? `/variables/${d.id}` : '/variables', {
      method: d.id ? 'PUT' : 'POST',
      body: { key: d.key, label: d.label, default_value: d.default_value || null },
    }),
    onSuccess: () => { setDraft(null); setErrors({}); toast('Переменная сохранена'); qc.invalidateQueries({ queryKey: ['variables'] }) },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast(e.message, true) } },
  })
  const remove = useMutation({
    mutationFn: (v: CustomVariable) => api(`/variables/${v.id}`, { method: 'DELETE' }),
    onSuccess: () => { setRemoving(null); toast('Переменная удалена'); qc.invalidateQueries({ queryKey: ['variables'] }) },
  })

  const open = (d: Draft) => { setErrors({}); setDraft(d) }
  const submit = (e: FormEvent) => { e.preventDefault(); if (draft) save.mutate(draft) }
  const err = (k: string) => errors[k]?.[0]

  return (
    <main className="page">
      <PageHead title="Переменные" sub="Подставляются в тему и текст письма: {{ ключ }}"
        actions={<button className="btn primary" onClick={() => open(EMPTY)}><Plus size={16} />Новая переменная</button>} />

      <h2 className="section-title">Свои переменные</h2>
      {q.isSuccess && custom.length === 0 ? (
        <Empty title="Своих переменных пока нет" text="Например, phone_support или promo_code. Значение по умолчанию подставится, если приложение не передало своё." />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Ключ</th><th>Название</th><th>Значение по умолчанию</th><th /></tr></thead>
            <tbody>
              {custom.map((v) => (
                <tr key={v.id}>
                  <td><span className="chip accent mono">{v.key}</span></td>
                  <td>{v.label}</td>
                  <td className="sub">{v.default_value || '—'}</td>
                  <td className="r">
                    <button className="btn sm text" onClick={() => open({ id: v.id, key: v.key, label: v.label, default_value: v.default_value ?? '' })}><Pencil size={14} />Изменить</button>
                    <button className="btn sm text" onClick={() => setRemoving(v)}><Trash2 size={14} />Удалить</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h2 className="section-title" style={{ marginTop: 36 }}>Базовые переменные</h2>
      <p className="sub" style={{ marginBottom: 12 }}>Их передаёт сайт при отправке. Менять и удалять их нельзя.</p>
      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Ключ</th><th>Название</th><th>Описание</th><th>Пример</th></tr></thead>
          <tbody>
            {base.map((v) => (
              <tr key={v.key}>
                <td><span className="chip mono">{v.key}</span></td>
                <td>{v.label}</td>
                <td className="sub">{v.description}</td>
                <td className="sub">{v.sample}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {draft && (
        <Modal title={draft.id ? 'Изменить переменную' : 'Новая переменная'} onClose={() => setDraft(null)}>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="v-key">Ключ</label>
              <input id="v-key" className={`input mono${err('key') ? ' err' : ''}`} value={draft.key} onChange={(e) => setDraft({ ...draft, key: e.target.value.trim() })} placeholder="promo_code" autoFocus />
              <div className={`hint${err('key') ? ' err' : ''}`}>{err('key') ?? 'Латиница в нижнем регистре, цифры и подчёркивание'}</div>
            </div>
            <div className="field">
              <label htmlFor="v-label">Название</label>
              <input id="v-label" className={`input${err('label') ? ' err' : ''}`} value={draft.label} onChange={(e) => setDraft({ ...draft, label: e.target.value })} placeholder="Промокод" />
              {err('label') && <div className="hint err">{err('label')}</div>}
            </div>
            <div className="field">
              <label htmlFor="v-def">Значение по умолчанию</label>
              <input id="v-def" className={`input${err('default_value') ? ' err' : ''}`} value={draft.default_value} onChange={(e) => setDraft({ ...draft, default_value: e.target.value })} placeholder="Необязательно" />
              <div className="hint">Подставляется, если приложение не передало значение. Показывается и в предпросмотре.</div>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setDraft(null)}>Отмена</button>
              <button className="btn primary" disabled={save.isPending || !draft.key || !draft.label}>{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
            </div>
          </form>
        </Modal>
      )}

      {removing && (
        <Modal title="Удалить переменную?" onClose={() => setRemoving(null)}>
          <p style={{ color: 'var(--ink-2)' }}>Ключ <span className="mono">{removing.key}</span> перестанет подставляться. В шаблонах он останется как пустое место.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRemoving(null)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate(removing)}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
