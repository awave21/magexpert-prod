<?php

use App\Sender\Mail\PasswordResetMail;
use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use App\Sender\Models\User;
use App\Sender\Services\AuthService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->migrateSenderDatabase();
    Mail::fake();
    config(['sender.ui_url' => 'https://magsender.ru', 'sender.client.system_from_address' => 'noreply@magsender.ru',
        'sender.auth_mail.organization' => 'mag-expert', 'sender.auth_mail.password_reset_template' => 'magsender-password-reset']);
    $organization = Organization::create(['name' => 'MagExpert', 'slug' => 'mag-expert', 'is_active' => true]);
    $domain = $organization->domains()->create(['domain' => 'magsender.ru', 'verification_token' => 'test', 'dkim_selector' => 'mail', 'status' => Domain::STATUS_VERIFIED]);
    $sender = $organization->senderAddresses()->create(['domain_id' => $domain->id, 'email' => 'noreply@magsender.ru', 'name' => 'MagSender', 'confirmed_at' => now()]);
    $content = json_decode(file_get_contents(base_path('deploy/templates/magsender-password-reset.json')), true)[0];
    $this->content = $organization->templates()->create([...$content, 'sender_address_id' => $sender->id]);
    $this->account = User::create([
        'organization_id' => $organization->id, 'name' => 'Максим',
        'email' => 'admin@example.com', 'password' => 'old-password', 'is_active' => true,
    ]);
});

it('uses separate editable content for magsender recovery and leaves magexpert content unchanged', function (): void {
    $site = Template::create(['organization_id' => $this->account->organization_id, 'slug' => 'password-reset', 'name' => 'MagExpert', 'subject' => 'MagExpert only', 'body_html' => '<p>MagExpert only</p>']);
    config(['services.sender.templates.password_reset' => (string) $site->id]);
    $this->content->update(['subject' => 'Свой заголовок MagSender']);
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();

    $mail = Mail::queued(PasswordResetMail::class)->first();
    expect($mail->templateId)->toBe($this->content->id)->not->toBe($site->id)
        ->and($mail->content['subject'])->toBe('Свой заголовок MagSender')
        ->and($mail->content['html'])->toContain('magsender.ru/reset-password#')
        ->not->toContain('mag-expert.ru', 'MagExpert only', '{{')
        ->and($site->fresh()->body_html)->toBe('<p>MagExpert only</p>');
    $mail->assertSeeInHtml('MagSender');
});

it('does not let a tenant replace the platform recovery content', function (): void {
    $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    $other->templates()->create(['slug' => 'magsender-password-reset', 'name' => 'Other', 'subject' => 'Other content', 'body_html' => '<p>Other content</p>']);
    $this->account->update(['organization_id' => $other->id]);
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    expect(Mail::queued(PasswordResetMail::class)->first()->templateId)->toBe($this->content->id);
});

it('keeps using the configured content id after its alias changes', function (): void {
    config(['sender.auth_mail.password_reset_template' => (string) $this->content->id]);
    $this->content->update(['slug' => 'renamed-magsender-recovery']);
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    expect(Mail::queued(PasswordResetMail::class)->first()->templateId)->toBe($this->content->id);
});

it('does not fall back to magexpert content if magsender content is missing', function (): void {
    $this->content->delete();
    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email]))
        ->toThrow(RuntimeException::class, 'Не настроен отдельный шаблон');
    Mail::assertNothingQueued();
    expect(DB::connection('sender')->table('sender_password_resets')->count())->toBe(0);
});

function recoveryLink(): array
{
    $mail = Mail::queued(PasswordResetMail::class)->first();
    parse_str(parse_url($mail->url, PHP_URL_FRAGMENT), $parameters);

    return $parameters;
}

it('queues an encrypted recovery email without disclosing whether an account exists', function (): void {
    $known = $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => ' ADMIN@example.com '])->assertOk();
    $unknown = $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => 'absent@example.com'])->assertOk();
    expect($known->json())->toBe($unknown->json());
    Mail::assertQueued(PasswordResetMail::class, function ($mail): bool {
        return $mail instanceof ShouldBeEncrypted && $mail->hasTo('admin@example.com')
            && $mail->queue === 'sender-transactional' && str_starts_with($mail->url, 'https://magsender.ru/reset-password#');
    });
    $record = DB::connection('sender')->table('sender_password_resets')->first();
    expect($record->token_hash)->toBe(hash('sha256', recoveryLink()['token']))
        ->not->toBe(recoveryLink()['token']);
    Mail::assertQueuedCount(1);
});

it('throttles repeated emails and invalidates the previous link when another is issued', function (): void {
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    $first = recoveryLink();
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    Mail::assertQueuedCount(1);
    $this->travel(61)->seconds();
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    Mail::assertQueuedCount(2);
    $this->postJson('/api/sender/v1/admin/reset-password', [...$first, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
});

it('changes the password once and revokes existing login tokens', function (): void {
    app(AuthService::class)->issueToken($this->account);
    $this->account->update(['password_set' => false]);
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    $payload = [...recoveryLink(), 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    $this->postJson('/api/sender/v1/admin/reset-password', $payload)->assertOk();
    expect(Hash::check('new-password', $this->account->fresh()->password))->toBeTrue()
        ->and($this->account->fresh()->hasPassword())->toBeTrue()
        ->and($this->account->tokens()->count())->toBe(0);
    $this->postJson('/api/sender/v1/admin/reset-password', $payload)->assertUnprocessable();
    $this->postJson('/api/sender/v1/admin/login', ['email' => $this->account->email, 'password' => 'new-password'])->assertOk();
    $this->postJson('/api/sender/v1/admin/login', ['email' => $this->account->email, 'password' => 'old-password'])->assertUnprocessable();
});

it('rejects expired, wrong, or mismatched links without changing the password', function (string $invalid): void {
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    $link = recoveryLink();
    if ($invalid === 'expired') {
        $this->travel(60)->minutes();
    } elseif ($invalid === 'wrong') {
        $link['token'] = str_repeat('x', 64);
    } else {
        $link['email'] = 'another@example.com';
    }
    $this->postJson('/api/sender/v1/admin/reset-password', [...$link, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
    expect(Hash::check('old-password', $this->account->fresh()->password))->toBeTrue();
})->with(['expired', 'wrong', 'different email']);

it('does not issue or redeem links for inactive users or organizations', function (bool $inactiveOrganization): void {
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    $link = recoveryLink();
    Mail::fake();
    if ($inactiveOrganization) {
        $this->account->organization->update(['is_active' => false]);
    } else {
        $this->account->update(['is_active' => false]);
    }
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    Mail::assertNothingQueued();
    $this->postJson('/api/sender/v1/admin/reset-password', [...$link, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
})->with([false, true]);

it('validates the email, password length, confirmation and limits requests', function (): void {
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => 'invalid'])->assertUnprocessable();
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => $this->account->email])->assertOk();
    $link = recoveryLink();
    $this->postJson('/api/sender/v1/admin/reset-password', [...$link, 'password' => 'short', 'password_confirmation' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('password');
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => 'absent@example.com']);
    }
    $this->postJson('/api/sender/v1/admin/forgot-password', ['email' => 'absent@example.com'])->assertTooManyRequests();
    Mail::assertQueuedCount(1);
});
