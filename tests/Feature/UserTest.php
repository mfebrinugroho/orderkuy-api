<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $superAdminRole = Role::factory()->create([
    'slug' => UserRole::SuperAdmin->value,
    'name' => 'Super Admin',
  ]);

  $this->superAdminUser = User::factory()->for($superAdminRole)->create();
  $this->customerRole = Role::factory()->create([
    'slug' => UserRole::Customer->value,
    'name' => 'Customer',
  ]);
  $this->userData = [
    'name' => 'Puput',
    'email' => 'puput@example.com',
    'password' => 'password123',
    'password_confirmation' => 'password123',
    'role_id' => $this->customerRole->id,
  ];
});

test('Super admin can get paginated users', function () {
  Sanctum::actingAs($this->superAdminUser);
  User::factory()->count(5)->for($this->customerRole)->create();

  $response = $this->getJson('/api/v1/staff/users?limit=2');

  $response->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonCount(2, 'data')
    // Akun super admin juga termasuk dalam daftar user.
    ->assertJsonPath('meta.total', 6)
    ->assertJsonPath('meta.last_page', 3)
    ->assertJsonPath('meta.current_page', 1)
    ->assertJsonPath('meta.per_page', 2)
    ->assertJsonPath('links.prev', null);

  expect($response->json('links.next'))->not->toBeNull();
  foreach ($response->json('data') as $user) {
    expect($user)->not->toHaveKey('password');
  }
});

test('Super admin can search users', function (string $search) {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create([
    'name' => 'Unique Search Person',
    'email' => 'unique.search@example.com',
  ]);

  $this->getJson('/api/v1/staff/users?' . http_build_query(['search' => $search]))
    ->assertOk()
    ->assertJsonCount(1, 'data')
    ->assertJsonPath('data.0.id', $user->id)
    ->assertJsonPath('meta.total', 1);
})->with(['name' => 'unique search', 'email' => 'unique.search@', 'role' => 'customer']);

test('Super admin can create a user with a hashed password', function () {
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->postJson('/api/v1/staff/users', $this->userData);
  $response->assertCreated()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Puput')
    ->assertJsonPath('data.email', 'puput@example.com')
    ->assertJsonPath('data.role_id', $this->customerRole->id);

  $user = User::findOrFail($response->json('data.id'));
  expect(Hash::check('password123', $user->password))->toBeTrue();
  expect($response->json('data'))->not->toHaveKey('password');
  $this->assertDatabaseHas('users', [
    'id' => $user->id,
    'name' => 'Puput',
    'email' => 'puput@example.com',
    'role_id' => $this->customerRole->id,
  ]);
});

test('user creation requires all required fields', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/users', [])
    ->assertUnprocessable()
    ->assertJsonValidationErrors(['name', 'email', 'password', 'role_id']);

  $this->assertDatabaseCount('users', 1);
});

test('user creation rejects invalid input', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/users', [...$this->userData, ...$changes])
    ->assertUnprocessable()
    ->assertJsonValidationErrors([$field]);

  $this->assertDatabaseCount('users', 1);
})->with([
  'name too long' => [['name' => str_repeat('a', 226)], 'name'],
  'invalid email' => [['email' => 'invalid-email'], 'email'],
  'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
  'password mismatch' => [['password_confirmation' => 'different'], 'password'],
  'missing role' => [['role_id' => 0], 'role_id'],
]);

test('user creation rejects an email already in use', function () {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/users', [
    ...$this->userData,
    'email' => $this->superAdminUser->email,
  ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

  $this->assertDatabaseCount('users', 1);
});

test('Super admin can see user details without exposing credentials', function () {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();

  $response = $this->getJson("/api/v1/staff/users/{$user->id}");
  $response->assertOk()
    ->assertJsonPath('data.id', $user->id)
    ->assertJsonPath('data.email', $user->email)
    ->assertJsonPath('data.role.slug', UserRole::Customer->value);

  expect($response->json('data'))->not->toHaveKeys(['password', 'remember_token']);
});

test('user can be updated without changing the password or email', function () {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();
  $oldPassword = $user->password;
  $data = ['name' => 'Puput Kurniawati', 'email' => $user->email, 'role_id' => $user->role_id];

  $this->patchJson("/api/v1/staff/users/{$user->id}", $data)
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.name', 'Puput Kurniawati');

  $this->assertDatabaseHas('users', ['id' => $user->id, ...$data]);
  expect($user->fresh()->password)->toBe($oldPassword);
});

