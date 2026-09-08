<?php

use App\Enums\UserRole;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $role = Role::factory()->create(['slug' => UserRole::SuperAdmin->value, 'name' => 'Super Admin']);
  $this->superAdminUser = User::factory()->for($role)->create();
  $this->store = Store::factory()->create();
  $this->category = MenuCategory::create([
    'store_id' => $this->store->id, 'name' => 'Makanan', 'sort_order' => 1, 'is_active' => true,
  ]);
  $this->menuData = [
    'store_id' => $this->store->id,
    'menu_category_id' => $this->category->id,
    'name' => 'Nasi Goreng',
    'description' => 'Nasi goreng spesial',
    'price' => 25000,
    'sort_order' => 1,
    'is_available' => true,
  ];
});

test('Super admin can get paginated menus', function () {
  Sanctum::actingAs($this->superAdminUser);
  Menu::create($this->menuData);
  Menu::create([...$this->menuData, 'name' => 'Mie Goreng']);

  $response = $this->getJson('/api/v1/staff/menus?limit=1');
  $response->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('meta.total', 2)
    ->assertJsonPath('meta.per_page', 1)
    ->assertJsonPath('meta.last_page', 2)
    ->assertJsonPath('links.prev', null);
  expect($response->json('links.next'))->not->toBeNull();
});

test('menus can be searched by name', function () {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create($this->menuData);
  Menu::create([...$this->menuData, 'name' => 'Es Teh', 'description' => 'Minuman dingin']);

  // Regresi PostgreSQL: scopeSearch juga memakai ILIKE pada kolom price bertipe decimal.
  $this->getJson('/api/v1/staff/menus?search=nasi')
    ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $menu->id);
});

test('Super admin can create a menu', function () {
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->postJson('/api/v1/staff/menus', $this->menuData);
  $response->assertCreated()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Nasi Goreng')
    ->assertJsonPath('data.menu_category_id', $this->category->id);

  $this->assertDatabaseHas('menus', ['id' => $response->json('data.id'), ...$this->menuData]);
});

test('menu can be created with an image', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->postJson('/api/v1/staff/menus', [
    ...$this->menuData, 'image' => UploadedFile::fake()->image('menu.jpg'),
  ])->assertCreated();

  $menu = Menu::findOrFail($response->json('data.id'));
  expect($menu->image)->toStartWith('images/menus/');
  Storage::disk('public')->assertExists($menu->image);
});

test('menu creation requires all required fields', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/menus', [])
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['store_id', 'menu_category_id', 'name', 'price', 'sort_order', 'is_available']);

  $this->assertDatabaseCount('menus', 0);
});

test('menu creation rejects invalid input', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/menus', [...$this->menuData, ...$changes])
    ->assertUnprocessable()->assertJsonValidationErrors([$field]);

  $this->assertDatabaseCount('menus', 0);
})->with([
  'invalid store' => [['store_id' => 'invalid'], 'store_id'],
  'invalid category' => [['menu_category_id' => 'invalid'], 'menu_category_id'],
  'long name' => [['name' => str_repeat('a', 256)], 'name'],
  'invalid price' => [['price' => 'invalid'], 'price'],
  'invalid sort order' => [['sort_order' => 'invalid'], 'sort_order'],
  'invalid status' => [['is_available' => 'invalid'], 'is_available'],
]);

test('menu upload rejects non images and oversized images', function (string $kind) {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $file = $kind === 'text'
    ? UploadedFile::fake()->create('menu.txt', 1, 'text/plain')
    : UploadedFile::fake()->image('menu.jpg')->size(1025);

  $this->postJson('/api/v1/staff/menus', [...$this->menuData, 'image' => $file])
    ->assertUnprocessable()->assertJsonValidationErrors(['image']);

  $this->assertDatabaseCount('menus', 0);
  expect(Storage::disk('public')->allFiles())->toBeEmpty();
})->with(['text', 'oversized']);

test('Super admin can see menu details with store and category', function () {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create($this->menuData);

  $this->getJson("/api/v1/staff/menus/{$menu->id}")
    ->assertOk()
    ->assertJsonPath('data.id', $menu->id)
    ->assertJsonPath('data.name', 'Nasi Goreng')
    ->assertJsonPath('data.store.id', $this->store->id)
    ->assertJsonPath('data.category.id', $this->category->id);
});

test('Super admin can update a menu', function () {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create($this->menuData);
  $data = [...$this->menuData, 'name' => 'Nasi Goreng Seafood', 'price' => 35000, 'is_available' => false];

  $this->patchJson("/api/v1/staff/menus/{$menu->id}", $data)
    ->assertOk()->assertJsonPath('data.name', 'Nasi Goreng Seafood');

  $this->assertDatabaseHas('menus', ['id' => $menu->id, ...$data]);
});

