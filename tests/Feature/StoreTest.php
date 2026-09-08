<?php

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $superAdminRole = Role::factory()->create([
    'slug' => UserRole::SuperAdmin->value,
    'name' => 'Super Admin',
  ]);
  $this->superAdminUser = User::factory()->for($superAdminRole)->create();
  $this->ownerRole = Role::factory()->create([
    'slug' => UserRole::Owner->value,
    'name' => 'Owner',
  ]);
  $this->owner = User::factory()->for($this->ownerRole)->create();

  // Permission dibuat langsung agar test tidak bergantung pada seeder.
  foreach (['store.view', 'store.update'] as $slug) {
    $permission = new Permission();
    $permission->name = $slug;
    $permission->slug = $slug;
    $permission->save();
    $this->ownerRole->permissions()->attach($permission->id);
  }

  $this->storeData = [
    'name' => 'Toko Baru',
    'description' => 'Deskripsi toko untuk pengujian',
    'address' => 'Jalan Darussalam 4',
    'phone' => '085334346667',
    'is_active' => true,
  ];
});

test('Super admin can get paginated stores', function () {
  Sanctum::actingAs($this->superAdminUser);
  Store::factory()->count(5)->create();

  $response = $this->getJson('/api/v1/staff/stores?limit=2');
  $response->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonCount(2, 'data')
    ->assertJsonPath('meta.total', 5)
    ->assertJsonPath('meta.current_page', 1)
    ->assertJsonPath('meta.per_page', 2)
    ->assertJsonPath('meta.last_page', 3)
    ->assertJsonPath('links.prev', null);

  expect($response->json('links.next'))->not->toBeNull();
});

test('Super admin can search stores', function (string $search) {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create([
    'name' => 'Unique Search Resto',
    'address' => 'Unique Search Street',
  ]);
  Store::factory()->create(['name' => 'Other Resto', 'address' => 'Other Street']);

  $this->getJson('/api/v1/staff/stores?' . http_build_query(['search' => $search]))
    ->assertOk()
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('data.0.id', $store->id)
    ->assertJsonPath('meta.total', 1);
})->with(['name' => 'unique search resto', 'address' => 'unique search street']);

test('Owner only sees linked stores', function () {
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create();
  $this->owner->stores()->attach($store->id);
  Store::factory()->create();

  $this->getJson('/api/v1/staff/stores')
    ->assertOk()
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('data.0.id', $store->id)
    ->assertJsonPath('meta.total', 1);
});

test('Super admin can create a store with an automatically generated slug', function () {
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->postJson('/api/v1/staff/stores', $this->storeData);
  $response->assertCreated()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Toko Baru')
    ->assertJsonPath('data.slug', 'toko-baru');

  $this->assertDatabaseHas('stores', [
    'id' => $response->json('data.id'),
    ...$this->storeData,
    'slug' => 'toko-baru',
  ]);
});

test('store creation requires name and active status', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/stores', [])
    ->assertUnprocessable()->assertJsonValidationErrors(['name', 'is_active']);

  $this->assertDatabaseCount('stores', 0);
});

test('store creation rejects invalid input', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/stores', [...$this->storeData, ...$changes])
    ->assertUnprocessable()->assertJsonValidationErrors([$field]);

  $this->assertDatabaseCount('stores', 0);
})->with([
  'name too long' => [['name' => str_repeat('a', 101)], 'name'],
  'description too long' => [['description' => str_repeat('a', 256)], 'description'],
  'phone too long' => [['phone' => str_repeat('1', 21)], 'phone'],
  'invalid status' => [['is_active' => 'invalid'], 'is_active'],
]);

test('Super admin can see store details', function () {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create();

  $this->getJson("/api/v1/staff/stores/{$store->id}")
    ->assertOk()
    ->assertJsonPath('data.id', $store->id)
    ->assertJsonPath('data.name', $store->name)
    ->assertJsonPath('data.address', $store->address);
});

test('Super admin can update a store', function () {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create();
  $data = [
    'name' => 'Toko Diperbarui',
    'description' => 'Deskripsi diperbarui',
    'address' => 'Jalan Baru',
    'phone' => '081234567890',
    'latitude' => '-6.20000000',
    'longitude' => '106.81666600',
  ];

  $this->patchJson("/api/v1/staff/stores/{$store->id}", $data)
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Toko Diperbarui');

  $this->assertDatabaseHas('stores', ['id' => $store->id, ...$data]);
});

test('store update requires name and description', function () {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create();
  $original = $store->fresh()->getAttributes();

  $this->patchJson("/api/v1/staff/stores/{$store->id}", [])
    ->assertUnprocessable()->assertJsonValidationErrors(['name', 'description']);

  expect($store->fresh()->getAttributes())->toBe($original);
});

