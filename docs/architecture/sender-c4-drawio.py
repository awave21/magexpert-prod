import html, sys
from xml.sax.saxutils import quoteattr

FONT = "fontFamily=Helvetica;"
S = {
 'person': "html=1;fontSize=11;dashed=0;whiteSpace=wrap;fillColor=#083F75;strokeColor=#06315C;fontColor=#ffffff;shape=mxgraph.c4.person2;align=center;metaEdit=1;resizable=0;"+FONT,
 'system': "rounded=1;whiteSpace=wrap;html=1;labelBackgroundColor=none;fillColor=#1061B0;fontColor=#ffffff;align=center;arcSize=10;strokeColor=#0D5091;metaEdit=1;resizable=0;"+FONT,
 'ext': "rounded=1;whiteSpace=wrap;html=1;labelBackgroundColor=none;fillColor=#8C8496;fontColor=#ffffff;align=center;arcSize=10;strokeColor=#736782;metaEdit=1;resizable=0;"+FONT,
 'container': "rounded=1;whiteSpace=wrap;html=1;fontSize=11;labelBackgroundColor=none;fillColor=#23A2D9;fontColor=#ffffff;align=center;arcSize=10;strokeColor=#0E7DAD;metaEdit=1;resizable=0;"+FONT,
 'ext_container': "rounded=1;whiteSpace=wrap;html=1;fontSize=11;labelBackgroundColor=none;fillColor=#8C8496;fontColor=#ffffff;align=center;arcSize=10;strokeColor=#736782;metaEdit=1;resizable=0;"+FONT,
 'db': "shape=cylinder3;size=15;whiteSpace=wrap;html=1;boundedLbl=1;rounded=0;labelBackgroundColor=none;fillColor=#23A2D9;fontSize=12;fontColor=#ffffff;align=center;strokeColor=#0E7DAD;metaEdit=1;resizable=0;"+FONT,
 'queue': "shape=cylinder3;size=15;whiteSpace=wrap;html=1;boundedLbl=1;rounded=0;labelBackgroundColor=none;fillColor=#23A2D9;fontSize=12;fontColor=#ffffff;align=center;strokeColor=#0E7DAD;metaEdit=1;resizable=0;"+FONT,
 'component': "rounded=1;whiteSpace=wrap;html=1;labelBackgroundColor=none;fillColor=#63BEF2;fontColor=#ffffff;align=center;arcSize=6;strokeColor=#2086C9;metaEdit=1;resizable=0;"+FONT,
 'boundary': "rounded=1;fontSize=11;whiteSpace=wrap;html=1;dashed=1;arcSize=3;fillColor=none;strokeColor=#666666;fontColor=#333333;labelBackgroundColor=none;align=left;verticalAlign=bottom;spacing=10;dashPattern=8 4;metaEdit=1;container=1;collapsible=0;"+FONT,
 'title': "text;html=1;align=left;verticalAlign=top;fontSize=20;fontStyle=1;"+FONT,
 'edge': "endArrow=blockThin;html=1;fontSize=10;fontColor=#404040;strokeWidth=1;endFill=1;strokeColor=#828282;metaEdit=1;endSize=14;startSize=14;jumpStyle=arc;jumpSize=16;rounded=1;edgeStyle=orthogonalEdgeStyle;labelBackgroundColor=#ffffff;"+FONT,
}
KIND = {'person': 'Person', 'system': 'Software System', 'ext': 'Software System', 'container': 'Container', 'ext_container': 'Container',
        'db': 'Container', 'queue': 'Container', 'component': 'Component'}
SIZE = {'person': (200, 180), 'system': (240, 120), 'ext': (240, 120), 'container': (240, 120), 'ext_container': (240, 120),
        'db': (240, 140), 'queue': (240, 130), 'component': (240, 120)}

def label(name, kind, tech, desc):
    k = KIND[kind] + (f': {tech}' if tech else '')
    return (f'<font style="font-size: 15px"><b>{html.escape(name)}</b></font>'
            f'<div>[{html.escape(k)}]</div><br><div><font style="font-size: 11px"><font color="#E6E6E6">{html.escape(desc)}</font></font></div>')

