<?php

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone_number' => '081234567890',
        'jabatan' => 'Dewan',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('personal.index', absolute: false));

    $user = \App\Models\User::where('email', 'test@example.com')->first();
    expect($user->phone_number)->toBe('081234567890');
    expect($user->jabatan)->toBe('Dewan');
});

test('registration requires phone number', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'jabatan' => 'Dewan',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('phone_number');
    $this->assertGuest();
});

test('registration requires jabatan', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone_number' => '081234567890',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('jabatan');
    $this->assertGuest();
});

test('registration validates phone number format', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone_number' => 'bukan-nomor',
        'jabatan' => 'Dewan',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('phone_number');
    $this->assertGuest();
});

test('registration accepts valid phone number formats', function (string $phone) {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => fake()->unique()->safeEmail(),
        'phone_number' => $phone,
        'jabatan' => 'Pengurus Divisi',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    auth()->logout();
})->with([
    '08123456789',
    '081234567890',
    '6281234567890',
    '+6281234567890',
]);
