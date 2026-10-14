import { ExternalLink } from 'lucide-react'
import { Link } from 'react-router-dom'
import { CopyField } from '../components/ui'

/** Документация публичного API: спецификация OpenAPI и страницы для чтения. Ключ не нужен, страницы открыты. */
export default function SettingsApiDocs() {
  const base = `${window.location.origin}/api/sender/v1`
  const items = [
    { title: 'Swagger UI', text: 'Справка, где запросы можно пробовать прямо на странице. Ключ вставляется кнопкой Authorize.', href: `${base}/docs/swagger` },
    { title: 'Redoc', text: 'Читаемая справка по всем методам, схемам ответов и ошибкам.', href: `${base}/docs/redoc` },
  ]

  return (
    <div className="stack" style={{ gap: 24 }}>
      <div>
        <div className="section-title" style={{ marginTop: 0 }}>Документация API</div>
        <p className="hint" style={{ fontSize: 14, maxWidth: 640 }}>
          Публичный API Sender описан в формате OpenAPI 3.1: отправка писем, согласие на рассылку, базы и подписчики, теги, события-вебхуки и формат ошибок.
          По этому описанию приложение подключается к Sender; примеры отправки письма есть в разделе <Link to="/api">«API»</Link>, ключи выпускаются в разделе <Link to="/api-keys">«API-ключи»</Link>.
        </p>
      </div>

      <div className="stack" style={{ gap: 12 }}>
        {items.map((item) => (
          <div key={item.title} className="api-endpoint">
            <div className="grow" style={{ minWidth: 0 }}>
              <b>{item.title}</b>
              <div className="hint" style={{ fontSize: 14 }}>{item.text}</div>
            </div>
            <a className="btn" href={item.href} target="_blank" rel="noreferrer"><ExternalLink size={16} /> Открыть</a>
          </div>
        ))}
      </div>

      <div>
        <div className="section-title">Спецификация OpenAPI</div>
        <p className="hint" style={{ fontSize: 14, maxWidth: 640 }}>
          Файл можно загрузить в Postman, Insomnia или генератор клиентов. Он всегда соответствует работающему API: тесты сверяют их при каждой сборке.
        </p>
        <CopyField value={`${base}/openapi.yaml`} />
      </div>
    </div>
  )
}
