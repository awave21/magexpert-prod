<?php

use App\Sender\Mcp\Support\TemplateVariables;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $this->sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'noreply@mag-expert.ru', 'name' => 'MagExpert']);
    $this->sender->forceFill(['confirmed_at' => now()])->save();
});

it('treats the fields inside an each block as fields of the list item, not of the letter', function (): void {
    $variables = app(TemplateVariables::class);

    $used = $variables->used(
        'Вы записаны: {{ event_title }}',
        '',
        '{{#if speakers}}<h2>Спикеры</h2>{{#each speakers}}<p>{{ name }}{{#if photo}}<img src="{{ photo }}">{{/if}}</p>{{/each}}{{/if}}',
        '',
    );

    expect($used)->toBe(['event_title', 'speakers'])
        ->and($used)->not->toContain('photo', 'name');
});

it('sender:check-templates passes for a clean template', function (): void {
    $this->organization->templates()->create([
        'slug' => 'event-registration', 'name' => 'Регистрация', 'subject' => 'Вы записаны: {{ event_title }}',
        'preheader' => 'Ждём вас на мероприятии: детали внутри письма',
        'body_html' => '<p>Здравствуйте, {{ first_name }}!</p>{{#each speakers}}<p>{{ name }} {{ regalia }}</p>{{/each}}',
        'sender_address_id' => $this->sender->id,
    ]);

    $this->artisan('sender:check-templates')
        ->expectsOutputToContain('замечаний нет')
        ->assertSuccessful();
});

it('sender:check-templates fails for a service template without a sender address', function (): void {
    config(['sender.client.templates' => ['welcome' => 'welcome']]);
    $this->organization->templates()->create([
        'slug' => 'welcome', 'name' => 'Приветствие', 'subject' => 'Добро пожаловать', 'body_html' => '<p>Привет</p>',
    ]);

    $this->artisan('sender:check-templates')
        ->expectsOutputToContain('welcome')
        ->assertFailed();
});

it('sender:check-templates reports unknown variables and fails only on --strict', function (): void {
    $this->organization->templates()->create([
        'slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости', 'body_html' => '<p>{{ totally_unknown_variable }}</p>',
        'sender_address_id' => $this->sender->id,
    ]);

    $this->artisan('sender:check-templates')
        ->expectsOutputToContain('unknown_variables')
        ->assertSuccessful();

    $this->artisan('sender:check-templates', ['--strict' => true])->assertFailed();
});
