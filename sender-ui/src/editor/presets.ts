// Готовые макеты писем: с них можно начать новое письмо в редакторе.
import { DEFAULT_SETTINGS, createBlock, starterDesign, type Block, type ButtonBlock, type Design, type FooterBlock, type HeadingBlock, type ImageBlock, type SpacerBlock, type TextBlock } from './model'

const logo = (): ImageBlock => ({ ...(createBlock('image') as ImageBlock), alt: 'Логотип', full: false, width: 180, align: 'left', radius: 0, padding: { t: 28, r: 40, b: 8, l: 40 } })
const heading = (text: string): HeadingBlock => ({ ...(createBlock('heading') as HeadingBlock), html: `<p>${text}</p>`, padding: { t: 16, r: 40, b: 8, l: 40 } })
const text = (html: string, extra: Partial<TextBlock> = {}): TextBlock => ({ ...(createBlock('text') as TextBlock), html, ...extra })
const button = (label: string, href: string): ButtonBlock => ({ ...(createBlock('button') as ButtonBlock), text: label, href, padding: { t: 16, r: 40, b: 24, l: 40 } })
const footer = (): FooterBlock => ({
  ...(createBlock('footer') as FooterBlock),
  html: '<p>Вы получили это письмо, потому что зарегистрировались на нашем сайте.</p><p>Если письмо пришло по ошибке, просто удалите его.</p>',
})

// строка в карточке с деталями: серый фон, показывается только при заполненной переменной
const detail = (label: string, value: string, cond: string, first = false, last = false): TextBlock =>
  text(`<p><strong>${label}:</strong> ${value}</p>`, {
    cond, bg: '#F4F6FA', inset: true, size: 15,
    padding: { t: first ? 18 : 4, r: 24, b: last ? 18 : 4, l: 24 },
  })


const credentials = (title: string): Block[] => [
  { ...text(`<p><strong>${title}</strong></p><p>Логин: {{ user_email }}<br>Пароль: {{ password }}</p><p>Пароль можно сменить в личном кабинете.</p>`, { bg: '#FFF6EC', inset: true, size: 15, cond: 'password' }), padding: { t: 18, r: 24, b: 18, l: 24 } },
]

export type Preset = { key: string; title: string; text: string; design: () => Design }

export const PRESETS: Preset[] = [
  {
    key: 'event',
    title: 'Регистрация на мероприятие',
    text: 'Приветствие, карточка с датой, форматом и местом, данные для входа новым участникам и кнопка.',
    design: () => ({
      version: 1,
      settings: { ...DEFAULT_SETTINGS, preheader: '' },
      blocks: [
        logo(),
        heading('Вы зарегистрированы'),
        text('<p>Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!</p><p>Вы в списке участников мероприятия «{{ event_title }}». Ждём вас!</p>'),
        ...([
          detail('Дата', '{{ start_date }}{{#if start_time}} в {{ start_time }}{{/if}}', 'start_date', true),
          detail('Формат', '{{ event_format }}', 'event_format'),
          detail('Тип', '{{ event_type }}', 'event_type'),
          detail('Место', '{{ event_location }}', 'event_location'),
          detail('Стоимость', '{{ price }}', 'price'),
          detail('Спикеры', '{{ speakers }}', 'speakers', false, true),
        ]),
        { ...(createBlock('spacer') as SpacerBlock), height: 12, cond: 'password' },
        ...credentials('Мы создали для вас учётную запись'),
        button('Страница мероприятия', '{{ event_url }}'),
        createBlock('divider'),
        footer(),
      ],
    }),
  },
  {
    key: 'password',
    title: 'Новый пароль',
    text: 'Короткое письмо с логином и новым паролем и кнопкой входа.',
    design: () => ({
      version: 1,
      settings: { ...DEFAULT_SETTINGS },
      blocks: [
        logo(),
        heading('Новый пароль для входа'),
        text('<p>Здравствуйте{{#if name}}, {{ name }}{{/if}}!</p><p>Для вашей учётной записи создан новый пароль.</p>'),
        { ...text('<p>Логин: {{ user_email }}<br>Пароль: <strong>{{ password }}</strong></p>', { bg: '#F4F6FA', inset: true, size: 15 }), padding: { t: 18, r: 24, b: 18, l: 24 } },
        button('Войти на сайт', 'https://'),
        text('<p>Если вы не запрашивали новый пароль, напишите нам: возможно, кто-то пытался войти в ваш аккаунт.</p>', { size: 14, color: '#4A505B' }),
        createBlock('divider'),
        footer(),
      ],
    }),
  },
  {
    key: 'account',
    title: 'Учётная запись создана',
    text: 'Приветствие нового пользователя с данными для входа.',
    design: () => ({
      version: 1,
      settings: { ...DEFAULT_SETTINGS },
      blocks: [
        logo(),
        heading('Добро пожаловать!'),
        text('<p>Здравствуйте{{#if name}}, {{ name }}{{/if}}!</p><p>Для вас создана учётная запись. Сохраните данные для входа:</p>'),
        { ...text('<p>Логин: {{ user_email }}<br>Пароль: <strong>{{ password }}</strong></p>', { bg: '#F4F6FA', inset: true, size: 15 }), padding: { t: 18, r: 24, b: 18, l: 24 } },
        button('Войти на сайт', 'https://'),
        createBlock('divider'),
        footer(),
      ],
    }),
  },
  {
    key: 'simple',
    title: 'Простое письмо',
    text: 'Заголовок, текст, кнопка и подвал.',
    design: starterDesign,
  },
]
