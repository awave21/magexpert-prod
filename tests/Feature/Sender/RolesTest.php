<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Queue::fake([CheckContactsJob::class]);

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->owner = User::create(['organization_id' => $this->organization->id, 'name' => 'Владелец', 'email' => 'owner@example.com', 'password' => 'secret-pass', 'role' => 'owner']);
    $this->ownerToken = (new AuthService)->login('owner@example.com', 'secret-pass')['token'];

    $domain = $this->organization->domains()->create(['domain' => 'mag-expert.ru', 'verification_token' => 't', 'status' => Domain::STATUS_VERIFIED, 'dkim_selector' => 'mail']);
    $sender = $this->organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'news@mag-expert.ru', 'name' => 'MagExpert']);
    $sender->forceFill(['confirmed_at' => now()])->save();
    $this->template = $this->organization->templates()->create(['slug' => 'digest', 'name' => 'Дайджест', 'subject' => 'Новости', 'body_html' => '<p>Текст</p>', 'sender_address_id' => $sender->id]);
    $this->list = $this->organization->lists()->create(['name' => 'Врачи']);
    $this->list->contacts()->create(['organization_id' => $this->organization->id, 'email' => 'a@example.com']);
    $this->campaign = $this->organization->campaigns()->create(['name' => 'Ноябрь', 'template_id' => $this->template->id, 'list_id' => $this->list->id, 'status' => Campaign::STATUS_DRAFT]);
});

function rolesApi(string $path): string
{
    return '/api/sender/v1/admin'.$path;
}

/**
 * @return array{0: User, 1: string}
 */
function member(string $role, ?string $email = null): array
{
    $email ??= "{$role}@example.com";
    $user = User::create(['organization_id' => test()->organization->id, 'name' => ucfirst($role), 'email' => $email, 'password' => 'secret-pass', 'role' => $role]);

    return [$user, (new AuthService)->login($email, 'secret-pass')['token']];
}

it('lets a designer edit letters but nothing else', function (): void {
    [$designer, $token] = member('designer');

    $this->withToken($token)->postJson(rolesApi('/templates'), ['slug' => 'new', 'name' => 'Новое', 'subject' => 'Тема', 'body_html' => '<p>X</p>'])->assertCreated();
    $this->withToken($token)->putJson(rolesApi("/templates/{$this->template->id}"), ['slug' => 'digest', 'name' => 'Дайджест 2', 'subject' => 'Новости', 'body_html' => '<p>Y</p>'])->assertOk();
    $this->withToken($token)->postJson(rolesApi('/template-folders'), ['name' => 'Папка'])->assertCreated();
    $this->withToken($token)->postJson(rolesApi('/variables'), ['key' => 'clinic', 'label' => 'Клиника'])->assertCreated();
    $this->withToken($token)->putJson(rolesApi('/profile'), ['name' => 'Дизайнер Иван'])->assertOk();

    $denied = 'Это может делать только редактор или администратор';
    $this->withToken($token)->postJson(rolesApi('/lists'), ['name' => 'Новая'])->assertForbidden()->assertJsonPath('message', $denied);
    $this->withToken($token)->postJson(rolesApi("/lists/{$this->list->id}/import"), [])->assertForbidden();
    $this->withToken($token)->deleteJson(rolesApi("/lists/{$this->list->id}"))->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/campaigns'), ['name' => 'X'])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi("/campaigns/{$this->campaign->id}/send"))->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/domains'), ['domain' => 'other.ru'])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/api-keys'), ['name' => 'Ключ'])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/users'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();

    // тест письма — только себе
    $this->withToken($token)->postJson(rolesApi("/templates/{$this->template->id}/test"), ['to' => 'someone@else.ru'])
        ->assertForbidden()->assertJsonPath('message', 'Дизайнер может отправить тестовое письмо только себе');

    expect($this->campaign->fresh()->status)->toBe(Campaign::STATUS_DRAFT)
        ->and($this->organization->lists()->count())->toBe(1);
});

