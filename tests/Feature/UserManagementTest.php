<?php

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

pest()->group('ui', 'tenancy');

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
});

it('lists users with their roles', function () {
    $other = User::factory()->create(['name' => 'Ana Silva']);
    $other->assignRole($this->editor);

    Livewire::test(ManageUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $other])
        ->assertSee('Ana Silva')
        ->assertSee('editor');
});

it('creates a user with a hashed password and roles', function () {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Rui Costa',
            'email' => 'rui@example.test',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
            'roles' => [$this->editor->id],
        ])
        ->assertHasNoActionErrors();

    $user = User::firstWhere('email', 'rui@example.test');

    expect($user)->not->toBeNull()
        ->and(Hash::check('segredo-forte-123', $user->password))->toBeTrue()
        ->and($user->hasRole('editor'))->toBeTrue();
});

it('sends a new user the email confirmation link', function () {
    Notification::fake();

    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Rui Costa',
            'email' => 'rui@example.test',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
        ])
        ->assertHasNoActionErrors();

    $user = User::firstWhere('email', 'rui@example.test');

    // The panel stays shut until the address is confirmed: they need the link to get in.
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class, fn (VerifyEmail $notification) => str_contains($notification->url, '/email-verification/verify/'.$user->id.'/'));
});

it('requires the password to be confirmed on create', function () {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Rui Costa',
            'email' => 'rui@example.test',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'outra-coisa',
        ])
        ->assertHasActionErrors(['password']);
});

it('keeps the current password when editing without a new one', function () {
    $user = User::factory()->create(['password' => 'antiga-123456']);

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $user, data: ['name' => 'Novo Nome', 'password' => null, 'password_confirmation' => null])
        ->assertHasNoTableActionErrors();

    expect($user->fresh()->name)->toBe('Novo Nome')
        ->and(Hash::check('antiga-123456', $user->fresh()->password))->toBeTrue();
});

it('does not let you delete yourself', function () {
    Livewire::test(ManageUsers::class)
        ->assertTableActionHidden('delete', $this->admin);
});

it('leaves super_admin alone when a super admin\'s roles are edited (it is only managed by command)', function () {
    $other = User::factory()->create();
    $other->assignRole('super_admin');

    foreach ([$this->admin, $other] as $user) {
        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $user, data: ['roles' => [$this->editor->id]])
            ->assertHasNoTableActionErrors();

        expect($user->fresh()->hasRole('super_admin'))->toBeTrue()
            ->and($user->fresh()->hasRole('editor'))->toBeTrue();
    }
});

it('applies the central password policy when an administrator creates a user', function () {
    $previous = Password::$defaultCallback;
    Password::defaults(fn (): Password => Password::min(12));

    try {
        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Rui Costa',
                'email' => 'rui@example.test',
                'password' => 'Short1234!',
                'password_confirmation' => 'Short1234!',
            ])
            ->assertHasActionErrors(['password']);

        expect(User::query()->where('email', 'rui@example.test')->exists())->toBeFalse();

        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Rui Costa',
                'email' => 'rui@example.test',
                'password' => 'Longer123456!',
                'password_confirmation' => 'Longer123456!',
            ])
            ->assertHasNoActionErrors();

        expect(User::query()->where('email', 'rui@example.test')->exists())->toBeTrue();
    } finally {
        Password::$defaultCallback = $previous;
    }
});