test('store update rejects invalid input', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create();
  $original = $store->fresh()->getAttributes();

  $this->patchJson("/api/v1/staff/stores/{$store->id}", [...$this->storeData, ...$changes])
    ->assertUnprocessable()->assertJsonValidationErrors([$field]);

  expect($store->fresh()->getAttributes())->toBe($original);
})->with([
  'name too long' => [['name' => str_repeat('a', 256)], 'name'],
  'phone too long' => [['phone' => str_repeat('1', 16)], 'phone'],
  'invalid image' => [['image' => 'not-an-image'], 'image'],
  'invalid banner' => [['banner' => 'not-an-image'], 'banner'],
]);

test('store images can be replaced and old files are deleted', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $oldImage = 'images/stores/profile/old.jpg';
  $oldBanner = 'images/stores/banner/old.jpg';
  Storage::disk('public')->put($oldImage, 'old image');
  Storage::disk('public')->put($oldBanner, 'old banner');
  $store = Store::factory()->create(['image' => $oldImage, 'banner' => $oldBanner]);

  $this->patchJson("/api/v1/staff/stores/{$store->id}", [
    ...$this->storeData,
    'image' => UploadedFile::fake()->image('profile.jpg'),
    'banner' => UploadedFile::fake()->image('banner.png'),
  ])->assertOk();

  $store->refresh();
  expect($store->image)->not->toBe($oldImage);
  expect($store->banner)->not->toBe($oldBanner);
  Storage::disk('public')->assertExists([$store->image, $store->banner]);
  Storage::disk('public')->assertMissing([$oldImage, $oldBanner]);
});

test('store update without uploads preserves existing images', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $image = 'images/stores/profile/existing.jpg';
  $banner = 'images/stores/banner/existing.jpg';
  Storage::disk('public')->put($image, 'image');
  Storage::disk('public')->put($banner, 'banner');
  $store = Store::factory()->create(['image' => $image, 'banner' => $banner]);

  $this->patchJson("/api/v1/staff/stores/{$store->id}", $this->storeData)->assertOk();

  $this->assertDatabaseHas('stores', ['id' => $store->id, 'image' => $image, 'banner' => $banner]);
  Storage::disk('public')->assertExists([$image, $banner]);
});

test('Super admin can delete a store and its images', function () {
  Storage::fake('public');
  Sanctum::actingAs($this->superAdminUser);
  $image = 'images/stores/profile/deleted.jpg';
  $banner = 'images/stores/banner/deleted.jpg';
  Storage::disk('public')->put($image, 'image');
  Storage::disk('public')->put($banner, 'banner');
  $store = Store::factory()->create(['image' => $image, 'banner' => $banner]);
  $otherStore = Store::factory()->create();

  $this->deleteJson("/api/v1/staff/stores/{$store->id}")
    ->assertOk()->assertJsonPath('success', true);

  $this->assertDatabaseMissing('stores', ['id' => $store->id]);
  $this->assertDatabaseHas('stores', ['id' => $otherStore->id]);
  Storage::disk('public')->assertMissing([$image, $banner]);
});

test('Super admin can change store statuses', function (string $endpoint, string $field, bool $value) {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create([$field => !$value]);

  $this->patchJson("/api/v1/staff/stores/{$store->id}/{$endpoint}", [$field => $value])
    ->assertOk()->assertJsonPath('success', true);

  $this->assertDatabaseHas('stores', ['id' => $store->id, $field => $value]);
})->with([
  'activate' => ['status', 'is_active', true],
  'deactivate' => ['status', 'is_active', false],
  'open' => ['status-operational', 'is_open', true],
  'close' => ['status-operational', 'is_open', false],
  'accept orders' => ['status-order', 'is_accept_order', true],
  'stop orders' => ['status-order', 'is_accept_order', false],
]);

test('store statuses require boolean values', function (string $endpoint, string $field) {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create([$field => true]);

  foreach ([[], [$field => 'invalid']] as $payload) {
    $this->patchJson("/api/v1/staff/stores/{$store->id}/{$endpoint}", $payload)
      ->assertUnprocessable()->assertJsonValidationErrors([$field]);
  }

  $this->assertDatabaseHas('stores', ['id' => $store->id, $field => true]);
})->with([
  'active' => ['status', 'is_active'],
  'operational' => ['status-operational', 'is_open'],
  'order' => ['status-order', 'is_accept_order'],
]);

test('store options only contain active stores', function () {
  Sanctum::actingAs($this->superAdminUser);
  $store = Store::factory()->create(['is_active' => true]);
  Store::factory()->create(['is_active' => false]);

  $this->getJson('/api/v1/staff/stores/options')
    ->assertOk()
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('data.0.id', $store->id);
});

