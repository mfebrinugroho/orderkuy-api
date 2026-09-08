<?php

use App\Enums\UserRole;
use App\Models\MenuCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $superAdminRole = Role::factory()->create([
    'slug' => UserRole::SuperAdmin->value,
    'name' => 'Super Admin',
  ]);

  $this->superAdminUser = User::factory()
    ->for($superAdminRole)
    ->create();

  $this->store = Store::factory()->create();

  $this->categoryData = [
    'store_id' => $this->store->id,
    'name' => 'Makanan',
    'sort_order' => 1,
    'is_active' => true,
  ];
});

test('Super admin can get paginated menu categories from all stores', function () {
  Sanctum::actingAs($this->superAdminUser);

  MenuCategory::create($this->categoryData);
  MenuCategory::create([
    ...$this->categoryData,
    'store_id' => Store::factory()->create()->id,
    'name' => 'Minuman',
  ]);

  $this->getJson('/api/v1/staff/menu-categories?limit=1')
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('meta.total', 2)
    ->assertJsonPath('meta.per_page', 1)
    ->assertJsonPath('meta.last_page', 2);
});

test('Owner only gets menu categories from the active store', function () {
  $ownerRole = Role::factory()->create([
    'slug' => UserRole::Owner->value,
    'name' => 'Owner',
  ]);

  $owner = User::factory()->for($ownerRole)->create([
    'store_id' => $this->store->id,
  ]);
  $otherStore = Store::factory()->create();
  $owner->stores()->attach([$this->store->id, $otherStore->id]);

  $category = MenuCategory::create($this->categoryData);
  MenuCategory::create([
    ...$this->categoryData,
    'store_id' => $otherStore->id,
    'name' => 'Kategori Toko Lain',
  ]);

  Sanctum::actingAs($owner);

  $this->getJson('/api/v1/staff/menu-categories')
    ->assertOk()
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('data.0.id', $category->id)
    ->assertJsonPath('meta.total', 1);
});

test('menu category can be created', function () {
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->postJson('/api/v1/staff/menu-categories', $this->categoryData);

  $response
    ->assertCreated()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Makanan')
    ->assertJsonPath('data.store_id', $this->store->id);

  $this->assertDatabaseHas('menu_categories', [
    'id' => $response->json('data.id'),
    ...$this->categoryData,
  ]);
});

test('menu category creation requires all required fields', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/menu-categories', [])
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['store_id', 'name', 'sort_order', 'is_active']);

  $this->assertDatabaseCount('menu_categories', 0);
});

test('menu category can be updated', function () {
  Sanctum::actingAs($this->superAdminUser);

  $category = MenuCategory::create($this->categoryData);
  $updatedData = [
    ...$this->categoryData,
    'name' => 'Makanan Utama',
    'sort_order' => 2,
    'is_active' => false,
  ];

  $this->patchJson("/api/v1/staff/menu-categories/{$category->id}", $updatedData)
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.id', $category->id)
    ->assertJsonPath('data.name', 'Makanan Utama');

  $this->assertDatabaseHas('menu_categories', [
    'id' => $category->id,
    ...$updatedData,
  ]);
});

test('menu category update rejects a store that does not exist', function () {
  Sanctum::actingAs($this->superAdminUser);

  $category = MenuCategory::create($this->categoryData);
  $deletedStore = Store::factory()->create();
  $deletedStore->delete();

  $this->patchJson("/api/v1/staff/menu-categories/{$category->id}", [
    ...$this->categoryData,
    'store_id' => $deletedStore->id,
    'name' => 'Tidak Tersimpan',
  ])
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['store_id']);

  $this->assertDatabaseHas('menu_categories', [
    'id' => $category->id,
    ...$this->categoryData,
  ]);
});

test('menu category can be deleted', function () {
  Sanctum::actingAs($this->superAdminUser);

  $category = MenuCategory::create($this->categoryData);
  $otherCategory = MenuCategory::create([
    ...$this->categoryData,
    'name' => 'Minuman',
  ]);

  $this->deleteJson("/api/v1/staff/menu-categories/{$category->id}")
    ->assertOk()
    ->assertJsonPath('success', true);

  $this->assertDatabaseMissing('menu_categories', ['id' => $category->id]);
  $this->assertDatabaseHas('menu_categories', ['id' => $otherCategory->id]);
});

test('menu category status can be changed', function (bool $isActive) {
  Sanctum::actingAs($this->superAdminUser);

  $category = MenuCategory::create([
    ...$this->categoryData,
    'is_active' => !$isActive,
  ]);

  $this->patchJson("/api/v1/staff/menu-categories/{$category->id}/status", [
    'is_active' => $isActive,
  ])
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.id', $category->id);

  $this->assertDatabaseHas('menu_categories', [
    'id' => $category->id,
    ...$this->categoryData,
    'is_active' => $isActive,
  ]);
})->with([
  'activate' => [true],
  'deactivate' => [false],
]);

test('menu category status requires a boolean value', function (array $payload) {
  Sanctum::actingAs($this->superAdminUser);

  $category = MenuCategory::create($this->categoryData);

  $this->patchJson("/api/v1/staff/menu-categories/{$category->id}/status", $payload)
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['is_active']);

  $this->assertDatabaseHas('menu_categories', [
    'id' => $category->id,
    'is_active' => true,
  ]);
})->with([
  'missing status' => [[]],
  'invalid status' => [['is_active' => 'invalid']],
]);

test('menu category options are filtered by store and sorted by name', function () {
  Sanctum::actingAs($this->superAdminUser);

  $secondCategory = MenuCategory::create([
    ...$this->categoryData,
    'name' => 'Minuman',
  ]);
  $firstCategory = MenuCategory::create($this->categoryData);
  MenuCategory::create([
    ...$this->categoryData,
    'store_id' => Store::factory()->create()->id,
    'name' => 'Kategori Toko Lain',
  ]);

  $this->getJson("/api/v1/staff/menu-categories/options?store_id={$this->store->id}")
    ->assertOk()
    ->assertJsonCount(2, 'data')
    ->assertJsonPath('data.0.id', $firstCategory->id)
    ->assertJsonPath('data.1.id', $secondCategory->id);
});

test('menu category options require a store', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->getJson('/api/v1/staff/menu-categories/options')
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['store_id']);
});

test('guest cannot get menu categories', function () {
  $this->getJson('/api/v1/staff/menu-categories')
    ->assertUnauthorized();
});

test('Customer cannot create a menu category', function () {
  $customerRole = Role::factory()->create([
    'slug' => UserRole::Customer->value,
    'name' => 'Customer',
  ]);

  Sanctum::actingAs(User::factory()->for($customerRole)->create());

  $this->postJson('/api/v1/staff/menu-categories', $this->categoryData)
    ->assertForbidden();

  $this->assertDatabaseCount('menu_categories', 0);
});