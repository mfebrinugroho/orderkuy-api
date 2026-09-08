<?php

use App\Enums\DayOfWeek;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreOperatingHour;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
  $role = Role::factory()->create(['slug' => UserRole::SuperAdmin->value, 'name' => 'Super Admin']);
  $this->superAdminUser = User::factory()->for($role)->create();
  $this->store = Store::factory()->create();
  $this->hours = [
    'day_of_week' => DayOfWeek::Monday->value,
    'is_open' => true,
    'open_time' => '08:00:00',
    'close_time' => '21:00:00',
  ];
  $this->endpoint = "/api/v1/staff/stores/{$this->store->id}/operating-hours";
});

test('operating hours list includes all seven days even without saved hours', function () {
  Sanctum::actingAs($this->superAdminUser);

  $response = $this->getJson($this->endpoint);
  $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(7, 'data');

  foreach (DayOfWeek::cases() as $day) {
    $response->assertJsonFragment([
      'id' => null,
      'store_id' => null,
      'day_of_week' => $day->value,
      'day_name' => $day->label(),
      'open_time' => null,
      'close_time' => null,
      'is_open' => false,
    ]);
  }
});

test('operating hours list returns saved hours for the requested store', function () {
  Sanctum::actingAs($this->superAdminUser);
  $hours = StoreOperatingHour::create(['store_id' => $this->store->id, ...$this->hours]);
  StoreOperatingHour::create([
    ...$this->hours, 'store_id' => Store::factory()->create()->id, 'open_time' => '10:00:00',
  ]);

  $response = $this->getJson($this->endpoint)->assertOk()->assertJsonCount(7, 'data');
  $monday = collect($response->json('data'))->firstWhere('day_of_week', DayOfWeek::Monday->value);

  expect($monday['id'])->toBe($hours->id);
  expect($monday['store_id'])->toBe($this->store->id);
  expect($monday['open_time'])->toBe('08:00:00');
  expect($monday['close_time'])->toBe('21:00:00');
});

test('Super admin can save operating hours for multiple days', function () {
  Sanctum::actingAs($this->superAdminUser);
  $tuesday = [...$this->hours, 'day_of_week' => DayOfWeek::Tuesday->value, 'open_time' => '09:00:00'];

  $this->putJson($this->endpoint, ['operating_hours' => [$this->hours, $tuesday]])
    ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.id', $this->store->id);

  $this->assertDatabaseCount('store_operating_hours', 2);
  foreach ([$this->hours, $tuesday] as $hours) {
    $this->assertDatabaseHas('store_operating_hours', ['store_id' => $this->store->id, ...$hours]);
  }
});

test('saving existing operating hours updates the row without duplicating it', function () {
  Sanctum::actingAs($this->superAdminUser);
  $hours = StoreOperatingHour::create(['store_id' => $this->store->id, ...$this->hours]);
  $updated = [...$this->hours, 'open_time' => '10:00:00'];

  $this->putJson($this->endpoint, ['operating_hours' => [$updated]])->assertOk();
  $this->putJson($this->endpoint, ['operating_hours' => [$updated]])->assertOk();

  $this->assertDatabaseCount('store_operating_hours', 1);
  $this->assertDatabaseHas('store_operating_hours', [
    'id' => $hours->id, 'store_id' => $this->store->id, ...$updated,
  ]);
});

test('closing a day clears its opening and closing times', function () {
  Sanctum::actingAs($this->superAdminUser);
  $hours = StoreOperatingHour::create(['store_id' => $this->store->id, ...$this->hours]);

  $this->putJson($this->endpoint, ['operating_hours' => [[...$this->hours, 'is_open' => false]]])
    ->assertOk();

  $this->assertDatabaseHas('store_operating_hours', [
    'id' => $hours->id, 'is_open' => false, 'open_time' => null, 'close_time' => null,
  ]);
});

