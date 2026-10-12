// Видеоинструкции лежат на сервере сайта, в git их нет. Адрес абсолютный: кабинет открывается на другом поддомене.
// У каждого ролика рядом обложка с тем же именем: <slug>.jpg
export const VIDEO_BASE = 'https://mag-expert.ru/storage/sender-guides/'

export type Video = { slug: string; title: string; description: string; duration: string }

export const VIDEOS: Video[] = [
  { slug: 'sender-overview', title: 'Обзор сервиса', duration: '1:55', description: 'Из чего состоит Sender и в каком порядке всё настраивать: домен, база, письмо, рассылка.' },
  { slug: 'login-and-dashboard', title: 'Вход и обзор', duration: '1:17', description: 'Как войти в кабинет и что показывает главная страница: отправки, доставка, ошибки за неделю.' },
  { slug: 'connect-domain', title: 'Домены и адреса отправителей', duration: '1:28', description: 'Добавить домен, прописать DNS-записи, дождаться проверки и завести адрес отправителя.' },
  { slug: 'subscriber-lists', title: 'Базы подписчиков', duration: '1:40', description: 'Создать базу, вставить адреса списком или загрузить CSV, проверить адреса перед отправкой.' },
  { slug: 'email-editor', title: 'Письма и редактор блоков', duration: '2:02', description: 'Собрать письмо из блоков, подставить переменные, разложить письма по папкам.' },
  { slug: 'first-campaign', title: 'Новая рассылка', duration: '1:29', description: 'Выбрать письмо и базу, проверить, отправить сразу или запланировать на время.' },
  { slug: 'reports-and-log', title: 'Отчёты и журнал', duration: '1:38', description: 'Итоги рассылки и журнал: что доставлено, открыто, не дошло, и где искать конкретное письмо.' },
  { slug: 'team-and-api', title: 'Команда, блокировки и API', duration: '1:13', description: 'Пригласить коллег с нужной ролью, управлять блокировками и подключить приложение по API-ключу.' },
]

export const videoSrc = (slug: string) => `${VIDEO_BASE}${slug}.mp4`
export const posterSrc = (slug: string) => `${VIDEO_BASE}${slug}.jpg`
export const findVideo = (slug: string) => VIDEOS.find((v) => v.slug === slug)
