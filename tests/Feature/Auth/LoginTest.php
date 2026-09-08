<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
  $role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $this->user = User::factory()->for($role)->create([
    'email' => 'owner@example.com',
    'password' => 'password123',
  ]);
  $this->credentials = ['email' => $this->user->email, 'password' => 'password123'];
});

test('login returns user data and a valid token without exposing credentials', function () {
  $response = $this->postJson('/api/v1/staff/login', $this->credentials);

  $response->assertOk()
    ->assertJsonPath('success', true)
    ->assertJsonPath('message', 'Login berhasil')
    ->assertJsonPath('data.user.id', $this->user->id)
    ->assertJsonPath('data.user.email', $this->user->email);

  expect($response->json('data.user'))->not->toHaveKeys(['password', 'remember_token']);
  $plainTextToken = $response->json('token');
  expect($plainTextToken)->toBeString()->not->toBeEmpty();

  $token = PersonalAccessToken::findToken($plainTextToken);
  expect($token)->not->toBeNull();
  expect($token->tokenable->is($this->user))->toBeTrue();
  expect($token->name)->toBe('api-token');
  // Sanctum menyimpan hash bagian rahasia token, bukan bearer token asli.
  expect($token->token)->toBe(hash('sha256', explode('|', $plainTextToken, 2)[1]));
  $this->assertDatabaseCount('personal_access_tokens', 1);

  // Bersihkan cache guard agar request berikut memvalidasi bearer token sendiri.
  Auth::forgetGuards();
  $this->getJson('/api/v1/staff/user', ['Authorization' => 'Bearer ' . $plainTextToken])
    ->assertOk()->assertJsonPath('data.id', $this->user->id);
});

test('login rejects incorrect credentials without issuing a token', function (array $changes) {
  $response = $this->postJson('/api/v1/staff/login', [...$this->credentials, ...$changes]);

  $response->assertUnauthorized()
    ->assertJsonPath('success', false)
    ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

  expect($response->json())->not->toHaveKeys(['token', 'data']);
  $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
  'wrong password' => [['password' => 'wrong-password']],
  'unknown email' => [['email' => 'unknown@example.com']],
]);

test('login validates required credentials and email format', function (array $payload, array $fields) {
  $this->postJson('/api/v1/staff/login', $payload)
    ->assertUnprocessable()->assertJsonValidationErrors($fields);

  $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
  'missing credentials' => [[], ['email', 'password']],
  'missing email' => [['password' => 'password123'], ['email']],
  'missing password' => [['email' => 'owner@example.com'], ['password']],
  'empty email' => [['email' => '', 'password' => 'password123'], ['email']],
  'empty password' => [['email' => 'owner@example.com', 'password' => ''], ['password']],
  'invalid email' => [['email' => 'invalid-email', 'password' => 'password123'], ['email']],
]);

test('repeated login issues different tokens and preserves existing tokens', function () {
  $firstResponse = $this->postJson('/api/v1/staff/login', $this->credentials)->assertOk();
  Auth::forgetGuards();
  $secondResponse = $this->postJson('/api/v1/staff/login', $this->credentials)->assertOk();

  $firstToken = $firstResponse->json('token');
  $secondToken = $secondResponse->json('token');
  expect($secondToken)->not->toBe($firstToken);
  expect(PersonalAccessToken::findToken($firstToken))->not->toBeNull();
  expect(PersonalAccessToken::findToken($secondToken))->not->toBeNull();
  expect($this->user->tokens()->count())->toBe(2);
});

test('failed login preserves previously issued tokens', function () {
  $token = $this->user->createToken('existing-session');

  $this->postJson('/api/v1/staff/login', [...$this->credentials, 'password' => 'wrong-password'])
    ->assertUnauthorized();

  expect(PersonalAccessToken::findToken($token->plainTextToken))->not->toBeNull();
  $this->assertDatabaseCount('personal_access_tokens', 1);
});
