<?php

use App\Sender\Models\Contact;
use App\Sender\Models\Organization;
use App\Sender\Services\ApiKeyService;

beforeEach(function (): void {
    $this->migrateSenderDatabase();

    $this->organization = Organization::create(['name' => 'MagExpert', 'slug' => 'magexpert']);
    $this->key = (new ApiKeyService)->create($this->organization, 'profile')['plain'];
    $this->base = '/api/sender/v1';
});

function contactProfileApi(): Tests\TestCase
{
    return test()->withToken(test()->key);
}

it('finds lists by name and creates them with a Location header', function (): void {
    contactProfileApi()->getJson("{$this->base}/lists?name=Врачи")->assertOk()->assertExactJson(['data' => []]);

    $created = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Врачи', 'description' => 'Все врачи'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Врачи');
    $id = $created->json('data.id');

    expect($created->headers->get('Location'))->toEndWith("/api/sender/v1/lists/{$id}");

    // повтор: название занято, в ответе ID существующей базы
    contactProfileApi()->postJson("{$this->base}/lists", ['name' => ' врачи '])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('list_id', $id);

    contactProfileApi()->getJson("{$this->base}/lists?name=ВРАЧИ")->assertOk()->assertJsonPath('data.0.id', $id);
    contactProfileApi()->getJson("{$this->base}/lists")->assertOk()->assertJsonCount(1, 'data');
});

it('keeps the deprecated list and contact methods working and marks them', function (): void {
    $list = contactProfileApi()->putJson("{$this->base}/lists", ['name' => 'Старый метод'])->assertCreated()
        ->assertHeader('Deprecation', 'true');

    contactProfileApi()->putJson("{$this->base}/lists/{$list->json('data.id')}/contacts", ['email' => 'anna@example.com', 'data' => ['city' => 'Москва']])
        ->assertCreated()
        ->assertHeader('Deprecation', 'true');

    contactProfileApi()->putJson("{$this->base}/subscriptions", ['email' => 'anna@example.com', 'subscribed' => true])
        ->assertOk()
        ->assertHeader('Deprecation', 'true');

    // каноничные методы не помечены устаревшими
    contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Новый метод'])->assertCreated()->assertHeaderMissing('Deprecation');
});

it('stores a profile addressed by email: base fields and extra attributes', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Анкеты'])->json('data.id');

    $created = contactProfileApi()->putJson("{$this->base}/lists/{$listId}/contacts/Anna%40Example.com", [
        'name' => 'Анна Иванова',
        'phone' => '8 (999) 123-45-67',
        'city' => ' Москва ',
        'attributes' => ['specialization' => 'Гинеколог', 'is_member' => true],
        'tags' => ['school'],
        'subscribed' => true,
    ])->assertCreated()
        ->assertJsonPath('data.email', 'anna@example.com')
        ->assertJsonPath('data.created', true);

    expect($created->headers->get('Location'))->toEndWith("/api/sender/v1/lists/{$listId}/contacts/anna%40example.com");

    $shown = contactProfileApi()->getJson("{$this->base}/lists/{$listId}/contacts/anna@example.com")->assertOk();

    expect($shown->json('data.name'))->toBe('Анна Иванова')
        ->and($shown->json('data.phone'))->toBe('+79991234567')
        ->and($shown->json('data.city'))->toBe('Москва')
        ->and($shown->json('data.attributes'))->toBe(['specialization' => 'Гинеколог', 'is_member' => '1'])
        // прежний data остаётся полным набором для совместимости
        ->and($shown->json('data.data'))->toMatchArray(['phone' => '+79991234567', 'city' => 'Москва', 'specialization' => 'Гинеколог']);
});

it('updates only the attributes that are passed and clears one with null', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Анкеты'])->json('data.id');
    $url = "{$this->base}/lists/{$listId}/contacts/anna@example.com";

    contactProfileApi()->putJson($url, ['phone' => '+79991234567', 'city' => 'Москва', 'attributes' => ['specialization' => 'Гинеколог', 'company' => 'Клиника']])->assertCreated();
    contactProfileApi()->putJson($url, ['city' => 'Казань', 'attributes' => ['company' => null]])->assertOk()->assertJsonPath('data.created', false);

    $contact = Contact::query()->where('email', 'anna@example.com')->firstOrFail();

    expect($contact->data)->toEqualCanonicalizing(['phone' => '+79991234567', 'city' => 'Казань', 'specialization' => 'Гинеколог'])
        ->and($contact->data)->not->toHaveKey('company');
});