test('menu update rejects invalid input without changing existing data', function () {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create($this->menuData);
  $original = $menu->fresh()->getAttributes();

  $this->patchJson("/api/v1/staff/menus/{$menu->id}", [
    ...$this->menuData, 'name' => '', 'price' => 'invalid',
  ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'price']);

  expect($menu->fresh()->getAttributes())->toBe($original);
});

test('menu image replacement deletes the old image', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $oldImage = 'images/menus/old.jpg';
  Storage::disk('public')->put($oldImage, 'old image');
  $menu = Menu::create([...$this->menuData, 'image' => $oldImage]);

  $this->patchJson("/api/v1/staff/menus/{$menu->id}", [
    ...$this->menuData, 'image' => UploadedFile::fake()->image('new.png'),
  ])->assertOk();

  expect($menu->fresh()->image)->not->toBe($oldImage);
  Storage::disk('public')->assertMissing($oldImage);
  Storage::disk('public')->assertExists($menu->fresh()->image);
});

test('menu update without an image preserves the existing file', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $image = 'images/menus/existing.jpg';
  Storage::disk('public')->put($image, 'image');
  $menu = Menu::create([...$this->menuData, 'image' => $image]);

  $this->patchJson("/api/v1/staff/menus/{$menu->id}", $this->menuData)->assertOk();

  expect($menu->fresh()->image)->toBe($image);
  Storage::disk('public')->assertExists($image);
});

test('menu can be deleted together with its image', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $image = 'images/menus/deleted.jpg';
  Storage::disk('public')->put($image, 'image');
  $menu = Menu::create([...$this->menuData, 'image' => $image]);
  $otherMenu = Menu::create([...$this->menuData, 'name' => 'Mie Goreng']);

  $this->deleteJson("/api/v1/staff/menus/{$menu->id}")
    ->assertOk()->assertJsonPath('success', true);

  $this->assertDatabaseMissing('menus', ['id' => $menu->id]);
  $this->assertDatabaseHas('menus', ['id' => $otherMenu->id]);
  Storage::disk('public')->assertMissing($image);
});

test('menu availability can be changed', function (bool $available) {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create([...$this->menuData, 'is_available' => !$available]);

  $this->patchJson("/api/v1/staff/menus/{$menu->id}/status-available", ['is_available' => $available])
    ->assertOk()->assertJsonPath('success', true);

  $this->assertDatabaseHas('menus', ['id' => $menu->id, ...$this->menuData, 'is_available' => $available]);
})->with(['available' => [true], 'unavailable' => [false]]);

test('menu availability requires a boolean value', function (array $payload) {
  Sanctum::actingAs($this->superAdminUser);
  $menu = Menu::create($this->menuData);

  $this->patchJson("/api/v1/staff/menus/{$menu->id}/status-available", $payload)
    ->assertUnprocessable()->assertJsonValidationErrors(['is_available']);

  $this->assertDatabaseHas('menus', ['id' => $menu->id, 'is_available' => true]);
})->with(['missing' => [[]], 'invalid' => [['is_available' => 'invalid']]]);

test('Owner can create a menu for a linked store', function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $owner = User::factory()->for($role)->create(['store_id' => $this->store->id]);
  $owner->stores()->attach($this->store->id);
  Sanctum::actingAs($owner);

  $this->postJson('/api/v1/staff/menus', $this->menuData)->assertCreated();
  $this->assertDatabaseHas('menus', $this->menuData);
});

test('menu endpoints return 404 for a missing menu', function (string $method, string $suffix) {
  Sanctum::actingAs($this->superAdminUser);
  $this->json($method, '/api/v1/staff/menus/0' . $suffix, $this->menuData)->assertNotFound();
})->with([
  'detail' => ['GET', ''], 'update' => ['PATCH', ''],
  'delete' => ['DELETE', ''], 'status' => ['PATCH', '/status-available'],
]);

test('guest cannot access menu endpoints', function (string $method, string $suffix) {
  $this->json($method, '/api/v1/staff/menus' . $suffix, $this->menuData)->assertUnauthorized();
})->with([
  'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/1'],
  'update' => ['PATCH', '/1'], 'delete' => ['DELETE', '/1'], 'status' => ['PATCH', '/1/status-available'],
]);

test('roles other than Owner and Super admin cannot manage menus', function (UserRole $role) {
  $userRole = Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());
  $menu = Menu::create($this->menuData);
  $original = $menu->fresh()->getAttributes();

  $this->getJson('/api/v1/staff/menus')->assertForbidden();
  $this->postJson('/api/v1/staff/menus', $this->menuData)->assertForbidden();
  $this->getJson("/api/v1/staff/menus/{$menu->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/menus/{$menu->id}", $this->menuData)->assertForbidden();
  $this->deleteJson("/api/v1/staff/menus/{$menu->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/menus/{$menu->id}/status-available", ['is_available' => false])->assertForbidden();

  expect($menu->fresh()->getAttributes())->toBe($original);
  $this->assertDatabaseCount('menus', 1);
})->with([UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);
