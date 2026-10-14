import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Pencil } from 'lucide-react'
import { api, ApiError, type ContactList, type ListField, type ListTag, type SavedSegment, type SegmentCondition } from '../api'
import { BackLink, Modal, PageHead, useToast } from '../components/ui'
import { usePermissions } from '../auth'
import { SegmentConditions } from '../components/SegmentConditions'
import { isComplete, type DraftCondition } from '../segments'

const n = (v: number) => v.toLocaleString('ru-RU')
const strip = (conditions: DraftCondition[]): SegmentCondition[] => conditions.map(({ key: _key, ...c }) => c as SegmentCondition)

/**
 * Новый сегмент и изменение сегмента. Название спрашивается во всплывающем окне, как у новой базы.
 * База выбирается только при создании: значения полей и теги условий берутся из неё.
 */
export default function SegmentEditor() {
  const { id } = useParams()
  const isNew = !id
  const { canEdit } = usePermissions()
  const sq = useQuery({ queryKey: ['segment', id], queryFn: () => api<{ data: SavedSegment }>(`/segments/${id}`), enabled: !isNew })

  if (!canEdit) return <Navigate to={isNew ? '/subscribers/segments' : `/subscribers/segments/${id}`} replace />
  if (!isNew && sq.isError) return <main className="page"><BackLink to="/subscribers/segments">Все сегменты</BackLink><div className="callout err"><div><b>Сегмент не найден</b></div></div></main>
  if (!isNew && !sq.data) return <main className="page"><BackLink to={`/subscribers/segments/${id}`}>К сегменту</BackLink></main>

  return <SegmentForm key={id ?? 'new'} id={id} initial={sq.data?.data} />
}

