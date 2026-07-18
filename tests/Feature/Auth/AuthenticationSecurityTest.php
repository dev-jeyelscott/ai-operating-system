<?php

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

test('successful password login regenerates the session identifier', function () {
    $user = User::factory()->create();

    $this->withSession([
        'pre_authentication_marker' => 'preserved',
    ]);

    $sessionIdBeforeLogin = session()->getId();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    expect(session()->getId())
        ->not->toBe($sessionIdBeforeLogin)
        ->and(session('pre_authentication_marker'))
        ->toBe('preserved');
});

test('logout invalidates the session and regenerates the csrf token', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->withSession([
            'sensitive_marker' => 'must-be-removed',
        ]);

    $sessionIdBeforeLogout = session()->getId();
    $csrfTokenBeforeLogout = session()->token();

    $response = $this->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();

    expect(session()->getId())
        ->not->toBe($sessionIdBeforeLogout)
        ->and(session()->has('sensitive_marker'))
        ->toBeFalse()
        ->and(session()->token())
        ->not->toBe($csrfTokenBeforeLogout);
});

test('unverified users cannot access verified application routes', function () {
    $user = User::factory()->unverified()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));
});

test('verified users can access verified application routes', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('login errors do not disclose whether an account exists', function () {
    $existingUser = User::factory()->create();

    $existingAccountResponse = $this->post(route('login.store'), [
        'email' => $existingUser->email,
        'password' => 'incorrect-password',
    ]);

    $missingAccountResponse = $this->post(route('login.store'), [
        'email' => 'missing-account@example.com',
        'password' => 'incorrect-password',
    ]);

    $existingAccountMessage = $existingAccountResponse
        ->getSession()
        ->get('errors')
        ->first('email');

    $missingAccountMessage = $missingAccountResponse
        ->getSession()
        ->get('errors')
        ->first('email');

    expect($existingAccountMessage)
        ->toBeString()
        ->toBe($missingAccountMessage);
});

test('login throttling normalizes email case and includes the client address', function () {
    $user = User::factory()->create([
        'email' => 'rate-limit@example.com',
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post(route('login.store'), [
            'email' => 'RATE-LIMIT@EXAMPLE.COM',
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ])->assertTooManyRequests();
});

test('changing a password invalidates other authenticated sessions', function () {
    Event::fake([
        OtherDeviceLogout::class,
    ]);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a memorable passphrase',
            'password_confirmation' => 'a memorable passphrase',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);

    Event::assertDispatched(OtherDeviceLogout::class);

    expect(Hash::check(
        'a memorable passphrase',
        $user->refresh()->password,
    ))->toBeTrue();
});

test('session configuration uses the required security defaults', function () {
    expect(config('session.encrypt'))
        ->toBeTrue()
        ->and(config('session.http_only'))
        ->toBeTrue()
        ->and(config('session.same_site'))
        ->toBe('lax')
        ->and(config('session.serialization'))
        ->toBe('json');
});