it('keeps a viewer read-only', function (): void {
    [, $token] = member('viewer');

    $this->withToken($token)->getJson(rolesApi('/templates'))->assertOk();
    $this->withToken($token)->getJson(rolesApi('/organization'))->assertOk()->assertJsonPath('data.owner.email', 'owner@example.com');
    $this->withToken($token)->postJson(rolesApi('/templates'), ['name' => 'Новое', 'subject' => 'Тема', 'body_html' => 'X'])
        ->assertForbidden()->assertJsonPath('message', 'У вас доступ только для просмотра');
    $this->withToken($token)->postJson(rolesApi('/template-folders'), ['name' => 'Папка'])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/lists'), ['name' => 'Новая'])->assertForbidden();
    $this->withToken($token)->putJson(rolesApi('/profile'), ['name' => 'Зритель'])->assertOk();
});

it('lets an editor work with bases and campaigns but not with the team', function (): void {
    [, $token] = member('editor');

    $this->withToken($token)->postJson(rolesApi('/lists'), ['name' => 'Новая'])->assertCreated();
    $this->withToken($token)->postJson(rolesApi('/users'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'viewer'])
        ->assertForbidden()->assertJsonPath('message', 'Это может делать только администратор организации');
    $this->withToken($token)->putJson(rolesApi("/users/{$this->owner->id}"), ['role' => 'viewer'])->assertForbidden();
    $this->withToken($token)->putJson(rolesApi('/organization'), ['name' => 'Новое'])
        ->assertForbidden()->assertJsonPath('message', 'Это может делать только владелец организации');
});

it('does not let an admin touch the owner or grant ownership', function (): void {
    [, $token] = member('admin');

    $this->withToken($token)->putJson(rolesApi("/users/{$this->owner->id}"), ['role' => 'viewer'])
        ->assertForbidden()->assertJsonPath('message', 'Владельца организации изменить или отключить нельзя');
    $this->withToken($token)->putJson(rolesApi("/users/{$this->owner->id}"), ['is_active' => false])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/users'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'owner'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');

    [$editor] = member('editor');
    $this->withToken($token)->putJson(rolesApi("/users/{$editor->id}"), ['role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->withToken($token)->putJson(rolesApi("/users/{$editor->id}"), ['role' => 'designer'])->assertOk()->assertJsonPath('data.role', 'designer');

    $this->withToken($token)->putJson(rolesApi('/organization'), ['name' => 'Новое'])->assertForbidden();
    $this->withToken($token)->postJson(rolesApi('/organization/transfer'), ['user_id' => $editor->id, 'current_password' => 'secret-pass'])->assertForbidden();

    expect($this->owner->fresh()->role)->toBe('owner')
        ->and($this->owner->fresh()->is_active)->toBeTrue();
});

it('counts the owner as an admin and exposes the role in /me', function (): void {
    [$admin] = member('admin');

    // единственный другой администратор — владелец, значит admin можно понизить
    $this->withToken($this->ownerToken)->putJson(rolesApi("/users/{$admin->id}"), ['role' => 'editor'])->assertOk();
    $this->withToken($this->ownerToken)->getJson(rolesApi('/me'))->assertOk()
        ->assertJsonPath('data.role', 'owner')->assertJsonPath('data.is_owner', true);
    $this->withToken($this->ownerToken)->getJson(rolesApi('/users'))->assertOk()
        ->assertJsonFragment(['email' => 'owner@example.com', 'is_owner' => true]);
});

it('transfers ownership with the correct password only', function (): void {
    [$admin, $adminToken] = member('admin');
    [$disabled] = member('viewer', 'off@example.com');
    $disabled->forceFill(['is_active' => false])->save();

    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $admin->id, 'current_password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $disabled->id, 'current_password' => 'secret-pass'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    expect($this->owner->fresh()->role)->toBe('owner');

    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $admin->id, 'current_password' => 'secret-pass'])
        ->assertOk()->assertJsonPath('data.owner.id', $admin->id);

    expect($this->owner->fresh()->role)->toBe('admin')
        ->and($admin->fresh()->role)->toBe('owner')
        ->and(User::query()->where('organization_id', $this->organization->id)->where('role', 'owner')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'organization.transfer')->value('subject'))->toBe('admin@example.com');

    // прежний владелец больше не владелец
    $this->withToken($this->ownerToken)->putJson(rolesApi('/organization'), ['name' => 'Новое'])->assertForbidden();
    $this->withToken($adminToken)->putJson(rolesApi("/users/{$this->owner->id}"), ['role' => 'viewer'])->assertOk();
});

