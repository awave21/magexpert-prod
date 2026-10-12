import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '../api'
import { CopyField, Modal, ago, useToast } from '../components/ui'
import { usePermissions } from '../auth'

type McpToken = { id: number; name: string; last_used_at: string | null; created_at: string }

/** Подключение ИИ (Claude, ChatGPT, Cursor) к Sender по MCP: личные ключи и готовые команды. */
export default function SettingsAi() {
  const qc = useQueryClient()
  const toast = useToast()
  const { canEditContent } = usePermissions()
  const q = useQuery({ queryKey: ['mcp-tokens'], queryFn: () => api<{ data: McpToken[]; url: string }>('/mcp-tokens') })
  const tokens = q.data?.data ?? []
  const url = q.data?.url ?? ''

  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [created, setCreated] = useState<{ token: string; url: string } | null>(null)
  const create = useMutation({
    mutationFn: () => api<{ data: McpToken; token: string; url: string }>('/mcp-tokens', { method: 'POST', body: { name } }),
    onSuccess: (r) => { setCreating(false); setName(''); setCreated({ token: r.token, url: r.url }); qc.invalidateQueries({ queryKey: ['mcp-tokens'] }) },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const revoke = useMutation({
    mutationFn: (t: McpToken) => api(`/mcp-tokens/${t.id}`, { method: 'DELETE' }),
    onSuccess: () => { toast('Ключ отозван'); qc.invalidateQueries({ queryKey: ['mcp-tokens'] }) },
  })

  const claudeCode = (token: string, u: string) => `claude mcp add --transport http sender ${u} --header "Authorization: Bearer ${token}"`

  return (
    <div className="stack" style={{ gap: 24 }}>
      <div>
        <div className="section-title" style={{ marginTop: 0 }}>Подключение ИИ</div>
        <p className="hint" style={{ fontSize: 14, maxWidth: 640 }}>
          Подключите Sender к Claude, ChatGPT или Cursor — и попросите обычным текстом: «Сделай письмо-анонс конференции по кардиологии, кнопка „Зарегистрироваться“, обращение по имени». ИИ соберёт письмо в «Контенте», проверит его и пришлёт вам тест.
          {canEditContent ? ' Права ИИ совпадают с вашими.' : ' С вашей ролью ИИ сможет только смотреть письма.'}
        </p>
      </div>

      <div className="field" style={{ maxWidth: 640 }}>
        <label>Адрес сервера</label>
        {url ? <CopyField value={url} /> : <div className="hint">…</div>}
      </div>

      <div>
        <div className="row" style={{ justifyContent: 'space-between', marginBottom: 10 }}>
          <b style={{ fontWeight: 600 }}>Ваши ключи</b>
          <button className="btn primary sm" onClick={() => { setErr(null); setName(''); setCreating(true) }}><Plus size={15} />Новый ключ</button>
        </div>
        {q.isSuccess && tokens.length === 0 ? (
          <p className="hint">Ключей пока нет. Создайте ключ и вставьте его в настройки ИИ.</p>
        ) : (
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Название</th><th>Создан</th><th>Последнее обращение</th><th /></tr></thead>
              <tbody>
                {tokens.map((t) => (
                  <tr key={t.id}>
                    <td><span className="row" style={{ gap: 8 }}><KeyRound size={15} className="muted" />{t.name}</span></td>
                    <td className="sub">{ago(t.created_at)}</td>
                    <td className="sub">{t.last_used_at ? ago(t.last_used_at) : 'ещё не было'}</td>
                    <td className="r"><button className="btn sm ghost" disabled={revoke.isPending} onClick={() => revoke.mutate(t)}><Trash2 size={14} />Отозвать</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <details className="ai-help">
        <summary>Как подключить</summary>
        <ol>
          <li><b>Claude Code</b> — выполните в терминале команду, которую покажем после создания ключа.</li>
          <li><b>Claude Desktop, Cursor и другие</b> — добавьте MCP-сервер по адресу выше с заголовком <span className="mono">Authorization: Bearer ключ</span>.</li>
          <li>Подключение в один клик через «Коннекторы» на claude.ai появится позже.</li>
        </ol>
      </details>

      {creating && (
        <Modal title="Новый ключ для ИИ" onClose={() => setCreating(false)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); create.mutate() }} noValidate>
            <div className="field">
              <label htmlFor="mcp-name">Название</label>
              <input id="mcp-name" autoFocus className={`input${err ? ' err' : ''}`} placeholder="Claude на ноутбуке" value={name} onChange={(e) => setName(e.target.value)} />
              {err ? <div className="hint err">{err}</div> : <div className="hint">Чтобы потом понять, какой ключ где используется</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setCreating(false)}>Отмена</button>
              <button className="btn primary" disabled={!name.trim() || create.isPending}>Создать</button>
            </div>
          </form>
        </Modal>
      )}

      {created && (
        <Modal title="Ключ создан" onClose={() => setCreated(null)}>
          <p style={{ margin: 0, color: 'var(--ink-2)' }}>Скопируйте ключ сейчас: больше он нигде не показывается.</p>
          <div className="field"><label>Ключ</label><CopyField value={created.token} /></div>
          <div className="field"><label>Команда для Claude Code</label><CopyField value={claudeCode(created.token, created.url)} /></div>
          <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" onClick={() => setCreated(null)}>Готово</button></div>
        </Modal>
      )}
    </div>
  )
}
