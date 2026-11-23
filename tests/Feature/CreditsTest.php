<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('gives a newly registered user a $1.00 Balance', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', 1_000_000_000));
});
