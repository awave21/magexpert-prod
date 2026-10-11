export function AuthAside() {
  const rows = [
    { tone: 'ok', label: 'Отправлено', to: 'anna@example.com', what: 'Вы зарегистрированы: Вебинар', t: '2 мин' },
    { tone: 'warn', label: 'В очереди', to: 'boris@example.com', what: 'Новый пароль для входа', t: '5 мин' },
    { tone: 'err', label: 'Ошибка', to: 'kira@example.com', what: 'SMTP недоступен, 3 попытки', t: '14 мин' },
  ]
  return (
    <section className="auth-aside" aria-hidden="true">
      <div>
        <h2>Каждое письмо <em>под контролем</em></h2>
        <p style={{ marginTop: 16 }}>Подключайте свои домены, собирайте письма, загружайте базы подписчиков и смотрите, что произошло с каждым письмом: отправлено, ждёт в очереди или не дошло и почему.</p>
      </div>
      <div style={{ maxWidth: 540 }}>
        <div className="row" style={{ justifyContent: 'space-between', paddingBottom: 12, borderBottom: '1px solid var(--accent-soft-2)' }}>
          <span className="nav-title" style={{ padding: 0 }}>Журнал отправки</span>
        </div>
        {rows.map((r) => (
          <div key={r.to} className="row" style={{ padding: '14px 0', borderBottom: '1px solid var(--accent-soft-2)', flexWrap: 'nowrap' }}>
            <span className={`status ${r.tone}`} style={{ flex: '0 0 108px' }}><i />{r.label}</span>
            <div style={{ flex: 1, minWidth: 0 }}><div className="mono">{r.to}</div><div className="sub" style={{ color: 'var(--ink-2)', fontSize: 13 }}>{r.what}</div></div>
            <span className="sub">{r.t}</span>
          </div>
        ))}
      </div>
    </section>
  )
}
