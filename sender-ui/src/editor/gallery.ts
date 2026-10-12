// Оформленные шаблоны рассылок для любой компании: яркая шапка (зеро-блок) и обычные блоки
// редактора. Тексты — нейтральные заготовки, которые заменяют на свои; логотип добавляется
// в первый блок, цвета меняются в «Стилях письма».
import { HEROES } from './gallery-heroes'
import {
  DEFAULT_SETTINGS, createBlock, nested, type Block, type ButtonBlock, type ColumnsBlock, type Design, type FooterBlock,
  type HeadingBlock, type HtmlBlock, type ImageBlock, type Settings, type TextBlock, type ZeroBlock,
} from './model'
import { uidZ, zeroHashOf } from './zero'

type Pad = [number, number, number, number]
const pad = ([t, r, b, l]: Pad) => ({ t, r, b, l })

// картинки шапок лежат рядом с интерфейсом (public/templates); в письмо нужен полный адрес
const asset = (file: string) => new URL(`${import.meta.env.BASE_URL}templates/${file}`, window.location.origin).href

const logo = (bg: string): ImageBlock => ({ ...(createBlock('image') as ImageBlock), alt: 'Логотип', full: false, width: 160, align: 'left', radius: 0, bg, padding: pad([24, 40, 16, 40]) })
// шапка — зеро-блок из слоёв gallery-heroes; картинка уже собрана заранее, пока слои не меняли, в письмо уходит она
const cover = (key: keyof typeof HEROES, bg: string): ZeroBlock => {
  const h = HEROES[key]
  const b: ZeroBlock = {
    ...(createBlock('zero') as ZeroBlock), bg, height: h.height, canvasBg: h.bg, canvasBg2: h.bg2, canvasAngle: h.bgAngle,
    layers: h.layers.map((l) => ({ ...l, id: uidZ() })), src: asset(`${key}.png`), meta: `1200×${h.height * 2} · PNG`,
  }
  return { ...b, rendered: zeroHashOf(b) }
}
const eyebrow = (text: string, color: string, bg: string): TextBlock => ({ ...(createBlock('text') as TextBlock), html: `<p><strong>${text}</strong></p>`, size: 12, color, bg, padding: pad([28, 40, 0, 40]) })
const heading = (text: string, o: Partial<HeadingBlock> = {}): HeadingBlock => ({ ...(createBlock('heading') as HeadingBlock), html: `<p>${text}</p>`, padding: pad([8, 40, 8, 40]), ...o })
const text = (html: string, o: Partial<TextBlock> = {}): TextBlock => ({ ...(createBlock('text') as TextBlock), html, ...o })
const button = (label: string, href: string, o: Partial<ButtonBlock> = {}): ButtonBlock => ({ ...(createBlock('button') as ButtonBlock), text: label, href, align: 'left', padding: pad([12, 40, 24, 40]), ...o })
const card = (html: string, bg: string, o: Partial<TextBlock> = {}): TextBlock => text(html, { bg, inset: true, size: 15, padding: pad([18, 24, 18, 24]), ...o })
const columns = (left: Block[], right: Block[], o: Partial<ColumnsBlock> = {}): ColumnsBlock => ({ ...(createBlock('columns') as ColumnsBlock), columns: [left.map(nested), right.map(nested)], ...o })
const footer = (align: 'left' | 'center' = 'left'): FooterBlock => ({
  ...(createBlock('footer') as FooterBlock), align,
  html: '<p>Вы получили это письмо, потому что подписались на нашу рассылку.</p><p>Название компании, город · <a href="{{ unsubscribe_url }}">Отписаться</a></p>',
})
const hello = '<p>Здравствуйте{{#if first_name}}, {{ first_name }}{{/if}}!</p>'
const style = (s: Partial<Settings>): Settings => ({ ...DEFAULT_SETTINGS, ...s, preheader: '' })
const design = (settings: Partial<Settings>, blocks: Block[]): Design => ({ version: 1, settings: style(settings), blocks })

// строка деталей в карточке: первая и последняя с увеличенным отступом
const details = (rows: [string, string][], bg: string): TextBlock[] => rows.map(([l, v], i) =>
  card(`<p><strong>${l}:</strong> ${v}</p>`, bg, { padding: pad([i === 0 ? 18 : 4, 24, i === rows.length - 1 ? 18 : 4, 24]) }))