def page(pid, name, title, nodes, edges, boundary=None):
    cells = ['<mxCell id="0"/>', '<mxCell id="1" parent="0"/>']
    cells.append(f'<mxCell id="{pid}-title" value={quoteattr(title)} style="{S["title"]}" vertex="1" parent="1"><mxGeometry x="20" y="-60" width="900" height="40" as="geometry"/></mxCell>')
    if boundary:
        bid, blabel, (bx, by, bw, bh) = boundary
        v = f'<font style="font-size: 14px"><b>{html.escape(blabel[0])}</b></font><div>[{html.escape(blabel[1])}]</div>'
        cells.append(f'<mxCell id="{bid}" value={quoteattr(v)} style="{S["boundary"]}" vertex="1" parent="1"><mxGeometry x="{bx}" y="{by}" width="{bw}" height="{bh}" as="geometry"/></mxCell>')
    for nid, kind, name, tech, desc, x, y in nodes:
        w, h = SIZE[kind]
        cells.append(f'<mxCell id="{nid}" value={quoteattr(label(name, kind, tech, desc))} style="{S[kind]}" vertex="1" parent="1"><mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry"/></mxCell>')
    for i, (src, dst, text, tech, *opt) in enumerate(edges):
        style = S['edge'] + ('dashed=1;' if 'dashed' in opt else '') + ('startArrow=blockThin;startFill=1;' if 'both' in opt else '') + ''.join(o for o in opt if '=' in o)
        points = next((o[7:] for o in opt if o.startswith('points:')), '')
        label_at = next((o[6:] for o in opt if o.startswith('label:')), None)
        geometry = '<mxGeometry relative="1" as="geometry"' + (f' x="{label_at}"' if label_at else '') + '>' + (('<Array as="points">' + ''.join(f'<mxPoint x="{p.split(",")[0]}" y="{p.split(",")[1]}"/>' for p in points.split(' ')) + '</Array>') if points else '') + '</mxGeometry>'
        v = f'<div style="text-align: left"><div style="text-align: center"><b>{html.escape(text)}</b></div>' + (f'<div style="text-align: center">[{html.escape(tech)}]</div>' if tech else '') + '</div>'
        cells.append(f'<mxCell id="{pid}-e{i}" value={quoteattr(v)} style="{style}" edge="1" parent="1" source="{src}" target="{dst}">{geometry}</mxCell>')
    return (f'<diagram id="{pid}" name={quoteattr(name)}><mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="0" pageScale="1" pageWidth="1169" pageHeight="827" math="0" shadow="0"><root>'
            + ''.join(cells) + '</root></mxGraphModel></diagram>')

context = page('ctx', '1. Контекст', 'Sender: контекст системы (C4, уровень 1)', [
    ('staff', 'person', 'Сотрудник MagExpert', '', 'Готовит письма и базы, запускает рассылки', 100, 0),
    ('doctor', 'person', 'Врач', '', 'Получает письма, открывает, отписывается', 860, 0),
    ('site', 'ext', 'Сайт MagExpert', '', 'Регистрация, мероприятия, галочка «Письма о мероприятиях»', 0, 330),
    ('sender', 'system', 'Sender', '', 'Сервис рассылок mail.mag-expert.ru: служебные письма и рассылки', 460, 330),
    ('mailboxes', 'ext', 'Почтовые сервисы', '', 'Mail.ru, Яндекс, Gmail', 840, 330),
    ('apps', 'ext', 'Внешние приложения', '', 'CRM и другие проекты', 0, 620),
    ('telegram', 'ext', 'Telegram', '', 'Чат с алертами', 460, 620),
    ('fbl', 'ext', 'Ящик FBL', '', 'Отчёты о жалобах «Это спам»', 840, 620),
], [
    ('staff', 'sender', 'Управляет рассылками', 'HTTPS', 'exitX=0.5;exitY=1;entryX=0.25;entryY=0;'),
    ('doctor', 'sender', 'Открытия, клики, отписка', 'HTTPS', 'dashed', 'exitX=0;exitY=0.6;entryX=0.75;entryY=0;'),
    ('site', 'sender', 'Служебные письма, согласия', 'вызов в приложении', 'both'),
    ('sender', 'mailboxes', 'Отправляет письма', 'SMTP, DKIM', 'exitX=1;exitY=0.35;entryX=0;entryY=0.35;'),
    ('mailboxes', 'doctor', 'Доставляет', ''),
    ('mailboxes', 'fbl', 'Отчёты о жалобах', 'ARF', 'exitX=0.75;exitY=1;entryX=0.75;entryY=0;'),
    ('sender', 'fbl', 'Забирает жалобы', 'IMAP', 'exitX=1;exitY=0.8;entryX=0;entryY=0.5;', 'points:780,426 780,680'),
    ('sender', 'telegram', 'Алерты', 'Bot API'),
    ('apps', 'sender', 'Письма, подписки / вебхуки', 'REST API, HMAC', 'both', 'exitX=0.5;exitY=0;entryX=0.15;entryY=1;'),
])

