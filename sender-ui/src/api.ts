const TOKEN_KEY = 'sender.token'

export const tokenStore = {
  get: () => {
    try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set: (t: string) => {
    try { localStorage.setItem(TOKEN_KEY, t) } catch { /* без хранилища токен живёт до перезагрузки */ }
  },
  clear: () => {
    try { localStorage.removeItem(TOKEN_KEY) } catch { /* ничего */ }
  },
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

let onUnauthorized: () => void = () => {}
export const setUnauthorizedHandler = (fn: () => void) => { onUnauthorized = fn }

const BASE = '/api/sender/v1/admin'

export async function api<T>(path: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
  const token = tokenStore.get()
  const res = await fetch(BASE + path, {
    method: init.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(init.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && path !== '/login') onUnauthorized()
    throw new ApiError(res.status, data.message ?? 'Не удалось выполнить запрос', data.errors ?? {})
  }
  return data as T
}

export type User = { id: number; name: string; email: string; organization: string | null }
export type DnsRecord = { key: 'verification' | 'dkim' | 'spf' | 'dmarc'; type: string; host: string; value: string; required: boolean }
export type Domain = {
  id: number; domain: string; status: 'pending' | 'verified' | 'failed'; dkim_selector: string
  verified_at: string | null; last_checked_at: string | null; created_at: string
  dns_records?: DnsRecord[]
}
export type Template = { id: number; folder_id: number | null; slug: string; name: string; subject: string; sender_address_id: number | null; reply_to: string | null; preheader: string | null; body_html: string; body_text: string | null; editor: 'html' | 'blocks'; design: import('./editor/model').Design | null; updated_at: string }
export type Message = {
  id: string; status: 'queued' | 'sending' | 'sent' | 'failed' | 'blocked'; to: string; from: string; subject: string
  template: string | null; attempts: number; error: string | null; sent_at: string | null; created_at: string
  variables?: Record<string, unknown> | null
}
export type ApiKey = { id: number; name: string; key_prefix: string; last_used_at: string | null; revoked_at: string | null; created_at: string }
export type Suppression = { id: number; email: string; reason: 'bounce' | 'complaint' | 'unsubscribe' | 'manual'; created_at: string }
export type Folder = { id: number; name: string; templates_count: number }
export type BaseVariable = { key: string; label: string; description: string; sample: string }
export type CustomVariable = { id: number; key: string; label: string; default_value: string | null }
export type Variables = { base: BaseVariable[]; custom: CustomVariable[] }
export type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } }
export type VerifyReport = Record<'verification' | 'dkim' | 'spf' | 'dmarc', boolean>
export type Stats = {
  totals: { sent: number; queued: number; failed: number; blocked: number }
  days: { date: string; label: string; sent: number; failed: number }[]
  unverified_domains: number
  recent: Message[]
}

export type SenderAddress = { id: number; email: string; name: string; domain: string | null; verified: boolean; confirmed: boolean; confirmation_sent_at: string | null }

export type CheckStatus = 'ok' | 'role' | 'typo' | 'disposable' | 'no_mx' | 'invalid'
export type ContactList = {
  id: number; name: string; description: string | null; contacts_count: number; subscribed_count: number; deliverable_count: number
  checks: Record<CheckStatus | 'unchecked', number>; created_at: string; updated_at: string
}
export type Contact = { id: number; email: string; name: string | null; data: Record<string, string>; unsubscribed_at: string | null; check_status: CheckStatus | null; check_hint: string | null; created_at: string }
export type ImportResult = {
  rows: number; added: number; updated: number; duplicates: number; skipped: number; columns: string[]
  skipped_rows: { line: number; value: string }[]; duplicate_rows: { line: number; email: string; first_line: number }[]
}
export type CampaignStats = { queued: number; sent: number; failed: number; blocked: number }
export type Campaign = {
  id: number; name: string; status: 'draft' | 'sending' | 'sent'
  template: { id: number; name: string } | null; list: { id: number; name: string } | null
  recipients_count: number; stats: CampaignStats | null; subscribed_count: number | null
  started_at: string | null; finished_at: string | null; created_at: string
}

// загрузка файла: api() отправляет только JSON
export async function upload<T>(path: string, body: FormData): Promise<T> {
  const token = tokenStore.get()
  const res = await fetch(BASE + path, { method: 'POST', headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401) onUnauthorized()
    throw new ApiError(res.status, data.message ?? 'Не удалось загрузить файл', data.errors ?? {})
  }
  return data as T
}
