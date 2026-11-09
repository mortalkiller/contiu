<div class="contiu-user-menu-header min-w-0">
    @if (filled($accountName))
        <div class="flex items-center gap-2.5 rounded-lg bg-moss-tint p-2.5 text-moss-deep dark:bg-primary-950 dark:text-primary-100">
            <span class="grid size-9 shrink-0 place-items-center rounded-md bg-white text-xs font-semibold dark:bg-primary-900" aria-hidden="true">
                {{ \Illuminate\Support\Str::substr(\Illuminate\Support\Str::initials($accountName, capitalize: true), 0, 2) }}
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-xs">Conta ativa</p>
                <div class="flex min-w-0 items-center gap-2">
                    <p class="min-w-0 flex-1 truncate text-sm font-semibold" title="{{ $accountName }}">
                        {{ $accountName }}
                    </p>

                    @if (filled($roleLabel))
                        <span class="max-w-[55%] shrink-0 truncate rounded-full bg-white px-2 py-0.5 text-xs font-medium dark:bg-primary-900" title="{{ $roleLabel }}">
                            {{ $roleLabel }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if (filled($accountProfileUrl))
            <x-filament::dropdown.list.item
                tag="a"
                :href="$accountProfileUrl"
                :icon="\ToneGabes\Filament\Icons\Enums\Phosphor::Buildings"
                class="mt-1"
            >
                Perfil da conta
            </x-filament::dropdown.list.item>
        @endif
    @endif

    <div @class([
        'flex items-center gap-2.5 px-2 py-2.5',
        'mt-1.5 border-t border-gray-200 dark:border-white/10' => filled($accountName),
    ])>
        <x-filament-panels::avatar.user :user="$user" class="shrink-0" />

        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ filament()->getUserName($user) }}">
                {{ filament()->getUserName($user) }}
            </p>
            <p class="truncate text-xs text-gray-600 dark:text-gray-300" title="{{ $user->email }}">
                {{ $user->email }}
            </p>
        </div>
    </div>
</div>