container = page('cnt', '2. Контейнеры', 'MagExpert и Sender: контейнеры (C4, уровень 2)', [
    ('c-staff', 'person', 'Сотрудник MagExpert', '', 'Готовит и запускает рассылки', 100, 0),
    ('c-doctor', 'person', 'Врач', '', 'Пользуется сайтом, получает письма', 560, 0),
    ('c-apps', 'ext', 'Внешние приложения', '', 'CRM и другие проекты', 900, 30),
    ('spa', 'container', 'Админка Sender — фронтенд', 'React 19 SPA, в браузере', 'Статика с mail.mag-expert.ru; данные берёт только через REST API', 80, 300),
    ('site', 'container', 'Сайт MagExpert — фронтенд', 'Vue 3 + Inertia, в браузере', 'Страницы сайта, кабинет, галочка рассылки', 460, 300),
    ('db', 'db', 'PostgreSQL', 'magexpert_sender', 'Письма, события, базы, рассылки, блок-лист, журнал', 80, 490),
    ('app', 'container', 'Бэкенд: Laravel-приложение', 'PHP 8.4, Laravel 11', 'Сайт и модуль Sender: REST API, Inertia, трекинг, отписка', 460, 500),
    ('scheduler', 'container', 'Планировщик', 'cron, schedule:run', 'Пачки, mail.log, зависшие письма, жалобы, алерты', 840, 500),
    ('worker-c', 'container', 'Воркер рассылок', 'queue:work, supervisor', 'Очередь sender: рассылки, подготовка баз, проверка адресов, вебхуки', 80, 720),
    ('redis', 'queue', 'Redis', 'две очереди', 'sender-transactional, sender', 460, 710),
    ('worker-tx', 'container', 'Воркер служебных писем', 'queue:work, supervisor', 'Только sender-transactional: сброс пароля, подтверждение почты', 840, 720),
    ('postfix', 'container', 'Postfix + OpenDKIM', '127.0.0.1:25', 'DKIM, медленные транспорты, /var/log/mail.log', 460, 930),
    ('c-fbl', 'ext', 'Ящик FBL', '', 'Отчёты о жалобах, IMAP', 80, 1160),
    ('c-mailboxes', 'ext', 'Почтовые сервисы', '', 'Mail.ru, Яндекс, Gmail', 460, 1160),
    ('c-telegram', 'ext', 'Telegram', '', 'Алерты', 840, 1160),
], [
    ('c-staff', 'spa', 'Открывает в браузере', 'HTTPS'),
    ('c-doctor', 'site', 'Открывает в браузере', 'HTTPS', 'exitX=0.5;exitY=1;entryX=0.8;entryY=0;'),
    ('spa', 'app', 'REST API /api/sender/v1/admin', 'JSON, Bearer-токен', 'exitX=1;exitY=0.7;entryX=0;entryY=0.33;', 'points:390,384 390,540'),
    ('site', 'app', 'Inertia: страницы и формы', 'HTTPS, JSON'),
    ('c-doctor', 'app', 'Ссылки из писем: пиксель, клики, отписка', 'HTTPS, без фронтенда', 'dashed', 'exitX=1;exitY=0.7;entryX=1;entryY=0.33;', 'points:790,126 790,540', 'label:-0.55'),
    ('c-apps', 'app', 'REST API отправки', 'JSON, ключ mxs_', 'exitX=0;exitY=0.5;entryX=1;entryY=0.67;', 'points:812,90 812,580', 'label:0.45'),
    ('app', 'db', 'Читает и пишет', 'SQL', 'exitX=0;exitY=0.67;entryX=1;entryY=0.64;'),
    ('app', 'redis', 'Ставит письма', ''),
    ('scheduler', 'redis', 'Очередные пачки', '', 'exitX=0;exitY=0.8;entryX=1;entryY=0.3;', 'points:770,596 770,749'),
    ('redis', 'worker-tx', 'sender-transactional', '', 'exitX=1;exitY=0.65;entryX=0;entryY=0.6;'),
    ('redis', 'worker-c', 'sender', '', 'exitX=0;exitY=0.5;entryX=1;entryY=0.5;'),
    ('worker-tx', 'postfix', 'Письма', 'SMTP', 'exitX=0.5;exitY=1;entryX=1;entryY=0.4;', 'points:960,978'),
    ('worker-c', 'postfix', 'Письма', 'SMTP', 'exitX=0.5;exitY=1;entryX=0;entryY=0.5;', 'points:200,990'),
    ('scheduler', 'postfix', 'Читает mail.log', '', 'dashed', 'exitX=1;exitY=0.6;entryX=1;entryY=0.75;', 'points:1110,572 1110,1020'),
    ('postfix', 'c-mailboxes', 'Доставляет', 'SMTP'),
    ('scheduler', 'c-telegram', 'Алерты', 'HTTPS', 'exitX=1;exitY=0.3;entryX=1;entryY=0.5;', 'points:1140,536 1140,1220'),
    ('scheduler', 'c-fbl', 'Жалобы', 'IMAP', 'exitX=0.5;exitY=0;entryX=0;entryY=0.5;', 'points:960,435 20,435 20,1220'),
], ('vps', ('MagExpert: сайт и Sender', 'фронтенд в браузере, бэкенд на VPS 62.217.181.42'), (40, 260, 1120, 860)))

