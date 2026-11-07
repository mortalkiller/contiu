<?php

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

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

it('keeps at least one super admin', function () {
    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $this->admin, data: ['roles' => [$this->editor->id]])
        ->assertHasTableActionErrors(['roles']);

    expect($this->admin->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('lets you remove super admin from someone when another super admin remains', function () {
    $other = User::factory()->create();
    $other->assignRole('super_admin');

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $other, data: ['roles' => [$this->editor->id]])
        ->assertHasNoTableActionErrors();

    expect($other->fresh()->hasRole('super_admin'))->toBeFalse();
});
