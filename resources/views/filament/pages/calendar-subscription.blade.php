<x-filament-panels::page>
    <x-filament::section>
        @if ($subscription === null)
            @if ($canWrite)
                <x-filament::button wire:click="createSubscription">
                    {{ __('Create subscription link') }}
                </x-filament::button>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No active calendar subscription') }}</p>
            @endif
        @else
            <div class="space-y-4" x-data="{ copied: false }">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Add this link in Google Calendar or Apple Calendar. Anyone with the link can view the shared dates.') }}
                </p>

                <div class="flex flex-wrap items-end gap-3">
                    <label class="min-w-0 grow">
                        <span class="mb-1 block text-sm font-medium">{{ __('Subscription link') }}</span>

                        <x-filament::input.wrapper>
                            <x-filament::input type="url" readonly :value="$subscription['url']" x-ref="url" />
                        </x-filament::input.wrapper>
                    </label>

                    <x-filament::button type="button" color="gray"
                        x-on:click="
                            copied = false;
                            const selectLink = () => {
                                $refs.url.focus();
                                $refs.url.select();
                            };
                            if (!navigator.clipboard) {
                                selectLink();
                                return;
                            }
                            navigator.clipboard.writeText($refs.url.value)
                                .then(() => copied = true)
                                .catch(selectLink);
                        ">
                        {{ __('Copy link') }}
                    </x-filament::button>
                    <span x-cloak x-show="copied" role="status" class="text-sm">{{ __('Link copied') }}</span>
                </div>

                <label class="flex items-center gap-3 text-sm">
                    <x-filament::input.checkbox
                        :checked="$subscription['includeReceivables']"
                        :disabled="! $canWrite"
                        wire:change="setIncludeReceivables($event.target.checked)"
                        wire:loading.attr="disabled"
                    />
                    <span>{{ __('Include open receivables') }}</span>
                </label>

                <x-filament::button color="danger" outlined wire:click="revokeSubscription"
                    :wire:confirm="__('Revoke this link? Calendars using it will stop receiving updates.')">
                    {{ __('Revoke link') }}
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
