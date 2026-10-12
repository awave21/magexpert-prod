<?php

use App\Sender\Jobs\CheckContactsJob;
use App\Sender\Mcp\McpTokens;
use App\Sender\Models\AuditLog;
use App\Sender\Models\Campaign;
use App\Sender\Models\Domain;
use App\Sender\Models\McpToken;
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

    // почту при регистрации по паролю никто не проверял
    expect(User::query()->where('email', 'anna@clinic.ru')->value('email_verified_at'))->toBeNull();
});

it('makes the first admin created from the console the owner', function (): void {
    $empty = Organization::create(['name' => 'Пустая', 'slug' => 'empty']);

    $this->artisan('sender:user-create', ['email' => 'boss@empty.ru', '--organization' => 'empty', '--password' => 'secret-pass'])->assertSuccessful();
    $this->artisan('sender:user-create', ['email' => 'second@empty.ru', '--organization' => 'empty', '--password' => 'secret-pass'])->assertSuccessful();
    $this->artisan('sender:user-create', ['email' => 'third@empty.ru', '--organization' => 'empty', '--role' => 'owner'])->assertFailed();

    expect(User::query()->where('email', 'boss@empty.ru')->value('role'))->toBe('owner')
        ->and(User::query()->where('email', 'second@empty.ru')->value('role'))->toBe('admin')
        ->and($empty->users()->count())->toBe(2)
        // адрес из консоли указал администратор сервера: вход через Яндекс по email к нему привяжется
        ->and(User::query()->where('email', 'boss@empty.ru')->value('email_verified_at'))->not->toBeNull();
});

it('asks an owner without a password to set one before transferring ownership', function (): void {
    [$admin] = member('admin');
    $this->owner->forceFill(['password_set' => false])->save();

    $this->withToken($this->ownerToken)->getJson(rolesApi('/me'))->assertJsonPath('data.password_set', false);
    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $admin->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'Сначала задайте пароль в профиле']);
    // даже если прислать пароль (он случайный, но вдруг угадан) — сначала нужно задать свой
    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $admin->id, 'current_password' => 'secret-pass'])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'Сначала задайте пароль в профиле']);

    expect($this->owner->fresh()->role)->toBe('owner')
        ->and($admin->fresh()->role)->toBe('admin');
});

it('does not transfer ownership to a member of another organization', function (): void {
    $other = Organization::create(['name' => 'Другая', 'slug' => 'other']);
    $stranger = User::create(['organization_id' => $other->id, 'name' => 'Чужой', 'email' => 'stranger@other.ru', 'password' => 'secret-pass', 'role' => 'admin']);

    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $stranger->id, 'current_password' => 'secret-pass'])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'Выберите активного участника команды, кроме себя']);
    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $this->owner->id, 'current_password' => 'secret-pass'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');

    expect($stranger->fresh()->role)->toBe('admin')
        ->and($this->owner->fresh()->role)->toBe('owner');
});

it('re-checks the owner under lock when the role changed after sign-in', function (): void {
    [$admin] = member('admin');
    $changed = false;

    // параллельная передача: пока запрос шёл, владельцем стал другой участник
    User::retrieved(function (User $user) use (&$changed, $admin): void {
        if (! $changed && $user->id === $this->owner->id) {
            $changed = true;
            User::query()->whereKey($this->owner->id)->update(['role' => 'admin']);
            User::query()->whereKey($admin->id)->update(['role' => 'owner']);
        }
    });

    [$editor] = member('editor');
    $this->withToken($this->ownerToken)->postJson(rolesApi('/organization/transfer'), ['user_id' => $editor->id, 'current_password' => 'secret-pass'])
        ->assertForbidden()->assertJsonPath('message', 'Это может делать только владелец организации');

    expect($changed)->toBeTrue()
        ->and($editor->fresh()->role)->toBe('editor')
        ->and(User::query()->where('organization_id', $this->organization->id)->where('role', 'owner')->pluck('id')->all())->toBe([$admin->id]);
});

it('re-checks under lock that the member being edited is not the owner', function (): void {
    [$admin, $adminToken] = member('admin');
    [$editor] = member('editor');
    $changed = false;

    // пока администратор правил участника, владелец передал ему владение
    User::retrieved(function (User $user) use (&$changed, $admin, $editor): void {
        if (! $changed && $user->id === $admin->id) {
            $changed = true;
            User::query()->whereKey($this->owner->id)->update(['role' => 'admin']);
            User::query()->whereKey($editor->id)->update(['role' => 'owner']);
        }
    });

    $this->withToken($adminToken)->putJson(rolesApi("/users/{$editor->id}"), ['is_active' => false])
        ->assertForbidden()->assertJsonPath('message', 'Владельца организации изменить или отключить нельзя');

    expect($changed)->toBeTrue()
        ->and($editor->fresh()->role)->toBe('owner')
        ->and($editor->fresh()->is_active)->toBeTrue();
});