test('Super admin can change a user password and role', function () {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();
  $ownerRole = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);

  $this->patchJson("/api/v1/staff/users/{$user->id}", [
    ...$this->userData,
    'role_id' => $ownerRole->id,
  ])->assertOk()->assertJsonPath('data.role_id', $ownerRole->id);

  expect(Hash::check('password123', $user->fresh()->password))->toBeTrue();
  $this->assertDatabaseHas('users', ['id' => $user->id, 'role_id' => $ownerRole->id]);
});

test('user update rejects invalid input without changing the user', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();
  $original = $user->fresh()->getAttributes();

  $this->patchJson("/api/v1/staff/users/{$user->id}", [...$this->userData, ...$changes])
    ->assertUnprocessable()
    ->assertJsonValidationErrors([$field]);

  expect($user->fresh()->getAttributes())->toBe($original);
})->with([
  'missing name' => [['name' => null], 'name'],
  'invalid email' => [['email' => 'invalid'], 'email'],
  'missing role' => [['role_id' => 0], 'role_id'],
  'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
  'password mismatch' => [['password_confirmation' => 'different'], 'password'],
]);

test('user update rejects another user email', function () {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();

  $this->patchJson("/api/v1/staff/users/{$user->id}", [
    ...$this->userData,
    'email' => $this->superAdminUser->email,
  ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

  expect($user->fresh()->email)->toBe($user->email);
});

test('Super admin can delete a user', function () {
  Sanctum::actingAs($this->superAdminUser);
  $user = User::factory()->for($this->customerRole)->create();

  $this->deleteJson("/api/v1/staff/users/{$user->id}")
    ->assertOk()->assertJsonPath('success', true);

  $this->assertDatabaseMissing('users', ['id' => $user->id]);
  $this->assertDatabaseHas('users', ['id' => $this->superAdminUser->id]);
});

test('user endpoints return 404 for a missing user', function (string $method) {
  Sanctum::actingAs($this->superAdminUser);

  $this->json($method, '/api/v1/staff/users/0', $this->userData)->assertNotFound();
})->with(['GET', 'PATCH', 'DELETE']);

test('guest cannot access user management', function (string $method, string $suffix) {
  $this->json($method, '/api/v1/staff/users' . $suffix, $this->userData)
    ->assertUnauthorized();
})->with([
  'list' => ['GET', ''],
  'create' => ['POST', ''],
  'detail' => ['GET', '/1'],
  'update' => ['PATCH', '/1'],
  'delete' => ['DELETE', '/1'],
]);

test('non super admin cannot manage users', function (UserRole $role) {
  $userRole = $role === UserRole::Customer
    ? $this->customerRole
    : Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());

  $target = $this->superAdminUser;
  $original = $target->fresh()->getAttributes();

  $this->getJson('/api/v1/staff/users')->assertForbidden();
  $this->postJson('/api/v1/staff/users', $this->userData)->assertForbidden();
  $this->getJson("/api/v1/staff/users/{$target->id}")->assertForbidden();
  $this->patchJson("/api/v1/staff/users/{$target->id}", $this->userData)->assertForbidden();
  $this->deleteJson("/api/v1/staff/users/{$target->id}")->assertForbidden();

  expect($target->fresh()->getAttributes())->toBe($original);
  $this->assertDatabaseCount('users', 2);
})->with([UserRole::Owner, UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);

test('Owner can select a linked store as the active store', function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $owner = User::factory()->for($role)->create();
  $store = Store::factory()->create();
  $owner->stores()->attach($store->id);
  Sanctum::actingAs($owner);

  $this->postJson('/api/v1/staff/user/active-store', ['store_id' => $store->id])
    ->assertOk()
    ->assertJsonPath('data.store_id', $store->id)
    ->assertJsonPath('data.stores.0.id', $store->id);

  $this->assertDatabaseHas('users', ['id' => $owner->id, 'store_id' => $store->id]);
});

test('Owner cannot select an unlinked store', function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $currentStore = Store::factory()->create();
  $owner = User::factory()->for($role)->create(['store_id' => $currentStore->id]);
  $owner->stores()->attach($currentStore->id);
  $otherStore = Store::factory()->create();
  Sanctum::actingAs($owner);

  $this->postJson('/api/v1/staff/user/active-store', ['store_id' => $otherStore->id])
    ->assertForbidden();

  expect($owner->fresh()->store_id)->toBe($currentStore->id);
});

test('active store selection requires an existing store', function (array $payload) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/user/active-store', $payload)
    ->assertUnprocessable()->assertJsonValidationErrors(['store_id']);

  expect($this->superAdminUser->fresh()->store_id)->toBeNull();
})->with(['missing store' => [[]], 'nonexistent store' => [['store_id' => 0]]]);