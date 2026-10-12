import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Pencil } from 'lucide-react'
import { api, ApiError, upload, type ContactList, type ImportResult, type ListSource } from '../api'
import { BackLink, Modal, PageHead, useToast } from '../components/ui'
import { usePermissions } from '../auth'
import { ColumnMapping, ConsentCheckbox, ImportSource, useImportDraft } from '../components/ImportMapping'

const n = (v: number) => v.toLocaleString('ru-RU')

/** Новая база: название спрашивается во всплывающем окне, на странице — источник, адреса и сопоставление колонок. */
export default function NewList() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { canEdit } = usePermissions()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [source, setSource] = useState('')
  const [nameErr, setNameErr] = useState<string | null>(null)
  // окно с названием открыто сразу при переходе на страницу
  const [naming, setNaming] = useState(true)
  const [showDesc, setShowDesc] = useState(false)
  const [requireConfirmation, setRequireConfirmation] = useState(false)
  // согласие по умолчанию: есть, если база не требует подтверждения; после ручной отметки живёт само по себе
  const draft = useImportDraft(!requireConfirmation)
  const [importErr, setImportErr] = useState<string | null>(null)
  // если база создалась, а загрузка не прошла, повтор загружает в неё же
  const [created, setCreated] = useState<ContactList | null>(null)
  const sq = useQuery({ queryKey: ['list-sources'], queryFn: () => api<{ data: ListSource[] }>('/list-sources') })
  const sources = sq.data?.data ?? []

  const submit = useMutation({
    mutationFn: async () => {
      const list = created ?? (await api<{ data: ContactList }>('/lists', { method: 'POST', body: { name, description: description || null, source: source || null, require_confirmation: requireConfirmation } })).data
      setCreated(list)
      if (source || !draft.hasInput || !draft.mapping) return { list, result: null }
      const r = await upload<{ data: ImportResult }>(`/lists/${list.id}/import`, draft.body(draft.mapping))
      return { list, result: r.data }
    },
    onSuccess: ({ list, result }) => {
      qc.invalidateQueries({ queryKey: ['lists'] })
      toast(result ? `База создана: новых адресов ${n(result.added)}${result.pending ? `, ждут подтверждения ${n(result.pending)}` : ''}${result.skipped ? `, без адреса ${n(result.skipped)}` : ''}` : list.source ? `База создана: ${n(list.contacts_count)} адресов` : 'База создана')
      nav(`/subscribers/${list.id}`)
    },
    onError: (e) => {
      if (!(e instanceof ApiError)) { setImportErr('Ошибка'); return }
      // ошибка названия — в окне с названием, остальные (источник, загрузка) — внизу страницы
      if (e.errors.name) { setNameErr(e.errors.name[0]); setNaming(true) }
      const other = Object.entries(e.errors).find(([k]) => k !== 'name')?.[1]?.[0]
      if (other || !e.errors.name) setImportErr(other ?? e.message)
    },
  })

  const ready = name.trim() && (source || !draft.hasInput || draft.ready)
  const label = submit.isPending ? (draft.hasInput && !source ? 'Загружаем…' : 'Создаём…')
    : source ? 'Создать базу'
    : draft.hasInput ? (draft.rows ? `Создать базу и загрузить ${n(draft.rows)} ${draft.rows === 1 ? 'строку' : 'строк'}` : 'Создать базу и загрузить')
    : 'Создать пустую базу'

  const emailCol = draft.columns[draft.choices.findIndex((c) => c.role === 'email')]
  const varCount = draft.choices.filter((c) => c.role === 'field').length

  if (!canEdit) return <Navigate to="/subscribers" replace />

  return (
    <main className="page nl-page">
      <BackLink to="/subscribers">Все базы</BackLink>
      <PageHead title={name.trim() || 'Новая база'} sub={description.trim() || 'Новая база подписчиков'}
        actions={!created && <button type="button" className="btn" onClick={() => setNaming(true)}><Pencil size={15} />Изменить название</button>} />

      <form className="nl-form" onSubmit={(e: FormEvent) => { e.preventDefault(); setNameErr(null); setImportErr(null); submit.mutate() }} noValidate>
        <section className="nl-block">
          <div className="nl-head"><h2>Адреса</h2><p>Вставьте списком или загрузите CSV из Excel. Можно пропустить и добавить потом.</p></div>
          {source ? (
            <p className="sub">База повторяет список пользователей сайта с согласием на рассылку и обновляется сама перед каждой рассылкой. <button type="button" className="btn sm text" style={{ padding: 0, height: 'auto' }} onClick={() => setSource('')}>Загрузить свои адреса</button></p>
          ) : (
            <ImportSource draft={draft} between={<ConsentCheckbox draft={draft} />} />
          )}
        </section>

        {!source && draft.hasInput && (
          <section className="nl-block">
            <ColumnMapping draft={draft} />
          </section>
        )}

        <div className="nl-footer">
          <div className="nl-summary">
            {importErr ? <span className="hint err">{importErr}</span>
              : created ? <span className="hint">База «{created.name}» уже создана, повтор загрузит адреса в неё.</span>
              : source ? <span className="sub">Адреса подтянутся автоматически</span>
              : draft.ready ? <span className="sub">{n(draft.rows)} {draft.rows === 1 ? 'строка' : 'строк'} · адрес из колонки «{emailCol && ((draft.namesFromFirstRow ? draft.preview.data?.data.sample_rows[0]?.[emailCol.index] : draft.header ? emailCol.header : '') || `Колонка ${emailCol.index + 1}`)}»{varCount ? ` · переменных: ${varCount}` : ''} · {draft.consent ? 'согласие есть' : 'новым адресам придёт письмо с подтверждением'}</span>
              : draft.hasInput ? <span className="sub">{draft.settled && !draft.preview.isFetching && draft.error ? draft.error : 'Читаем колонки…'}</span>
              : <span className="sub">Без адресов база создастся пустой</span>}
          </div>
          <button type="button" className="btn ghost" onClick={() => nav(created ? `/subscribers/${created.id}` : '/subscribers')}>{created ? 'К базе' : 'Отмена'}</button>
          <button className="btn primary" disabled={!ready || submit.isPending}>{submit.isPending && <Loader2 size={15} className="spin" />}{label}</button>
        </div>
      </form>

      {naming && (
        <Modal title="Новая база" onClose={() => (name.trim() ? setNaming(false) : nav('/subscribers'))}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); if (name.trim()) { setNameErr(null); setNaming(false) } }} noValidate>
            <div className="field">
              <label htmlFor="list-name">Название</label>
              <input id="list-name" autoFocus className={`input${nameErr ? ' err' : ''}`} placeholder="Подписчики блога" value={name} onChange={(e) => { setName(e.target.value); setNameErr(null) }} />
              {nameErr ? <div className="hint err">{nameErr}</div> : <div className="hint">Видно в списке баз и при выборе получателей рассылки</div>}
            </div>
            {showDesc || description ? (
              <div className="field">
                <label htmlFor="list-desc">Описание <span className="muted">· необязательно</span></label>
                <input id="list-desc" className="input" placeholder="Откуда адреса и для каких рассылок" value={description} onChange={(e) => setDescription(e.target.value)} />
              </div>
            ) : (
              <button type="button" className="btn sm text" style={{ alignSelf: 'flex-start', paddingLeft: 0 }} onClick={() => setShowDesc(true)}>+ Добавить описание</button>
            )}
            {!source && (
              <label className="check-row nl-consent">
                <input type="checkbox" checked={requireConfirmation} onChange={(e) => setRequireConfirmation(e.target.checked)} />
                <span>Требовать подтверждение подписки</span>
              </label>
            )}
            {sources.map((s) => (
              <label key={s.key} className="check-row nl-consent">
                <input type="checkbox" checked={source === s.key} onChange={(e) => setSource(e.target.checked ? s.key : '')} />
                <span>Собрать из списка «{s.label}»</span>
              </label>
            ))}
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => (name.trim() ? setNaming(false) : nav('/subscribers'))}>Отмена</button>
              <button className="btn primary" disabled={!name.trim()}>Дальше</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
