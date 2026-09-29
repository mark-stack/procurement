<?php

use App\Models\Business;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

/*
 * The domain is the tenancy key, so who is let through it decides who can read whose work.
 */
test('colleagues on one domain join the one business', function () {
    $this->post('/register', [
        'name' => 'First Estimator',
        'email' => 'sam@acmesteel.com.au',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->post('/logout');

    $this->post('/register', [
        'name' => 'Second Estimator',
        'email' => 'jo@acmesteel.com.au',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Business::where('domain', 'acmesteel.com.au')->count())->toBe(1);
    expect(User::where('email', 'sam@acmesteel.com.au')->value('business_id'))
        ->toBe(User::where('email', 'jo@acmesteel.com.au')->value('business_id'));
});

test('two companies get two businesses', function () {
    $this->post('/register', [
        'name' => 'Acme',
        'email' => 'sam@acmesteel.com.au',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->post('/logout');

    $this->post('/register', [
        'name' => 'Bolt Co',
        'email' => 'sam@boltco.com.au',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::where('email', 'sam@acmesteel.com.au')->value('business_id'))
        ->not->toBe(User::where('email', 'sam@boltco.com.au')->value('business_id'));
});

/*
 * Two strangers who both use Gmail are not colleagues. Letting them register would put them in
 * one "gmail.com" business, reading each other's cutting lists and sharing one free trial.
 */
test('a personal email address cannot register', function (string $email) {
    $response = $this->post('/register', [
        'name' => 'Sole Trader',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(User::where('email', $email)->exists())->toBeFalse();
    expect(Business::count())->toBe(0);
})->with([
    'consumer webmail' => 'sam@gmail.com',
    'webmail, other spelling' => 'sam@googlemail.com',
    'microsoft' => 'sam@outlook.com',
    'apple' => 'sam@icloud.com',
    'an australian isp' => 'sam@bigpond.com.au',
    'another australian isp' => 'sam@optusnet.com.au',
    'throwaway' => 'sam@mailinator.com',
]);

test('the refusal names the provider it refused', function () {
    $response = $this->post('/register', [
        'name' => 'Sole Trader',
        'email' => 'sam@gmail.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'Please use your work email address. gmail.com is a personal email provider, and an account here is shared by everyone at your company.',
    ]);
});

/*
 * A company domain that happens to contain a blocked one as a substring is still a company.
 */
test('a company domain that merely resembles a blocked one is allowed', function (string $email) {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
})->with([
    'gmail in the name' => 'sam@gmailers.com.au',
    'a real company on a long tld' => 'sam@steelworks.international',
    'a subdomain of its own' => 'sam@mail.acmesteel.com.au',
]);

/*
 * The domain used to come from an unanchored regex with the TLD capped at six letters, so
 * shop.international matched only as far as "shop.intern" and the business was named after a
 * domain nobody owns - which a genuine shop.intern would then have been merged into.
 */
test('a long top-level domain is not truncated', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'sam@steelworks.international',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Business::where('domain', 'steelworks.international')->exists())->toBeTrue();
    expect(Business::where('domain', 'steelworks.intern')->exists())->toBeFalse();
});

test('the domain is read from the last at sign, and lower-cased', function (?string $email, ?string $expected) {
    expect(User::domainFromEmail($email))->toBe($expected);
})->with([
    ['sam@acmesteel.com.au', 'acmesteel.com.au'],
    ['SAM@AcmeSteel.com.au', 'acmesteel.com.au'],
    ['sam@steelworks.international', 'steelworks.international'],
    ['"odd@local"@acmesteel.com.au', 'acmesteel.com.au'],
    ['sam+quotes@acmesteel.com.au', 'acmesteel.com.au'],
    ['nodomain', null],
    ['sam@localhost', null],
    ['sam@', null],
    ['', null],
    [null, null],
]);