test('Owner can view and update a linked store with permissions', function () {
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create();
  $this->owner->stores()->attach($store->id);

  $this->getJson("/api/v1/staff/stores/{$store->id}")
    ->assertOk()->assertJsonPath('data.id', $store->id);
  $this->patchJson("/api/v1/staff/stores/{$store->id}", $this->storeData)->assertOk();

  $this->assertDatabaseHas('stores', ['id' => $store->id, 'name' => 'Toko Baru']);
});

test('Owner without permissions cannot list view or update linked stores', function () {
  $this->ownerRole->permissions()->detach();
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create();
  $this->owner->stores()->attach($store->id);
  $original = $store->fresh()->getAttributes();

  $this->getJson('/api/v1/staff/stores')->assertForbidden();
  $this->getJson("/api/v1/staff/stores/{$store->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}", $this->storeData)->assertForbidden();

  expect($store->fresh()->getAttributes())->toBe($original);
});

test('Owner cannot view or modify an unlinked store', function () {
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create();
  $original = $store->fresh()->getAttributes();

  $this->getJson("/api/v1/staff/stores/{$store->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}", $this->storeData)->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status-operational", ['is_open' => false])
    ->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status-order", ['is_accept_order' => false])
    ->assertForbidden();

  expect($store->fresh()->getAttributes())->toBe($original);
});

test('Owner cannot create delete or change activation of a store', function () {
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create(['is_active' => true]);
  $this->owner->stores()->attach($store->id);

  $this->postJson('/api/v1/staff/stores', $this->storeData)->assertForbidden();
  $this->deleteJson("/api/v1/staff/stores/{$store->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status", ['is_active' => false])
    ->assertForbidden();

  $this->assertDatabaseCount('stores', 1);
  $this->assertDatabaseHas('stores', ['id' => $store->id, 'is_active' => true]);
});

test('Owner can change operational and order statuses of a linked store', function (string $endpoint, string $field) {
  Sanctum::actingAs($this->owner);
  $store = Store::factory()->create([$field => true]);
  $this->owner->stores()->attach($store->id);

  $this->patchJson("/api/v1/staff/stores/{$store->id}/{$endpoint}", [$field => false])->assertOk();

  $this->assertDatabaseHas('stores', ['id' => $store->id, $field => false]);
})->with([
  'operational' => ['status-operational', 'is_open'],
  'order' => ['status-order', 'is_accept_order'],
]);

test('store endpoints return 404 for a missing store', function (string $method, string $suffix) {
  Sanctum::actingAs($this->superAdminUser);

  $this->json($method, '/api/v1/staff/stores/0' . $suffix, $this->storeData)->assertNotFound();
})->with([
  'detail' => ['GET', ''],
  'update' => ['PATCH', ''],
  'delete' => ['DELETE', ''],
  'active' => ['PATCH', '/status'],
  'operational' => ['PATCH', '/status-operational'],
  'order' => ['PATCH', '/status-order'],
]);

test('guest cannot access store endpoints', function (string $method, string $suffix) {
  $this->json($method, '/api/v1/staff/stores' . $suffix, $this->storeData)->assertUnauthorized();
})->with([
  'list' => ['GET', ''],
  'create' => ['POST', ''],
  'detail' => ['GET', '/1'],
  'update' => ['PATCH', '/1'],
  'delete' => ['DELETE', '/1'],
  'options' => ['GET', '/options'],
  'active' => ['PATCH', '/1/status'],
  'operational' => ['PATCH', '/1/status-operational'],
  'order' => ['PATCH', '/1/status-order'],
]);

test('roles other than Owner and Super admin cannot manage stores', function (UserRole $role) {
  $userRole = Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());
  $store = Store::factory()->create();
  $original = $store->fresh()->getAttributes();

  $this->getJson('/api/v1/staff/stores')->assertForbidden();
  $this->postJson('/api/v1/staff/stores', $this->storeData)->assertForbidden();
  $this->getJson("/api/v1/staff/stores/{$store->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}", $this->storeData)->assertForbidden();
  $this->deleteJson("/api/v1/staff/stores/{$store->id}")->assertForbidden();
  $this->getJson('/api/v1/staff/stores/options')->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status", ['is_active' => false])->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status-operational", ['is_open' => false])->assertForbidden();
  $this->patchJson("/api/v1/staff/stores/{$store->id}/status-order", ['is_accept_order' => false])->assertForbidden();

  expect($store->fresh()->getAttributes())->toBe($original);
  $this->assertDatabaseCount('stores', 1);
})->with([UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);