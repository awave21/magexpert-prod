<?php

use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\Message;
use App\Sender\Models\MessageEvent;
use App\Sender\Models\Organization;
use App\Sender\Models\Suppression;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use App\Sender\Services\CampaignService;
use App\Sender\Services\MailLogService;
use App\Sender\Services\TrackingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Cache::flush();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    User::create(['organization_id' => $this->organization->id, 'name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret-pass']);
    $this->token = (new AuthService)->login('admin@example.com', 'secret-pass')['token'];

    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->template = $this->organization->templates()->create([
        'slug' => 'news', 'name' => 'Новости', 'subject' => 'Новости',
        'body_html' => '<html><body><p><a href="https://mag-expert.ru/events?a=1&amp;b=2">Мероприятия</a> <a href="mailto:x@y.ru">Почта</a> <a href="{{ event_url }}">Событие</a></p></body></html>',
        'sender_address_id' => $sender->id,
    ]);
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
    $this->contact = $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'anna@mail.ru', 'name' => 'Анна', 'check_status' => 'ok', 'checked_at' => now()]);
});

function sendCampaign(bool $track = true): Message
{
    $campaign = test()->organization->campaigns()->create([
        'name' => 'Ноябрь', 'template_id' => test()->template->id, 'list_id' => test()->list->id,
        'track' => $track, 'status' => Campaign::STATUS_SENDING,
    ]);
    app(CampaignService::class)->dispatchMessages($campaign);

    return Message::query()->where('campaign_id', $campaign->id)->firstOrFail();
}

it('tracks links and opens in campaign mail but keeps unsubscribe, mailto and broken links', function (): void {
    $message = sendCampaign();
    $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $html = $sent->getHtmlBody();
    $tracking = app(TrackingService::class);

    expect($message->fresh()->tracked)->toBeTrue()
        ->and($html)->toContain(e($tracking->clickUrl($message, 'https://mag-expert.ru/events?a=1&b=2')))
        ->and($html)->not->toContain('href="https://mag-expert.ru/events')
        ->and($html)->toContain('href="mailto:x@y.ru"')
        ->and($html)->toContain('href=""')
        ->and($html)->toContain('href="'.e($message->data['unsubscribe_url']).'"')
        ->and($html)->toContain(e($tracking->pixelUrl($message)))
        ->and($sent->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<'.$message->uuid.'@'.config('sender.mail_host').'>');
});

it('does not track when the campaign has tracking off and in transactional mail', function (): void {
    $message = sendCampaign(track: false);
    $html = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();

    expect($message->tracked)->toBeFalse()
        ->and($html)->toContain('href="https://mag-expert.ru/events?a=1&amp;b=2"')
        ->and($html)->not->toContain('/t/o/');
});

it('records a human open and marks the contact active', function (): void {
    $message = sendCampaign();

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])->get('/sender/t/o/'.$message->uuid)
        ->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect($message->fresh()->opened_at)->not->toBeNull()
        ->and($message->fresh()->opens_count)->toBe(1)
        ->and($this->contact->fresh()->last_opened_at)->not->toBeNull();
});

it('counts apple and bot opens as automatic', function (): void {
    $message = sendCampaign();

    $this->withServerVariables(['REMOTE_ADDR' => '17.58.1.2'])->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get('/sender/t/o/'.$message->uuid)->assertOk();
    $this->withHeaders(['User-Agent' => 'Barracuda Sentinel'])->get('/sender/t/o/'.$message->uuid)->assertOk();

    expect($message->fresh()->opened_at)->toBeNull()
        ->and(MessageEvent::query()->where('type', 'open')->where('is_auto', true)->count())->toBe(2);
});

it('redirects a signed click, records it and rejects a forged link', function (): void {
    $message = sendCampaign();
    $message->forceFill(['sent_at' => now()->subMinute()])->save();
    $url = app(TrackingService::class)->clickUrl($message, 'https://mag-expert.ru/events?a=1&b=2');
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Safari'])->get($path)->assertRedirect('https://mag-expert.ru/events?a=1&b=2');

    $fresh = $message->fresh();
    expect($fresh->clicked_at)->not->toBeNull()
        ->and($fresh->clicks_count)->toBe(1)
        ->and($fresh->opened_at)->not->toBeNull();

    $this->get('/sender/t/c/'.$message->uuid.'?u='.urlencode('https://evil.example').'&s=0000')->assertNotFound();

    $this->getJson('/api/sender/v1/admin/campaigns/'.$message->campaign_id, ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()
        ->assertJsonPath('data.stats.opened', 1)
        ->assertJsonPath('data.stats.clicked', 1)
        ->assertJsonPath('data.stats.links.0', ['url' => 'https://mag-expert.ru/events?a=1&b=2', 'clicks' => 1]);
});

it('treats a click right after sending as a link scanner', function (): void {
    $message = sendCampaign();
    $url = app(TrackingService::class)->clickUrl($message, 'https://mag-expert.ru/events?a=1&b=2');

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Safari'])->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertRedirect();

    expect($message->fresh()->clicked_at)->toBeNull();
});

it('reads delivery, deferral and bounce from the postfix log', function (): void {
    $first = sendCampaign();
    $second = $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'ghost@mail.ru', 'from_email' => 'news@mag-expert.ru', 'subject' => 'Тема', 'status' => Message::STATUS_SENT]);
    $third = $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'spam@corp.ru', 'from_email' => 'news@mag-expert.ru', 'subject' => 'Тема', 'status' => Message::STATUS_SENT]);
    $host = config('sender.mail_host');

    $log = <<<LOG