const rating = (color: string): HtmlBlock => ({
  ...(createBlock('html') as HtmlBlock), padding: pad([4, 40, 20, 40]),
  html: `<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>${[1, 2, 3, 4, 5].map((n) => `<td style="padding:0 8px 0 0"><a href="https://" target="_blank" style="display:inline-block;width:44px;height:44px;line-height:44px;border-radius:10px;border:2px solid ${color};color:${color};font-family:Arial,sans-serif;font-size:16px;font-weight:700;text-align:center;text-decoration:none">${n}</a></td>`).join('')}</tr></table>`,
})

export type GalleryPreset = { key: string; title: string; text: string; group: string; design: () => Design }

export const GALLERY_GROUPS = ['Мероприятия', 'Продукт и продажи', 'Контент', 'Поводы'] as const

export const GALLERY: GalleryPreset[] = [
  {
    key: 'g-event', group: 'Мероприятия', title: 'Анонс мероприятия',
    text: 'Тёмная шапка с яркими кругами, карточка с деталями и программа в двух колонках.',
    design: () => {
      const bg = '#14213D'
      return design({ outerBg: '#E6E9F2', textColor: '#14213D', linkColor: '#3D5AFE', buttonBg: '#FF7A1A', buttonRadius: 999, headingSize: 30, radius: 12 }, [
        logo(bg), cover('event', bg),
        eyebrow('КОНФЕРЕНЦИЯ · 2026', '#FFB37A', bg),
        heading('Название вашего мероприятия', { size: 34, color: '#FFFFFF', bg }),
        text('<p>Одна фраза о главном: для кого мероприятие и что участники унесут с собой.</p>', { color: '#C9D1E6', bg, padding: pad([4, 40, 12, 40]) }),
        button('Зарегистрироваться', '{{ event_url }}', { bg, padding: pad([12, 40, 36, 40]) }),
        text(`${hello}<p>Расскажите, почему стоит прийти: темы, спикеры, формат. Два-три коротких абзаца читаются лучше одного длинного.</p>`, { padding: pad([32, 40, 16, 40]) }),
        ...details([['Дата', '15 ноября, 10:00–18:00'], ['Формат', 'Очно и онлайн-трансляция'], ['Место', 'Город, площадка'], ['Участие', 'Бесплатно по регистрации']], '#F2F4FA'),
        columns(
          [text('<p><strong>День первый</strong></p><p>Темы и спикеры первого дня</p>', { size: 15 })],
          [text('<p><strong>День второй</strong></p><p>Темы и спикеры второго дня</p>', { size: 15 })],
          { padding: pad([20, 40, 8, 40]) },
        ),
        button('Смотреть программу', '{{ event_url }}', { fill: bg }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-webinar', group: 'Мероприятия', title: 'Вебинар',
    text: 'Шапка в стиле прямого эфира, программа по времени и подсказка про запись.',
    design: () => {
      const bg = '#1A1033'
      return design({ outerBg: '#E9E6F5', textColor: '#1A1033', linkColor: '#E5483D', buttonBg: '#FF5A4E', buttonRadius: 999, headingSize: 28, radius: 12 }, [
        logo(bg), cover('webinar', bg),
        eyebrow('ВЕБИНАР · ПРЯМОЙ ЭФИР', '#FF8F86', bg),
        heading('Тема вебинара в одну-две строки', { size: 32, color: '#FFFFFF', bg }),
        text('<p>20 ноября · 19:00 по Москве · 60 минут</p>', { color: '#B9B0E0', bg, padding: pad([4, 40, 12, 40]) }),
        button('Записаться на эфир', '{{ event_url }}', { bg, padding: pad([12, 40, 36, 40]) }),
        text(`${hello}<p>Коротко о том, какую задачу разберём и почему это полезно именно сейчас.</p>`, { padding: pad([32, 40, 16, 40]) }),
        card('<p><strong>19:00</strong> · Вступление и главная мысль</p><p><strong>19:20</strong> · Разбор на примерах</p><p><strong>19:45</strong> · Ответы на вопросы из чата</p>', '#F3F1FA'),
        text('<p><strong>Ведущий:</strong> Имя Фамилия, должность и компания</p>', { padding: pad([20, 40, 8, 40]) }),
        button('Записаться на эфир', '{{ event_url }}'),
        text('<p>Не получится подключиться вживую? Запись пришлём всем зарегистрированным на следующий день.</p>', { size: 14, color: '#5F5A75' }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-workshop', group: 'Мероприятия', title: 'Мастер-класс',
    text: 'Лаймовая шапка с карточками внахлёст, шаги программы и детали участия.',
    design: () => {
      const bg = '#D4F25A'
      return design({ outerBg: '#EEF6CF', textColor: '#111111', linkColor: '#111111', buttonBg: '#111111', buttonRadius: 10, headingSize: 28, radius: 12 }, [
        logo(bg), cover('workshop', bg),
        eyebrow('МАСТЕР-КЛАСС', '#111111', bg),
        heading('Один день практики вместо месяца теории', { size: 32, color: '#111111', bg }),
        text('<p>Небольшая группа, у каждого своё рабочее место и наставник рядом.</p>', { color: '#2B2B2B', bg, padding: pad([4, 40, 12, 40]) }),
        button('Забронировать место', '{{ event_url }}', { color: '#D4F25A', bg, padding: pad([12, 40, 36, 40]) }),
        text(`${hello}<p>Как пройдёт день:</p><ol><li>Знакомство и постановка задачи</li><li>Практика по шагам вместе с наставником</li><li>Самостоятельная работа</li><li>Разбор результатов каждого участника</li></ol>`, { padding: pad([32, 40, 12, 40]) }),
        ...details([['Когда', '15 ноября, 10:00–18:00'], ['Где', 'Город, адрес площадки'], ['Группа', 'до 12 человек'], ['С собой', 'ноутбук и хорошее настроение']], '#F3F9D2'),
        text('<p><strong>Мест немного.</strong> Бронь сохраняется за вами 24 часа после заявки.</p>', { padding: pad([20, 40, 8, 40]) }),
        button('Забронировать место', '{{ event_url }}', { color: '#D4F25A' }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-recap', group: 'Мероприятия', title: 'Итоги мероприятия',
    text: 'Полароиды внахлёст, цифры участия, ссылка на записи и оценка от 1 до 5.',
    design: () => {
      const bg = '#F2F5FF', accent = '#3B51D3'
      return design({ outerBg: '#E1E7FA', textColor: '#0B1640', linkColor: accent, buttonBg: accent, buttonRadius: 999, headingSize: 28, radius: 12 }, [
        logo(bg), cover('recap', bg),
        eyebrow('ИТОГИ', accent, bg),
        heading('Спасибо, что были с нами', { size: 32, bg }),
        text('<p>Собрали записи, презентации и фотографии в одном месте.</p>', { color: '#56608A', bg, padding: pad([4, 40, 32, 40]) }),
        columns(
          [heading('1 200', { size: 36, color: accent, align: 'center', padding: pad([18, 0, 0, 0]), bg: '#F2F5FF' }), text('<p>участников</p>', { align: 'center', color: '#56608A', size: 14, padding: pad([0, 0, 18, 0]), bg: '#F2F5FF' })],
          [heading('36', { size: 36, color: accent, align: 'center', padding: pad([18, 0, 0, 0]), bg: '#F2F5FF' }), text('<p>часов программы</p>', { align: 'center', color: '#56608A', size: 14, padding: pad([0, 0, 18, 0]), bg: '#F2F5FF' })],
          { padding: pad([28, 40, 8, 40]) },
        ),
        text(`${hello}<p>Записи всех выступлений уже доступны. Презентации спикеров выложим до конца недели.</p>`, { padding: pad([16, 40, 8, 40]) }),
        button('Смотреть записи', '{{ event_url }}'),
        text('<p><strong>Как вам мероприятие?</strong> Ответьте одним нажатием, это займёт секунду.</p>', { padding: pad([8, 40, 8, 40]) }),
        rating(accent),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-product', group: 'Продукт и продажи', title: 'Новинка',
    text: 'Глубокий бирюзовый, светящийся шар и плашки с преимуществами продукта.',
    design: () => {
      const bg = '#062A33'
      return design({ outerBg: '#DCEBEE', textColor: '#06222B', linkColor: '#0B8F7C', buttonBg: '#0E8F7C', buttonRadius: 8, headingSize: 26, radius: 10 }, [
        logo('#0E5566'), cover('product', bg),
        eyebrow('НОВИНКА', '#2EE6C5', bg),
        heading('Название продукта и чем он полезен', { size: 32, color: '#FFFFFF', bg }),
        text('<p>Короткое обещание: какую задачу решает и чем лучше прежнего.</p>', { color: '#A9C7CE', bg, padding: pad([4, 40, 12, 40]) }),
        button('Подробнее о новинке', 'https://', { fill: '#2EE6C5', color: '#062A33', bg, padding: pad([12, 40, 36, 40]) }),
        heading('Три причины попробовать', { padding: pad([32, 40, 8, 40]), size: 22 }),
        text('<ul><li><strong>Быстрее.</strong> Что теперь занимает меньше времени.</li><li><strong>Проще.</strong> Какой шаг больше не нужен.</li><li><strong>Надёжнее.</strong> Что стало работать стабильнее.</li></ul>'),
        { ...(createBlock('image') as ImageBlock), alt: 'Фото продукта', radius: 12 },
        ...details([['Цена', 'от 9 900 ₽'], ['Доступно', 'с 1 декабря'], ['Гарантия', '2 года']], '#EEF7F6'),
        button('Заказать демонстрацию', 'https://', { padding: pad([20, 40, 24, 40]) }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-sale', group: 'Продукт и продажи', title: 'Скидка',
    text: 'Крупная скидка, звезда-бейдж и билет, промокод и условия акции.',
    design: () => {
      const bg = '#2A0B3D'
      return design({ outerBg: '#F4DCE8', textColor: '#2A0B3D', linkColor: '#D61F6E', buttonBg: '#FF3D8B', buttonRadius: 999, headingSize: 26, radius: 12 }, [
        logo(bg), cover('sale', bg),
        eyebrow('ТОЛЬКО ДО 30 НОЯБРЯ', '#FFE14D', bg),
        heading('−30% на всё', { size: 52, color: '#FF3D8B', bg }),
        text('<p>Скидка действует на все тарифы при оплате до конца месяца.</p>', { color: '#FFFFFF', bg, padding: pad([4, 40, 12, 40]) }),
        button('Выбрать со скидкой', 'https://', { bg, padding: pad([12, 40, 36, 40]) }),
        heading('Сколько вы сэкономите', { size: 22, padding: pad([32, 40, 8, 40]) }),
        ...details([['Базовый', '8 400 ₽ вместо 12 000 ₽'], ['Расширенный', '12 600 ₽ вместо 18 000 ₽'], ['Максимальный', '20 300 ₽ вместо 29 000 ₽']], '#FFF0F6'),
        card('<p>Промокод при оплате</p><p><strong>SALE30</strong></p>', '#FFE14D', { align: 'center', size: 22, padding: pad([20, 24, 20, 24]) }),
        button('Выбрать со скидкой', 'https://', { align: 'center', padding: pad([20, 40, 12, 40]) }),
        text('<p>Скидка не суммируется с другими акциями. Условия можно изменить под вашу акцию.</p>', { size: 13, color: '#6D5A78', align: 'center' }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-digest', group: 'Контент', title: 'Дайджест',
    text: 'Журнальная шапка, три материала с рубриками и ссылками «Читать».',
    design: () => {
      const bg = '#E7EDFB', accent = '#2F4BFF'
      const item = (tag: string, title: string): Block[] => [
        text(`<p><strong>${tag}</strong></p>`, { size: 12, color: accent, padding: pad([24, 40, 0, 40]) }),
        heading(title, { size: 22, padding: pad([6, 40, 6, 40]) }),
        text('<p>Два предложения о сути материала и о том, почему его стоит открыть. <a href="https://">Читать →</a></p>', { padding: pad([0, 40, 20, 40]) }),
        createBlock('divider'),
      ]
      return design({ outerBg: '#DCE3F4', font: 'Georgia, Times, serif', headingFont: 'Georgia, Times, serif', textColor: '#101935', linkColor: accent, buttonBg: accent, buttonRadius: 6, headingSize: 26, radius: 4 }, [
        logo(bg), cover('digest', bg),
        eyebrow('ДАЙДЖЕСТ · ВЫПУСК № 1', accent, bg),
        heading('Главное за месяц', { size: 40, bg }),
        text('<p>Пять минут чтения: новости, полезные материалы и анонсы.</p>', { color: '#5A6385', bg, padding: pad([4, 40, 28, 40]) }),
        ...item('НОВОСТИ', 'Заголовок первого материала'),
        ...item('ПОЛЕЗНОЕ', 'Заголовок второго материала'),
        ...item('АНОНС', 'Заголовок третьего материала'),
        button('Все материалы выпуска', 'https://', { padding: pad([24, 40, 24, 40]) }),
        footer(),
      ])
    },
  },
  {
    key: 'g-interview', group: 'Контент', title: 'Интервью',
    text: 'Оранжевая шапка с растром и большими кавычками, цитата и вопросы с ответами.',
    design: () => {
      const bg = '#FF7A1A'
      return design({ outerBg: '#F5E2D2', headingFont: 'Georgia, Times, serif', textColor: '#1D1D1B', linkColor: '#C25500', buttonBg: '#1D1D1B', buttonRadius: 999, headingSize: 24, radius: 12 }, [
        logo(bg), cover('interview', bg),
        eyebrow('ИНТЕРВЬЮ', '#1D1D1B', bg),
        heading('«Цитата героя, ради которой хочется прочитать всё интервью»', { size: 30, color: '#1D1D1B', bg }),
        text('<p><strong>Имя Фамилия</strong>, должность и компания</p>', { color: '#3A2412', bg, padding: pad([4, 40, 32, 40]) }),
        text('<p>Короткое вступление: кто герой, почему мы с ним поговорили и что вы узнаете из интервью.</p>', { padding: pad([32, 40, 12, 40]) }),
        card('<p><strong>Первый вопрос, который волнует читателей?</strong></p><p>Ответ героя в двух-трёх предложениях. Живая речь работает лучше пересказа.</p>', '#FFF3EA'),
        { ...(createBlock('spacer')), height: 12 } as Block,
        card('<p><strong>Второй вопрос, неожиданный и честный?</strong></p><p>Ещё один ответ, после которого хочется дочитать до конца.</p>', '#FFF3EA'),
        button('Читать интервью целиком', 'https://', { padding: pad([24, 40, 24, 40]) }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-poll', group: 'Контент', title: 'Опрос',
    text: 'Один вопрос и варианты ответа кнопками: подписчик отвечает одним нажатием.',
    design: () => {
      const bg = '#3B4BFF'
      const option = (label: string) => button(label, 'https://', { fill: '#EEF0FF', color: '#1B1F4D', full: true, radius: 12, padding: pad([5, 40, 5, 40]) })
      return design({ outerBg: '#DFE2FF', textColor: '#1B1F4D', linkColor: bg, buttonBg: bg, buttonRadius: 12, headingSize: 24, radius: 12 }, [
        logo(bg), cover('poll', bg),
        eyebrow('ОПРОС · 1 МИНУТА', '#FFE14D', bg),
        heading('Помогите нам стать лучше', { size: 32, color: '#FFFFFF', bg }),
        text('<p>Один вопрос, ответ в одно нажатие.</p>', { color: '#D9DCFF', bg, padding: pad([4, 40, 32, 40]) }),
        heading('Что для вас важнее всего?', { size: 22, padding: pad([32, 40, 12, 40]) }),
        option('А. Первый вариант ответа'), option('Б. Второй вариант ответа'), option('В. Третий вариант ответа'), option('Г. Свой вариант'),
        text('<p>Каждая кнопка ведёт на свою ссылку: так вы увидите, сколько людей выбрали каждый вариант.</p>', { size: 13, color: '#5B5F86', padding: pad([16, 40, 20, 40]) }),
        createBlock('divider'), footer(),
      ])
    },
  },
  {
    key: 'g-holiday', group: 'Поводы', title: 'Поздравление',
    text: 'Конфетти, шарики и подарок, тёплый текст и промокод в подарок.',
    design: () => {
      const bg = '#0F3D2E'
      return design({ outerBg: '#D8E8DF', headingFont: 'Georgia, Times, serif', textColor: '#0F3D2E', linkColor: '#1C6B4F', buttonBg: bg, buttonRadius: 999, headingSize: 30, radius: 14 }, [
        logo(bg), cover('holiday', '#FFFFFF'),
        heading('С праздником!', { size: 40, align: 'center', padding: pad([8, 40, 8, 40]) }),
        text('<p>Пусть этот день будет тёплым, а год удачным. Спасибо, что вы с нами.</p>', { align: 'center', padding: pad([4, 40, 20, 40]) }),
        card('<p>Наш подарок для вас</p><p><strong>GIFT2026</strong></p><p>Скидка 15% на любую покупку до 31 декабря</p>', '#EEF6F1', { align: 'center', padding: pad([22, 24, 22, 24]) }),
        button('Выбрать подарок', 'https://', { align: 'center', color: '#FFE7A8', padding: pad([24, 40, 28, 40]) }),
        createBlock('divider'), footer('center'),
      ])
    },
  },
]