it('rejects a profile that breaks the contract', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Анкеты'])->json('data.id');
    $url = "{$this->base}/lists/{$listId}/contacts/anna@example.com";

    contactProfileApi()->putJson($url, ['phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    contactProfileApi()->putJson($url, ['city' => str_repeat('я', 121)])->assertUnprocessable()->assertJsonValidationErrors('city');
    contactProfileApi()->putJson($url, ['attributes' => ['Bad-Key' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('attributes');
    // базовые поля в attributes передавать нельзя
    contactProfileApi()->putJson($url, ['attributes' => ['phone' => '+79991234567']])->assertUnprocessable()->assertJsonValidationErrors('attributes');
    contactProfileApi()->putJson($url, ['attributes' => ['nested' => ['a' => 1]]])->assertUnprocessable();
    // адрес в теле не может отличаться от адреса в пути
    contactProfileApi()->putJson($url, ['email' => 'other@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(Contact::query()->count())->toBe(0);
});

it('accepts the deprecated data object as a synonym of attributes and lets attributes win', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Анкеты'])->json('data.id');

    contactProfileApi()->putJson("{$this->base}/lists/{$listId}/contacts/anna@example.com", [
        'data' => ['specialization' => 'Старое', 'company' => 'Клиника'],
        'attributes' => ['specialization' => 'Новое'],
    ])->assertCreated();

    expect(Contact::query()->where('email', 'anna@example.com')->firstOrFail()->data)
        ->toEqualCanonicalizing(['specialization' => 'Новое', 'company' => 'Клиника']);
});

it('applies the same profile rules to every row of a batch', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Пакет'])->json('data.id');

    $response = contactProfileApi()->postJson("{$this->base}/lists/{$listId}/contacts/batch", ['contacts' => [
        ['email' => 'a@example.com', 'phone' => '8 999 000-11-22', 'city' => 'Москва', 'attributes' => ['company' => 'Клиника']],
        ['email' => 'b@example.com', 'phone' => 'abc'],
        ['email' => 'c@example.com', 'attributes' => ['email' => 'x@example.com']],
    ]])->assertOk();

    expect($response->json('data.created'))->toBe(1)
        ->and(collect($response->json('data.invalid'))->pluck('index')->all())->toBe([1, 2])
        ->and(Contact::query()->where('email', 'a@example.com')->firstOrFail()->data)
        ->toEqualCanonicalizing(['phone' => '+79990001122', 'city' => 'Москва', 'company' => 'Клиника']);
});

it('sets the subscription by the address in the path', function (): void {
    contactProfileApi()->putJson("{$this->base}/contacts/Anna%40Example.com/subscription", ['subscribed' => false])
        ->assertOk()
        ->assertExactJson(['data' => ['email' => 'anna@example.com', 'subscribed' => false]]);

    contactProfileApi()->putJson("{$this->base}/contacts/anna@example.com/subscription", [])->assertUnprocessable();
    contactProfileApi()->putJson("{$this->base}/contacts/not-an-email/subscription", ['subscribed' => true])->assertUnprocessable();
});

it('answers 409 for a base that is built automatically', function (): void {
    $list = $this->organization->lists()->create(['name' => 'Из источника', 'source' => 'site']);

    contactProfileApi()->putJson("{$this->base}/lists/{$list->id}/contacts/anna@example.com", ['name' => 'Анна'])
        ->assertStatus(409);
});

it('requires only the email: a contact is created with no other profile field', function (): void {
    $listId = contactProfileApi()->postJson("{$this->base}/lists", ['name' => 'Только адрес'])->json('data.id');

    // адрес в пути, тело пустое
    contactProfileApi()->putJson("{$this->base}/lists/{$listId}/contacts/only@example.com", [])
        ->assertCreated()
        ->assertJsonPath('data.email', 'only@example.com');

    // старый вид: в теле только email
    contactProfileApi()->putJson("{$this->base}/lists/{$listId}/contacts", ['email' => 'legacy@example.com'])
        ->assertCreated();

    $shown = contactProfileApi()->getJson("{$this->base}/lists/{$listId}/contacts/only@example.com")->assertOk();

    expect($shown->json('data.name'))->toBeNull()
        ->and($shown->json('data.phone'))->toBeNull()
        ->and($shown->json('data.city'))->toBeNull()
        ->and($shown->json('data.attributes'))->toBe([]);

    // в пакете строка с одним адресом тоже допустима
    contactProfileApi()->postJson("{$this->base}/lists/{$listId}/contacts/batch", ['contacts' => [['email' => 'batch@example.com']]])
        ->assertOk()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.invalid', []);
});