it('lets only the owner rename the organization', function (): void {
    [, $adminToken] = member('admin');

    $this->withToken($adminToken)->putJson(rolesApi('/organization'), ['name' => 'Чужое'])->assertForbidden();
    $this->withToken($this->ownerToken)->putJson(rolesApi('/organization'), ['name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->withToken($this->ownerToken)->putJson(rolesApi('/organization'), ['name' => '  Клиника Здоровье  '])
        ->assertOk()->assertJsonPath('data.name', 'Клиника Здоровье');

    expect($this->organization->fresh()->name)->toBe('Клиника Здоровье')
        ->and(AuditLog::query()->where('action', 'organization.update')->value('subject'))->toBe('Клиника Здоровье');
});

it('backfills the earliest active admin as the owner', function (): void {
    $other = Organization::create(['name' => 'Другая', 'slug' => 'other']);
    $make = fn (string $email, string $role, bool $active, string $at) => DB::connection(config('sender.connection'))->table('sender_users')->insertGetId([
        'organization_id' => $other->id, 'name' => $email, 'email' => $email, 'password' => 'x', 'role' => $role, 'is_active' => $active, 'created_at' => $at, 'updated_at' => $at,
    ]);
    $make('editor@other.ru', 'editor', true, '2026-01-01 00:00:00');
    $make('off@other.ru', 'admin', false, '2026-01-02 00:00:00');
    $first = $make('first@other.ru', 'admin', true, '2026-01-03 00:00:00');
    $make('second@other.ru', 'admin', true, '2026-01-04 00:00:00');

    $migration = require base_path('app/Sender/Database/Migrations/2026_10_12_000022_add_owner_and_designer_roles_to_sender_users.php');
    $migration->up();

    expect(User::query()->where('organization_id', $other->id)->where('role', 'owner')->pluck('id')->all())->toBe([$first])
        ->and($this->owner->fresh()->role)->toBe('owner')
        ->and(User::query()->where('organization_id', $this->organization->id)->where('role', 'owner')->count())->toBe(1);
});

it('makes the creator of a new organization its owner', function (): void {
    config(['sender.registration' => true]);

    $this->postJson(rolesApi('/register'), [
        'organization' => 'Клиника', 'name' => 'Анна', 'email' => 'anna@clinic.ru', 'password' => 'long-password', 'accept_policy' => true,
    ])->assertCreated()->assertJsonPath('user.role', 'owner')->assertJsonPath('user.is_owner', true);
});

it('makes the first admin created from the console the owner', function (): void {
    $empty = Organization::create(['name' => 'Пустая', 'slug' => 'empty']);

    $this->artisan('sender:user-create', ['email' => 'boss@empty.ru', '--organization' => 'empty', '--password' => 'secret-pass'])->assertSuccessful();
    $this->artisan('sender:user-create', ['email' => 'second@empty.ru', '--organization' => 'empty', '--password' => 'secret-pass'])->assertSuccessful();
    $this->artisan('sender:user-create', ['email' => 'third@empty.ru', '--organization' => 'empty', '--role' => 'owner'])->assertFailed();

    expect(User::query()->where('email', 'boss@empty.ru')->value('role'))->toBe('owner')
        ->and(User::query()->where('email', 'second@empty.ru')->value('role'))->toBe('admin')
        ->and($empty->users()->count())->toBe(2);
});