function SegmentForm({ id, initial }: { id?: string; initial?: SavedSegment }) {
  const isNew = !id
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const lq = useQuery({ queryKey: ['lists'], queryFn: () => api<{ data: ContactList[] }>('/lists') })
  const lists = lq.data?.data ?? []

  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [listId, setListId] = useState<number | ''>(initial?.list.id ?? '')
  const [conditions, setConditions] = useState<DraftCondition[]>(() => (initial?.conditions ?? []).map((c, i) => ({ ...c, key: i + 1 }) as DraftCondition))
  const [naming, setNaming] = useState(isNew)
  const [nameDraft, setNameDraft] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [reset, setReset] = useState(false)

  const fq = useQuery({ queryKey: ['list-fields', listId], queryFn: () => api<{ data: ListField[] }>(`/lists/${listId}/fields`), enabled: listId !== '' })
  const tq = useQuery({ queryKey: ['list-tags', listId], queryFn: () => api<{ data: ListTag[] }>(`/lists/${listId}/tags`), enabled: listId !== '' })
  const list = lists.find((l) => l.id === listId)

  const ready = strip(conditions).filter(isComplete)
  const cq = useQuery({
    queryKey: ['segment-count', listId, JSON.stringify(ready)],
    queryFn: () => api<{ data: { count: number } }>(`/lists/${listId}/count`, { method: 'POST', body: { conditions: ready } }),
    enabled: listId !== '' && ready.length > 0,
    placeholderData: (prev) => prev,
  })

  const pickList = (value: number | '') => {
    // значения полей и теги у другой базы свои: такие условия сбрасываются, активность остаётся
    const kept = conditions.filter((c) => c.type === 'activity')
    setReset(kept.length !== conditions.length)
    setConditions(kept)
    setListId(value)
  }

  const save = useMutation({
    mutationFn: () => {
      const body = { name, description: description || null, conditions: strip(conditions), ...(isNew ? { list_id: listId || null } : {}) }
      return isNew
        ? api<{ data: SavedSegment }>('/segments', { method: 'POST', body })
        : api<{ data: SavedSegment }>(`/segments/${id}`, { method: 'PUT', body })
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['segments'] })
      qc.invalidateQueries({ queryKey: ['segment', String(r.data.id)] })
      toast(isNew ? 'Сегмент создан' : 'Сегмент сохранён')
      nav(`/subscribers/segments/${r.data.id}`)
    },
    onError: (e) => {
      if (!(e instanceof ApiError)) { setErrors({ form: 'Ошибка' }); return }
      setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])))
      if (e.errors.name) { setNameDraft(name); setNaming(true) }
      if (!Object.keys(e.errors).length) setErrors({ form: e.message })
    },
  })

  const incomplete = conditions.length > ready.length
  const canSave = name.trim() !== '' && listId !== '' && ready.length > 0 && !incomplete && !save.isPending
  const formError = errors.form ?? errors.list_id ?? errors.conditions
  const summary = formError ? <span className="hint err">{formError}</span>
    : listId === '' ? <span className="sub">Выберите базу</span>
    : conditions.length === 0 ? <span className="sub">Добавьте хотя бы одно условие</span>
    : incomplete ? <span className="sub">Выберите значения у всех условий</span>
    : <span className="sub">{cq.data ? `Подходят ${n(cq.data.data.count)} из ${n(list?.deliverable_count ?? 0)} адресов базы` : 'Считаем…'}</span>

  return (
    <main className="page nl-page">
      <BackLink to={isNew ? '/subscribers/segments' : `/subscribers/segments/${id}`}>{isNew ? 'Все сегменты' : 'К сегменту'}</BackLink>
      <PageHead title={name.trim() || (isNew ? 'Новый сегмент' : '…')} sub={list ? `Сегмент базы «${list.name}»` : 'Сегмент базы'}
        actions={<button type="button" className="btn" onClick={() => { setNameDraft(name); setNaming(true) }}><Pencil size={15} />Изменить название</button>} />

      <form className="nl-form" onSubmit={(e: FormEvent) => { e.preventDefault(); setErrors({}); save.mutate() }} noValidate>
        <section className="nl-block">
          <div className="nl-head"><h2>База</h2><p>{isNew ? 'Сегмент берёт адреса из одной базы и обновляется вместе с ней.' : 'Базу сегмента поменять нельзя: для другой базы создайте новый сегмент.'}</p></div>
          <select className="select" aria-label="База" value={listId} disabled={!isNew} onChange={(e) => pickList(e.target.value ? Number(e.target.value) : '')}>
            <option value="">Выберите базу</option>
            {lists.map((l) => <option key={l.id} value={l.id}>{l.name} · {n(l.deliverable_count)}</option>)}
          </select>
          {reset && <div className="hint">Условия по полям и тегам сброшены: у другой базы свои значения.</div>}
        </section>

        <section className="nl-block">
          <div className="nl-head"><h2>Кому из базы</h2><p>Добавляйте условия по одному, каждое один раз. Подходят те, кто удовлетворяет всем условиям.</p></div>
          {listId === '' ? <div className="muted">Сначала выберите базу.</div> : (
            <SegmentConditions conditions={conditions} onChange={setConditions} fields={fq.data?.data ?? []} tags={tq.data?.data ?? []} errors={errors} />
          )}
        </section>

        <div className="nl-footer">
          <div className="nl-summary">{summary}</div>
          <button type="button" className="btn ghost" onClick={() => nav(isNew ? '/subscribers/segments' : `/subscribers/segments/${id}`)}>Отмена</button>
          <button className="btn primary" disabled={!canSave}>{save.isPending && <Loader2 size={15} className="spin" />}{isNew ? 'Создать сегмент' : 'Сохранить'}</button>
        </div>
      </form>

      {naming && (
        <Modal title={isNew && !name ? 'Новый сегмент' : 'Название'} onClose={() => (name.trim() ? setNaming(false) : nav('/subscribers/segments'))}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); if (nameDraft.trim()) { setName(nameDraft.trim()); setErrors({}); setNaming(false) } }} noValidate>
            <div className="field">
              <label htmlFor="seg-name">Название</label>
              <input id="seg-name" autoFocus className={`input${errors.name ? ' err' : ''}`} placeholder="Активные за 90 дней" value={nameDraft} onChange={(e) => setNameDraft(e.target.value)} />
              {errors.name ? <div className="hint err">{errors.name}</div> : <div className="hint">Видно в списке сегментов и при выборе получателей рассылки</div>}
            </div>
            <div className="field">
              <label htmlFor="seg-desc">Описание <span className="muted">· необязательно</span></label>
              <input id="seg-desc" className="input" placeholder="Для каких рассылок" value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => (name.trim() ? setNaming(false) : nav('/subscribers/segments'))}>Отмена</button>
              <button className="btn primary" disabled={!nameDraft.trim()}>{isNew && !name ? 'Дальше' : 'Готово'}</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