component = page('cmp', '3. Компоненты', 'Sender: компоненты Laravel-приложения (C4, уровень 3)', [
    ('k-spa', 'container', 'Админка', 'React', 'Экраны Sender', 80, 0),
    ('k-site', 'ext_container', 'Код сайта MagExpert', 'Laravel', 'SenderMailService, галочка рассылки', 840, 0),
    ('http', 'component', 'HTTP-контроллеры', 'Admin, Message, Track, Unsubscribe', 'Админ-API, API отправки, трекинг, отписка; роли и журнал', 80, 220),
    ('campaigns', 'component', 'CampaignService', '', 'Запуск, подготовка, пачки, пауза, отмена, статистика', 460, 220),
    ('subscriptions', 'component', 'SubscriptionService, SubscriberSources', '', 'Отписка на все базы, база «Пользователи сайта»', 80, 420),
    ('messages', 'component', 'MessageService', '', 'Проверки отправителя, блок-лист, дневной лимит, очередь', 460, 420),
    ('schedule', 'component', 'CampaignSchedule, Warmup', '', 'Окно отправки по МСК, оценка окончания, прогрев IP', 840, 420),
    ('delivery', 'component', 'MailLogService, ComplaintService', '', 'Статусы из mail.log, жалобы ARF', 80, 620),
    ('jobs', 'component', 'SendMessageJob', 'задача очереди', 'Захват письма, Feedback-ID, List-Unsubscribe', 460, 620),
    ('monitoring', 'component', 'HealthMonitor, WebhookService', '', 'Алерты в Telegram, события на вебхуки', 840, 620),
    ('k-postfix', 'container', 'Postfix + OpenDKIM', 'SMTP', 'Подпись DKIM, /var/log/mail.log', 80, 1040),
    ('k-redis', 'queue', 'Redis', 'очереди', 'sender-transactional, sender', 460, 1040),
], [
    ('k-spa', 'http', 'JSON', ''),
    ('http', 'campaigns', 'Управление', ''),
    ('http', 'subscriptions', 'Отписка', ''),
    ('http', 'messages', 'Письмо по API', '', 'exitX=1;exitY=0.8;entryX=0;entryY=0.5;', 'points:390,316 390,480'),
    ('campaigns', 'messages', 'Готовит письма', ''),
    ('campaigns', 'schedule', 'Когда и сколько', '', 'exitX=1;exitY=0.5;entryX=0.5;entryY=0;', 'points:960,280'),
    ('k-site', 'messages', 'Служебные письма', 'LocalSenderClient', 'exitX=0.3;exitY=1;entryX=1;entryY=0.35;', 'points:912,200 780,200 780,462'),
    ('k-site', 'subscriptions', 'Согласие на рассылку', 'SubscriptionClient', 'exitX=0.1;exitY=1;entryX=0;entryY=0.5;', 'points:864,160 60,160 60,480'),
    ('messages', 'k-redis', 'В очередь', '', 'exitX=1;exitY=0.8;entryX=0.75;entryY=0;', 'points:760,516 760,1000 640,1000'),
    ('k-redis', 'jobs', 'Задачи', '', 'exitX=0.5;exitY=0;entryX=0.5;entryY=1;'),
    ('jobs', 'k-postfix', 'Отправка', 'SMTP', 'exitX=0;exitY=0.7;entryX=0.75;entryY=0;', 'points:400,704 400,1000 260,1000'),
    ('k-postfix', 'delivery', 'mail.log', '', 'dashed', 'exitX=0.5;exitY=0;entryX=0.5;entryY=1;'),
    ('delivery', 'subscriptions', 'Блок после жалобы', ''),
    ('delivery', 'monitoring', 'События', '', 'exitX=1;exitY=0.2;entryX=0.5;entryY=0;', 'points:360,644 360,590 960,590'),
], ('module', ('Модуль app/Sender', 'Laravel-приложение'), (40, 190, 1080, 780)))

out = sys.argv[1]
open(out, 'w').write('<mxfile host="Claude" agent="Claude Code" version="29.3.0">' + context + container + component + '</mxfile>\n')
print(out)
