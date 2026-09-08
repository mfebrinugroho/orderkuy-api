<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
  $this->role = Role::factory()->create(['slug' => UserRole::Owner->value, 'name' => 'Owner']);
  $this->user = User::factory()->for($this->role)->create([
    'email' => 'owner@example.com',
    'password' => 'password123',
  ]);
});

test('user can logout using a token issued by login', function () {
  $login = $this->postJson('/api/v1/staff/login', [
    'email' => $this->user->email,
    'password' => 'password123',
  ])->assertOk();
  $plainTextToken = $login->json('token');
  $token = PersonalAccessToken::findToken($plainTextToken);
  expect($token)->not->toBeNull();

  // Token asli diperlukan untuk membuktikan pencabutan token di database.
  Auth::forgetGuards();
  $this->postJson('/api/v1/staff/logout', [], ['Authorization' => 'Bearer ' . $plainTextToken])
    ->assertOk()->assertJsonPath('message', 'Logout berhasil');

  $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
  $this->assertDatabaseHas('users', ['id' => $this->user->id]);

  // Guard tidak boleh memakai user yang tersimpan dari request logout sebelumnya.
  Auth::forgetGuards();
  $this->getJson('/api/v1/staff/user', ['Authorization' => 'Bearer ' . $plainTextToken])
    ->assertUnauthorized();
});

test('logout only revokes the current token and preserves other sessions', function () {
  $currentToken = $this->user->createToken('current-session');
  $otherToken = $this->user->createToken('other-session');
  $otherUser = User::factory()->for($this->role)->create();
  $otherUserToken = $otherUser->createToken('other-user-session');

  $this->postJson('/api/v1/staff/logout', [], [
    'Authorization' => 'Bearer ' . $currentToken->plainTextToken,
  ])->assertOk();

  $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
  $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
  $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherUserToken->accessToken->id]);
  $this->assertDatabaseCount('personal_access_tokens', 2);

  Auth::forgetGuards();
  $this->getJson('/api/v1/staff/user', ['Authorization' => 'Bearer ' . $otherToken->plainTextToken])
    ->assertOk()->assertJsonPath('data.id', $this->user->id);
});

test('guest cannot logout or revoke existing tokens', function () {
  $token = $this->user->createToken('existing-session');

  $this->postJson('/api/v1/staff/logout')->assertUnauthorized();

  $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
});

test('logout rejects a tampered bearer token without deleting the real token', function () {
  $token = $this->user->createToken('existing-session');

  $this->postJson('/api/v1/staff/logout', [], [
    'Authorization' => 'Bearer ' . $token->plainTextToken . '-tampered',
  ])->assertUnauthorized();

  $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
  expect(PersonalAccessToken::findToken($token->plainTextToken))->not->toBeNull();
});

test('logout rejects an expired token', function () {
  $token = $this->user->createToken('expired-session', ['*'], now()->subMinute());

  $this->postJson('/api/v1/staff/logout', [], [
    'Authorization' => 'Bearer ' . $token->plainTextToken,
  ])->assertUnauthorized();

  $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
});

test('a revoked token cannot be used to logout again', function () {
  $token = $this->user->createToken('current-session');
  $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken];

  $this->postJson('/api/v1/staff/logout', [], $headers)->assertOk();
  Auth::forgetGuards();
  $this->postJson('/api/v1/staff/logout', [], $headers)->assertUnauthorized();

  $this->assertDatabaseCount('personal_access_tokens', 0);
});
