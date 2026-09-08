<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $role = Role::factory()->create(['slug' => UserRole::SuperAdmin->value, 'name' => 'Super Admin']);
  $this->superAdminUser = User::factory()->for($role)->create();
  $this->ownerRole = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $this->owner = User::factory()->for($this->ownerRole)->create();
  $this->store = Store::factory()->create();
  $this->ownerData = ['store_id' => $this->store->id, 'user_id' => $this->owner->id];
});

test('Super admin can assign an Owner to a store', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.id', $this->store->id)
    ->assertJsonPath('data.owner.id', $this->owner->id);

  $this->assertDatabaseHas('store_users', $this->ownerData);
  $this->assertDatabaseCount('store_users', 1);
});

test('an Owner can be assigned to multiple stores', function () {
  Sanctum::actingAs($this->superAdminUser);
  $otherStore = Store::factory()->create();
  $this->owner->stores()->attach($otherStore->id);

  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)->assertOk();

  $this->assertDatabaseHas('store_users', $this->ownerData);
  $this->assertDatabaseHas('store_users', ['store_id' => $otherStore->id, 'user_id' => $this->owner->id]);
  $this->assertDatabaseCount('store_users', 2);
});

test('owner assignment requires an existing store and user', function (array $changes, array $fields) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/stores/store-owner', [...$this->ownerData, ...$changes])
    ->assertUnprocessable()->assertJsonValidationErrors($fields);

  $this->assertDatabaseCount('store_users', 0);
})->with([
  'missing values' => [['store_id' => null, 'user_id' => null], ['store_id', 'user_id']],
  'nonexistent store' => [['store_id' => 0], ['store_id']],
  'nonexistent user' => [['user_id' => 0], ['user_id']],
]);

test('a user without the Owner role cannot be assigned as owner', function (UserRole $role) {
  Sanctum::actingAs($this->superAdminUser);
  $userRole = $role === UserRole::SuperAdmin
    ? $this->superAdminUser->role
    : Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  $user = User::factory()->for($userRole)->create();

  $this->postJson('/api/v1/staff/stores/store-owner', [...$this->ownerData, 'user_id' => $user->id])
    ->assertUnprocessable()
    ->assertJsonPath('success', false)
    ->assertJsonPath('message', 'User bukan owner.');

  $this->assertDatabaseCount('store_users', 0);
})->with([UserRole::SuperAdmin, UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);

test('a store that already has an Owner rejects another Owner', function () {
  Sanctum::actingAs($this->superAdminUser);
  $existingOwner = User::factory()->for($this->ownerRole)->create();
  $this->store->users()->attach($existingOwner->id);

  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)
    ->assertUnprocessable()->assertJsonPath('message', 'Store sudah memiliki owner.');

  $this->assertDatabaseHas('store_users', ['store_id' => $this->store->id, 'user_id' => $existingOwner->id]);
  $this->assertDatabaseMissing('store_users', $this->ownerData);
  $this->assertDatabaseCount('store_users', 1);
});

test('assigning the same Owner twice does not duplicate the relation', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)->assertOk();
  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)
    ->assertUnprocessable()->assertJsonPath('message', 'Store sudah memiliki owner.');

  $this->assertDatabaseHas('store_users', $this->ownerData);
  $this->assertDatabaseCount('store_users', 1);
});

test('available users only include Owners including those already linked to stores', function () {
  Sanctum::actingAs($this->superAdminUser);
  $this->owner->stores()->attach($this->store->id);
  $otherOwner = User::factory()->for($this->ownerRole)->create();
  $customerRole = Role::factory()->create(['slug' => UserRole::Customer->value, 'name' => 'Customer']);
  User::factory()->for($customerRole)->create();

  $response = $this->getJson('/api/v1/staff/stores-owners/available-users')
    ->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'data');

  expect(collect($response->json('data'))->pluck('id')->all())
    ->toEqualCanonicalizing([$this->owner->id, $otherOwner->id]);
});

test('available stores exclude owned stores and are sorted by name', function () {
  Sanctum::actingAs($this->superAdminUser);
  $this->owner->stores()->attach($this->store->id);
  $lastStore = Store::factory()->create(['name' => 'Zebra Resto']);
  $firstStore = Store::factory()->create(['name' => 'Anggrek Resto']);

  // Relasi pengguna non-owner tidak membuat toko dianggap sudah memiliki owner.
  $firstStore->users()->attach($this->superAdminUser->id);

  $this->getJson('/api/v1/staff/stores-owners/available-stores')
    ->assertOk()->assertJsonCount(2, 'data')
    ->assertJsonPath('data.0.id', $firstStore->id)
    ->assertJsonPath('data.1.id', $lastStore->id);
});

test('available stores are empty when every store has an Owner', function () {
  Sanctum::actingAs($this->superAdminUser);
  $this->owner->stores()->attach($this->store->id);

  $this->getJson('/api/v1/staff/stores-owners/available-stores')
    ->assertOk()->assertJsonCount(0, 'data');
});

test('Owner can access ownership selection lists', function () {
  Sanctum::actingAs($this->owner);

  $this->getJson('/api/v1/staff/stores-owners/available-users')
    ->assertOk()->assertJsonPath('data.0.id', $this->owner->id);
  $this->getJson('/api/v1/staff/stores-owners/available-stores')
    ->assertOk()->assertJsonPath('data.0.id', $this->store->id);
});

test('guest cannot access store ownership endpoints', function (string $method, string $path) {
  $this->json($method, '/api/v1/staff/' . $path, $this->ownerData)->assertUnauthorized();
})->with([
  'assign' => ['POST', 'stores/store-owner'],
  'users' => ['GET', 'stores-owners/available-users'],
  'stores' => ['GET', 'stores-owners/available-stores'],
]);

test('other roles cannot access store ownership endpoints', function (UserRole $role) {
  $userRole = Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());

  $this->postJson('/api/v1/staff/stores/store-owner', $this->ownerData)->assertForbidden();
  $this->getJson('/api/v1/staff/stores-owners/available-users')->assertForbidden();
  $this->getJson('/api/v1/staff/stores-owners/available-stores')->assertForbidden();

  $this->assertDatabaseCount('store_users', 0);
})->with([UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);