it('revokes MCP keys and sessions of a deactivated member', function (): void {
    [$editor, $editorToken] = member('editor');
    $plain = app(McpTokens::class)->issue($editor, 'Ассистент')['plain'];
    app(McpTokens::class)->issue($this->owner, 'Свой');

    $this->withToken($this->ownerToken)->putJson(rolesApi("/users/{$editor->id}"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    expect(McpToken::query()->where('user_id', $editor->id)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and(McpToken::query()->where('user_id', $editor->id)->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(McpToken::query()->where('user_id', $this->owner->id)->whereNull('revoked_at')->count())->toBe(1)
        ->and($editor->tokens()->count())->toBe(0);

    $this->withToken($editorToken)->getJson(rolesApi('/me'))->assertUnauthorized();

    // повторное включение старые ключи не возвращает
    $this->withToken($this->ownerToken)->putJson(rolesApi("/users/{$editor->id}"), ['is_active' => true])->assertOk();
    $this->withToken($plain)->postJson('/api/sender/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
});

it('falls back to the earliest active member of any role when an organization has no active admin', function (): void {
    $noAdmin = Organization::create(['name' => 'Без админа', 'slug' => 'no-admin']);
    $nobody = Organization::create(['name' => 'Пустая', 'slug' => 'nobody']);
    $make = fn (int $organizationId, string $email, string $role, bool $active, string $at) => DB::connection(config('sender.connection'))->table('sender_users')->insertGetId([
        'organization_id' => $organizationId, 'name' => $email, 'email' => $email, 'password' => 'x', 'role' => $role, 'is_active' => $active, 'created_at' => $at, 'updated_at' => $at,
    ]);
    $make($noAdmin->id, 'off-admin@no-admin.ru', 'admin', false, '2026-01-01 00:00:00');
    $editor = $make($noAdmin->id, 'editor@no-admin.ru', 'editor', true, '2026-01-02 00:00:00');
    $make($noAdmin->id, 'viewer@no-admin.ru', 'viewer', true, '2026-01-03 00:00:00');
    $make($nobody->id, 'off@nobody.ru', 'admin', false, '2026-01-01 00:00:00');

    $migration = require base_path('app/Sender/Database/Migrations/2026_10_12_000022_add_owner_and_designer_roles_to_sender_users.php');
    $migration->up();

    expect(User::query()->where('organization_id', $noAdmin->id)->where('role', 'owner')->pluck('id')->all())->toBe([$editor])
        ->and(User::query()->where('organization_id', $nobody->id)->where('role', 'owner')->exists())->toBeFalse()
        ->and($this->owner->fresh()->role)->toBe('owner');
});

it('assigns an owner from the console', function (): void {
    [$admin] = member('admin');
    [$viewer] = member('viewer');
    $viewer->forceFill(['is_active' => false])->save();

    $this->artisan('sender:assign-owner', ['organization_slug' => 'nope', 'email' => 'admin@example.com'])
        ->expectsOutputToContain("Организация 'nope' не найдена")->assertFailed();
    $this->artisan('sender:assign-owner', ['organization_slug' => 'magexpert', 'email' => 'ghost@example.com'])
        ->expectsOutputToContain('нет активного пользователя ghost@example.com')->assertFailed();
    $this->artisan('sender:assign-owner', ['organization_slug' => 'magexpert', 'email' => 'viewer@example.com'])->assertFailed();
    $this->artisan('sender:assign-owner', ['organization_slug' => 'magexpert', 'email' => 'owner@example.com'])
        ->expectsOutputToContain('уже владелец')->assertSuccessful();

    $this->artisan('sender:assign-owner', ['organization_slug' => 'magexpert', 'email' => 'Admin@Example.com'])
        ->expectsOutputToContain("Владелец организации 'magexpert' теперь admin@example.com")->assertSuccessful();

    expect($admin->fresh()->role)->toBe('owner')
        ->and($this->owner->fresh()->role)->toBe('admin')
        ->and(User::query()->where('organization_id', $this->organization->id)->where('role', 'owner')->count())->toBe(1);

    // организация без владельца
    $empty = Organization::create(['name' => 'Без владельца', 'slug' => 'ownerless']);
    $lonely = User::create(['organization_id' => $empty->id, 'name' => 'Один', 'email' => 'one@ownerless.ru', 'password' => 'secret-pass', 'role' => 'editor']);
    $this->artisan('sender:assign-owner', ['organization_slug' => 'ownerless', 'email' => 'one@ownerless.ru'])->assertSuccessful();
    expect($lonely->fresh()->role)->toBe('owner');
});

it('refuses to move the owner of another organization from the console', function (): void {
    Organization::create(['name' => 'Другая', 'slug' => 'other']);

    $this->artisan('sender:user-create', ['email' => 'owner@example.com', '--organization' => 'other', '--password' => 'new-pass-123'])
        ->expectsOutputToContain('владелец другой организации')
        ->assertFailed();

    $owner = $this->owner->fresh();
    expect($owner->organization_id)->toBe($this->organization->id)
        ->and($owner->role)->toBe('owner')
        ->and((new AuthService)->login('owner@example.com', 'secret-pass'))->not->toBeNull();

    // обычного участника перенести можно, пароль считается заданным
    [$editor] = member('editor');
    $editor->forceFill(['password_set' => false])->save();
    $this->artisan('sender:user-create', ['email' => 'editor@example.com', '--organization' => 'other', '--role' => 'editor', '--password' => 'new-pass-123'])->assertSuccessful();
    expect($editor->fresh()->organization->slug)->toBe('other')
        ->and($editor->fresh()->password_set)->toBeTrue();
});
