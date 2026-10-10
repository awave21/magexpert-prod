<?php

use App\Models\Category;
use App\Models\MedicalLibrary;
use App\Models\Partner;
use App\Models\Role;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function staffWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

    return $user;
}

test('страницы создания открываются отдельными страницами', function (string $url, string $component) {
    $this->actingAs(staffWithRole('admin'))->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'спикер' => ['/admin/speakers/create', 'Admin/SpeakerForm'],
    'партнёр' => ['/admin/partners/create', 'Admin/PartnerForm'],
    'материал' => ['/admin/medical-library/create', 'Admin/MedicalLibraryForm'],
    'категория' => ['/admin/categories/create', 'Admin/CategoryForm'],
    'пользователь' => ['/admin/users/create', 'Admin/UserForm'],
]);

test('страницы редактирования открываются с данными', function () {
    $admin = staffWithRole('admin');
    $speaker = Speaker::factory()->create();
    $partner = Partner::factory()->create();
    $item = MedicalLibrary::factory()->create();
    $category = Category::factory()->create();
    $user = User::factory()->create(['first_name' => 'Анна']);

    $this->actingAs($admin);
    $this->get("/admin/speakers/{$speaker->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/SpeakerForm')->where('speaker.id', $speaker->id));
    $this->get("/admin/partners/{$partner->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/PartnerForm')->where('partner.id', $partner->id));
    $this->get("/admin/medical-library/{$item->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/MedicalLibraryForm')->where('item.id', $item->id));
    $this->get("/admin/categories/{$category->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/CategoryForm')->where('category.id', $category->id));
    $this->get("/admin/users/{$user->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Admin/UserForm')->where('user.first_name', 'Анна'));
});

test('старый адрес карточки спикера ведёт на редактирование', function () {
    $speaker = Speaker::factory()->create();

    $this->actingAs(staffWithRole('editor'))->get("/admin/speakers/{$speaker->id}")
        ->assertRedirect("/admin/speakers/{$speaker->id}/edit");
});

test('спикер сохраняется только с разрешёнными полями', function () {
    $speaker = Speaker::factory()->create(['first_name' => 'Ирина']);

    $this->actingAs(staffWithRole('editor'))->put("/admin/speakers/{$speaker->id}", [
        'first_name' => 'Мария',
        'last_name' => $speaker->last_name,
        'is_active' => false,
        'sort_order' => 3,
        'photo' => '/storage/чужой-путь.webp',
    ])->assertRedirect("/admin/speakers/{$speaker->id}/edit")
        ->assertSessionHas('success');

    $speaker->refresh();
    expect($speaker->first_name)->toBe('Мария')
        ->and($speaker->is_active)->toBeFalse()
        ->and($speaker->photo)->not->toBe('/storage/чужой-путь.webp');
});

test('партнёр сохраняется без повторной загрузки логотипа', function () {
    $partner = Partner::factory()->create(['logo_path' => 'partners/1/logo.webp']);

    $this->actingAs(staffWithRole('editor'))->put("/admin/partners/{$partner->id}", [
        'name' => 'МедАльянсГрупп',
        'website_url' => 'https://medalliance-group.ru',
    ])->assertSessionHasNoErrors()
        ->assertRedirect("/admin/partners/{$partner->id}/edit");

    expect($partner->refresh())
        ->name->toBe('МедАльянсГрупп')
        ->logo_path->toBe('partners/1/logo.webp');
});

test('новый партнёр без логотипа не создаётся', function () {
    $this->actingAs(staffWithRole('editor'))->post('/admin/partners', ['name' => 'Без логотипа'])
        ->assertSessionHasErrors('logo');

    expect(Partner::count())->toBe(0);
});

test('материал библиотеки сохраняется без замены файла и обложки', function () {
    $item = MedicalLibrary::factory()->create(['file_path' => 'medical-library/1/files/a.pdf']);

    $this->actingAs(staffWithRole('editor'))->put("/admin/medical-library/{$item->id}", [
        'title' => 'Новое название',
        'publication_date' => '2026-09-01',
        'language' => 'ru',
        'delete_file' => 1,
    ])->assertSessionHasNoErrors()
        ->assertRedirect("/admin/medical-library/{$item->id}/edit");

    expect($item->refresh())
        ->title->toBe('Новое название')
        ->file_path->toBe('medical-library/1/files/a.pdf');
});

test('файл материала можно заменить', function () {
    Storage::fake('public');
    $item = MedicalLibrary::factory()->create();

    $this->actingAs(staffWithRole('editor'))->put("/admin/medical-library/{$item->id}", [
        'title' => $item->title,
        'publication_date' => '2026-09-01',
        'language' => 'en',
        'file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->file_path)->toStartWith("medical-library/{$item->id}/files/guide")
        ->and($item->language)->toBe('en');
    Storage::disk('public')->assertExists($item->file_path);
});

test('категория получает свободный адрес из названия', function () {
    Category::factory()->create(['name' => 'Эстетика', 'slug' => 'estetika']);

    $this->actingAs(staffWithRole('editor'))->post('/admin/categories', [
        'name' => 'Эстетика',
        'is_active' => true,
        'sort_order' => 0,
    ])->assertRedirect('/admin/categories')
        ->assertSessionHas('success', 'Категория создана');

    expect(Category::pluck('slug')->all())->toContain('estetika', 'estetika-2');
});

test('после сохранения категория остаётся на своей странице', function () {
    $category = Category::factory()->create();

    $this->actingAs(staffWithRole('editor'))->put("/admin/categories/{$category->id}", [
        'name' => 'Новое имя',
        'slug' => $category->slug,
        'is_active' => true,
        'sort_order' => 1,
    ])->assertRedirect("/admin/categories/{$category->id}/edit");

    expect($category->refresh()->name)->toBe('Новое имя');
});

test('созданный пользователь открывается в карточке', function () {
    $this->actingAs(staffWithRole('manager'))->post('/admin/users', [
        'first_name' => 'Ольга',
        'last_name' => 'Петрова',
        'email' => 'olga@example.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertSessionHasNoErrors()
        ->assertRedirectContains('/admin/users/');

    $user = User::where('email', 'olga@example.test')->firstOrFail();
    expect($user->first_name)->toBe('Ольга');
    expect(session('success'))->toBe('Пользователь создан');
});

test('после удаления пользователя админка возвращает к списку', function () {
    $user = User::factory()->create();

    $this->actingAs(staffWithRole('admin'))->delete("/admin/users/{$user->id}")
        ->assertRedirect('/admin/users')
        ->assertSessionHas('success');
});

test('редактор не открывает создание пользователя', function () {
    $this->actingAs(staffWithRole('editor'))->get('/admin/users/create')->assertForbidden();
});

test('менеджер не редактирует администратора', function () {
    $admin = staffWithRole('admin');

    $this->actingAs(staffWithRole('manager'))->get("/admin/users/{$admin->id}/edit")->assertForbidden();
});
