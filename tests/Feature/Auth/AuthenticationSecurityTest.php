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
    // Create an account that will be tested using an invalid password.
    $user = User::factory()->create([
        'email' => 'existing@example.test',
    ]);

    // Fortify must return this same generic message for every invalid login.
    $genericFailureMessage = trans('auth.failed');

    // Verify an existing account with an invalid password receives
    // only the generic authentication failure message.
    $existingAccountResponse = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ]);

    $existingAccountResponse->assertSessionHasErrors([
        'email' => $genericFailureMessage,
    ]);

    $this->assertGuest();

    // Verify a nonexistent account receives the exact same generic message,
    // preventing account enumeration through differing login responses.
    $missingAccountResponse = $this->post(route('login.store'), [
        'email' => 'missing@example.test',
        'password' => 'incorrect-password',
    ]);

    $missingAccountResponse->assertSessionHasErrors([
        'email' => $genericFailureMessage,
    ]);

    $this->assertGuest();
});

test('login throttling normalizes email case and includes the client address', function () {
    $user = User::factory()->create([
        'email' => 'rate-limit@example.com',
    ]);

    /*
     * Documentation-only addresses reserved for examples and tests.
     * Using explicit addresses also verifies that the limiter is scoped
     * by both the normalized email address and the client address.
     */
    $limitedClientAddress = '203.0.113.10';
    $otherClientAddress = '203.0.113.11';

    /*
     * Consume the five allowed attempts using an uppercase email address
     * from the client address that will eventually be rate limited.
     */
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this
            ->withServerVariables([
                'REMOTE_ADDR' => $limitedClientAddress,
            ])
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'RATE-LIMIT@EXAMPLE.COM',
                'password' => 'incorrect-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        /*
         * Remove flashed validation errors between requests so an earlier
         * authentication failure cannot create a false-positive assertion.
         *
         * This does not reset the rate limiter because limiter counters are
         * stored in the configured cache store, not in the session.
         */
        $this->flushSession();
    }

    /*
     * The same normalized email from another address must still receive
     * the normal authentication failure instead of a rate-limit response.
     */
    $this
        ->withServerVariables([
            'REMOTE_ADDR' => $otherClientAddress,
        ])
        ->from(route('login'))
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->flushSession();

    /*
     * Lowercase and uppercase versions of the email must share the same
     * limiter bucket when they originate from the same client address.
     *
     * Browser requests intentionally receive a redirect with a flashed
     * validation-style error. JSON requests are covered separately and
     * receive the platform's HTTP 429 API response.
     */
    $this
        ->withServerVariables([
            'REMOTE_ADDR' => $limitedClientAddress,
        ])
        ->from(route('login'))
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
        ->assertRedirect(route('login'))
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit');
});

test('testing isolates rate limiter counters in memory', function () {
    /*
     * Automated tests must never use the persistent Redis limiter store.
     * Persistent counters would leak across test methods and repeated runs.
     */
    expect(app()->environment('testing'))
        ->toBeTrue()
        ->and(config('cache.limiter'))
        ->toBe('array');
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

test('login throttling returns the stable retry response for json clients', function () {
    User::factory()->create([
        'email' => 'json-rate-limit@example.com',
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this
            ->withHeader('Accept', 'application/json')
            ->postJson(route('login.store'), [
                'email' => 'JSON-RATE-LIMIT@EXAMPLE.COM',
                'password' => 'incorrect-password',
            ])
            ->assertUnprocessable();
    }

    $response = $this
        ->withHeader('X-Request-ID', 'login-rate-limit-request')
        ->postJson(route('login.store'), [
            'email' => 'json-rate-limit@example.com',
            'password' => 'incorrect-password',
        ]);

    $response
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath(
            'error.code',
            'rate_limit_exceeded',
        )
        ->assertJsonPath(
            'error.retryable',
            true,
        )
        ->assertJsonPath(
            'error.request_id',
            'login-rate-limit-request',
        );

    expect(
        $response->json('error.details.retry_after_seconds'),
    )->toBeInt()->toBeGreaterThan(0);
});
