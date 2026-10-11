<?php

use App\Sender\Models\Domain;
use App\Sender\Models\Organization;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    config(['sender.client.organization' => 'magexpert']);
    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
});

it('imports the welcome template from the repository file', function (): void {
    $this->artisan('sender:template-import', ['file' => base_path('deploy/templates/welcome.json')])
        ->expectsOutputToContain('Создан шаблон «welcome», отправителя выберите в интерфейсе')
        ->assertSuccessful();

    $template = $this->organization->templates()->where('slug', 'welcome')->firstOrFail();

    expect($template->editor)->toBe('blocks')
        ->and($template->design['blocks'])->not->toBeEmpty()
        ->and($template->body_html)->toContain('t.me/beautifulgynecology')
        ->and($template->body_html)->toContain('{{ first_name }}')
        ->and($template->reply_to)->toBe('info@mag-expert.ru');
});

it('imports the password reset link template', function (): void {
    $this->artisan('sender:template-import', ['file' => base_path('deploy/templates/password-reset-link.json')])
        ->assertSuccessful();

    $template = $this->organization->templates()->where('slug', 'password-reset-link')->firstOrFail();

    expect($template->editor)->toBe('blocks')
        ->and($template->subject)->toBe('Смена пароля на mag-expert.ru')
        ->and($template->body_html)->toContain('{{ reset_url }}')
        ->and($template->body_html)->not->toContain('verify_url')
        ->and($template->body_text)->toContain('{{ reset_url }}');
});

it('keeps an edited template unless forced and links a confirmed sender', function (): void {
    $file = base_path('deploy/templates/welcome.json');
    $this->organization->templates()->create(['slug' => 'welcome', 'name' => 'Своё', 'subject' => 'Своя тема', 'body_html' => '<p>своё</p>']);

    $this->artisan('sender:template-import', ['file' => $file])->expectsOutputToContain('уже есть')->assertSuccessful();
    expect($this->organization->templates()->where('slug', 'welcome')->value('subject'))->toBe('Своя тема');

    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'noreply@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();

    $this->artisan('sender:template-import', ['file' => $file, '--force' => true])->expectsOutputToContain('Обновлён шаблон «welcome», отправитель noreply@mag-expert.ru')->assertSuccessful();

    expect($this->organization->templates()->where('slug', 'welcome')->value('sender_address_id'))->toBe($sender->id);
});

it('fails clearly on a missing organization or a broken file', function (): void {
    $this->artisan('sender:template-import', ['file' => base_path('deploy/templates/welcome.json'), '--organization' => 'nope'])->assertFailed();
    $this->artisan('sender:template-import', ['file' => base_path('composer.lock.missing')])->assertFailed();
});
