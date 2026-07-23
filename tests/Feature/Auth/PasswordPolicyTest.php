<?php

use Illuminate\Support\Str;
use Laravel\Fortify\Features;

test('registration accepts a long passphrase without composition requirements', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Passphrase User',
        'email' => 'passphrase@example.com',
        'password' => 'a memorable passphrase',
        'password_confirmation' => 'a memorable passphrase',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('registration rejects passwords shorter than fifteen characters', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Weak Password User',
        'email' => 'weak-password@example.com',
        'password' => 'too short',
        'password_confirmation' => 'too short',
    ]);

    $response->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('registration rejects passwords longer than one hundred twenty eight characters', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $password = Str::repeat('a', 129);

    $response = $this->post(route('register.store'), [
        'name' => 'Oversized Password User',
        'email' => 'oversized-password@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $response->assertSessionHasErrors('password');

    $this->assertGuest();
});
