<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $this->superAdminRole = Role::factory()->create([
    'slug' => UserRole::SuperAdmin->value,
    'name' => 'Super Admin',
  ]);
  $this->superAdminUser = User::factory()->for($this->superAdminRole)->create();
});

test('authenticated user can get roles', function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  Sanctum::actingAs($this->superAdminUser);

  $this->getJson('/api/v1/staff/roles')
    ->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonCount(2, 'data')
    ->assertJsonFragment(['id' => $role->id, 'slug' => 'owner', 'name' => 'Owner']);
});

test('role list is available to every authenticated role', function (UserRole $role) {
  $userRole = Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());

  $this->getJson('/api/v1/staff/roles')->assertOk()->assertJsonCount(2, 'data');
})->with([UserRole::Owner, UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);

test('Super admin can create a role with a valid enum slug', function () {
  Sanctum::actingAs($this->superAdminUser);

  // Regresi: controller saat ini hanya menyimpan name, padahal slug wajib di database.
  $response = $this->postJson('/api/v1/staff/roles', ['name' => 'Owner', 'slug' => 'owner']);
  $response->assertCreated()
    ->assertJsonPath('success', true)
    ->assertJsonPath('data.slug', 'owner')
    ->assertJsonPath('data.name', 'Owner');

  $this->assertDatabaseHas('roles', [
    'id' => $response->json('data.id'),
    'name' => 'Owner',
    'slug' => 'owner',
  ]);
});

test('role creation rejects invalid names', function (array $payload) {
  Sanctum::actingAs($this->superAdminUser);

  $this->postJson('/api/v1/staff/roles', $payload)
    ->assertUnprocessable()->assertJsonValidationErrors(['name']);

  $this->assertDatabaseCount('roles', 1);
})->with([
  'missing name' => [[]],
  'empty name' => [['name' => '']],
  'non string name' => [['name' => 123]],
  'name too long' => [['name' => str_repeat('a', 226)]],
]);

test('guest cannot access role endpoints', function (string $method, string $suffix) {
  $this->json($method, '/api/v1/staff/roles' . $suffix, ['name' => 'Owner', 'slug' => 'owner'])
    ->assertUnauthorized();
})->with([
  'list' => ['GET', ''],
  'create' => ['POST', ''],
  'detail' => ['GET', '/1'],
  'update' => ['PATCH', '/1'],
  'delete' => ['DELETE', '/1'],
]);

// Method controller masih kosong; kontrak endpoint perlu diimplementasikan dahulu.
test('Super admin can view role details')->todo();
test('Super admin can update a role')->todo();
test('Super admin can delete an unused role')->todo();