test('updating hours preserves omitted days and other stores hours', function () {
  Sanctum::actingAs($this->superAdminUser);
  $tuesday = StoreOperatingHour::create([
    ...$this->hours, 'store_id' => $this->store->id, 'day_of_week' => DayOfWeek::Tuesday->value,
  ]);
  $otherHours = StoreOperatingHour::create([
    ...$this->hours, 'store_id' => Store::factory()->create()->id,
  ]);
  $originalTuesday = $tuesday->fresh()->getAttributes();
  $originalOther = $otherHours->fresh()->getAttributes();

  $this->putJson($this->endpoint, ['operating_hours' => [$this->hours]])->assertOk();

  expect($tuesday->fresh()->getAttributes())->toBe($originalTuesday);
  expect($otherHours->fresh()->getAttributes())->toBe($originalOther);
  $this->assertDatabaseCount('store_operating_hours', 3);
});

test('operating hours require a nonempty array', function (array $payload) {
  Sanctum::actingAs($this->superAdminUser);

  $this->putJson($this->endpoint, $payload)
    ->assertUnprocessable()->assertJsonValidationErrors(['operating_hours']);

  $this->assertDatabaseCount('store_operating_hours', 0);
})->with([
  'missing' => [[]], 'empty' => [['operating_hours' => []]],
  'not an array' => [['operating_hours' => 'invalid']],
]);

test('invalid operating hours are rejected before any rows are saved', function (array $changes, string $field) {
  Sanctum::actingAs($this->superAdminUser);
  $invalid = [...$this->hours, 'day_of_week' => DayOfWeek::Tuesday->value, ...$changes];

  $this->putJson($this->endpoint, ['operating_hours' => [$this->hours, $invalid]])
    ->assertUnprocessable()->assertJsonValidationErrors(["operating_hours.1.{$field}"]);

  $this->assertDatabaseCount('store_operating_hours', 0);
})->with([
  'missing day' => [['day_of_week' => null], 'day_of_week'],
  'negative day' => [['day_of_week' => -1], 'day_of_week'],
  'day beyond Saturday' => [['day_of_week' => 7], 'day_of_week'],
  'fractional day' => [['day_of_week' => 1.5], 'day_of_week'],
  'missing status' => [['is_open' => null], 'is_open'],
  'invalid status' => [['is_open' => 'invalid'], 'is_open'],
  'invalid opening time' => [['open_time' => '25:00:00'], 'open_time'],
  'invalid closing format' => [['close_time' => '21:00'], 'close_time'],
]);

test('Owner can read and save operating hours of a linked store', function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $owner = User::factory()->for($role)->create();
  $owner->stores()->attach($this->store->id);
  Sanctum::actingAs($owner);

  $this->getJson($this->endpoint)->assertOk()->assertJsonCount(7, 'data');
  $this->putJson($this->endpoint, ['operating_hours' => [$this->hours]])->assertOk();

  $this->assertDatabaseHas('store_operating_hours', ['store_id' => $this->store->id, ...$this->hours]);
});

test('guest cannot access operating hours', function (string $method) {
  $this->json($method, $this->endpoint, ['operating_hours' => [$this->hours]])->assertUnauthorized();
})->with(['GET', 'PUT']);

test('operating hours return 404 for a missing store', function (string $method) {
  Sanctum::actingAs($this->superAdminUser);
  $this->json($method, '/api/v1/staff/stores/0/operating-hours', ['operating_hours' => [$this->hours]])
    ->assertNotFound();
})->with(['GET', 'PUT']);

test('other roles cannot read or update operating hours', function (UserRole $role) {
  $userRole = Role::factory()->create(['slug' => $role->value, 'name' => $role->label()]);
  Sanctum::actingAs(User::factory()->for($userRole)->create());

  $this->getJson($this->endpoint)->assertForbidden();
  $this->putJson($this->endpoint, ['operating_hours' => [$this->hours]])->assertForbidden();

  $this->assertDatabaseCount('store_operating_hours', 0);
})->with([UserRole::Admin, UserRole::Customer, UserRole::Cashier, UserRole::Kitchen]);