Oct 10 10:00:00 mail postfix/cleanup[100]: 4ABC1: message-id=<{$first->uuid}@{$host}>
Oct 10 10:00:00 mail postfix/cleanup[100]: 4ABC2: message-id=<{$second->uuid}@{$host}>
Oct 10 10:00:00 mail postfix/cleanup[100]: 4ABC3: message-id=<{$third->uuid}@{$host}>
Oct 10 10:00:00 mail postfix/cleanup[100]: 4ZZZZ: message-id=<other@example.com>
Oct 10 10:00:01 mail postfix/smtp[101]: 4ABC1: to=<anna@mail.ru>, relay=mxs.mail.ru[94.100.180.31]:25, delay=1, delays=0/0/0.5/0.5, dsn=4.7.1, status=deferred (host mxs.mail.ru said: 421 try again later)
Oct 10 10:05:01 mail postfix/smtp[101]: 4ABC1: to=<anna@mail.ru>, relay=mxs.mail.ru[94.100.180.31]:25, delay=301, delays=0/0/0.5/0.5, dsn=2.0.0, status=sent (250 OK id=1abc)
Oct 10 10:00:02 mail postfix/smtp[102]: 4ABC2: to=<ghost@mail.ru>, relay=mxs.mail.ru[94.100.180.31]:25, delay=1, dsn=5.1.1, status=bounced (host mxs.mail.ru said: 550 Message was not accepted -- invalid mailbox. Local mailbox ghost@mail.ru is unavailable: user not found)
Oct 10 10:00:03 mail postfix/smtp[103]: 4ABC3: to=<spam@corp.ru>, relay=mx.corp.ru[1.2.3.4]:25, delay=1, dsn=5.7.1, status=bounced (host mx.corp.ru said: 554 5.7.1 Message rejected as spam)
LOG;

    $service = app(MailLogService::class);
    foreach (explode("\n", $log) as $line) {
        $service->handle($line);
    }

    expect($first->fresh()->status)->toBe(Message::STATUS_DELIVERED)
        ->and($first->fresh()->delivered_at)->not->toBeNull()
        ->and($first->fresh()->error)->toBeNull()
        ->and($first->events()->pluck('type')->all())->toBe(['deferred', 'delivered'])
        ->and($second->fresh()->status)->toBe(Message::STATUS_BOUNCED)
        ->and($second->fresh()->error)->toStartWith('Адреса не существует.')
        ->and(Suppression::query()->where('email', 'ghost@mail.ru')->value('reason'))->toBe('bounce')
        ->and($third->fresh()->status)->toBe(Message::STATUS_BOUNCED)
        ->and(Suppression::query()->where('email', 'spam@corp.ru')->exists())->toBeFalse();
});

it('reads the log incrementally and starts over after rotation', function (): void {
    $message = sendCampaign();
    $file = tempnam(sys_get_temp_dir(), 'maillog');
    $host = config('sender.mail_host');

    file_put_contents($file, "Oct 10 mail postfix/cleanup[1]: 9AA1: message-id=<{$message->uuid}@{$host}>\n");
    $this->artisan('sender:mail-log', ['file' => $file])->expectsOutputToContain('о наших письмах: 1')->assertSuccessful();

    file_put_contents($file, "Oct 10 mail postfix/smtp[2]: 9AA1: to=<anna@mail.ru>, relay=x, dsn=2.0.0, status=sent (250 OK)\npartial line", FILE_APPEND);
    $this->artisan('sender:mail-log', ['file' => $file])->expectsOutputToContain('Прочитано строк: 1, о наших письмах: 1')->assertSuccessful();

    expect($message->fresh()->status)->toBe(Message::STATUS_DELIVERED);

    unlink($file);
    file_put_contents($file, "Oct 11 mail postfix/smtp[3]: 9AA1: to=<anna@mail.ru>, relay=x, dsn=2.0.0, status=sent (250 OK)\n");
    $this->artisan('sender:mail-log', ['file' => $file])->expectsOutputToContain('Прочитано строк: 1')->assertSuccessful();

    unlink($file);
});

it('counts delivered and bounced mail in the overview', function (): void {
    $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'a@mail.ru', 'from_email' => 'n@mag-expert.ru', 'subject' => 'Т', 'status' => Message::STATUS_DELIVERED]);
    $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'b@mail.ru', 'from_email' => 'n@mag-expert.ru', 'subject' => 'Т', 'status' => Message::STATUS_BOUNCED]);

    $this->getJson('/api/sender/v1/admin/stats', ['Authorization' => 'Bearer '.$this->token])
        ->assertOk()->assertJsonPath('totals.sent', 1)->assertJsonPath('totals.failed', 1);
});

it('prunes messages and events older than the retention period', function (): void {
    $old = $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'a@mail.ru', 'from_email' => 'n@mag-expert.ru', 'subject' => 'Т', 'status' => Message::STATUS_DELIVERED]);
    $old->events()->create(['type' => MessageEvent::TYPE_OPEN]);
    $old->forceFill(['created_at' => now()->subDays(400)])->save();
    $fresh = $this->organization->messages()->create(['uuid' => (string) Str::uuid(), 'to_email' => 'b@mail.ru', 'from_email' => 'n@mag-expert.ru', 'subject' => 'Т', 'status' => Message::STATUS_DELIVERED]);

    $this->artisan('sender:prune')->expectsOutputToContain('Удалено писем старше 365 дней: 1')->assertSuccessful();

    expect(Message::query()->pluck('id')->all())->toBe([$fresh->id])
        ->and(MessageEvent::query()->count())->toBe(0);
});
