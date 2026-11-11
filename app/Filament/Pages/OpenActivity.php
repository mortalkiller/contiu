<?php

namespace App\Filament\Pages;

use App\Actions\ConfirmFiscalProfile;
use App\Filament\Support\SignupQuestions;
use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\TaxProfileDeduction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;

/**
 * "Já abri atividade": an account created while the activity was not open yet answers the fiscal
 * questions the sign-up skipped (when and how it opened, the year's situation, the work), sees
 * the deduced tax profile and saves it. From then on Contiu shows the taxes and the tax calendar.
 * With organised accounting only the VAT is asked, as in the sign-up.
 */
class OpenActivity extends Page
{
    protected static ?string $slug = 'abrir-atividade';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Only while the activity is not open, and for whoever can change the account. */
    public static function canAccess(): bool
    {
        return parent::canAccess() && ! CurrentAccount::readOnly() && ! app(FinanceSettings::class)->activityOpened();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getSubheading(): string
    {
        return __('Tell us when you opened it and answer a few questions. We work out your taxes from them.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make(__('Activity'))
                        ->schema([
                            Group::make(SignupQuestions::activityDetails(onlyIfOpened: false))
                                ->extraAttributes(['class' => 'contiu-signup-lines']),
                        ]),
                    Step::make(__('Situation'))
                        ->visible(fn (): bool => in_array('situation', $this->stepNames(), true))
                        ->schema(SignupQuestions::situation()),
                    Step::make(__('Work'))
                        ->visible(fn (): bool => in_array('work', $this->stepNames(), true))
                        ->schema(SignupQuestions::work()),
                    Step::make(__('VAT'))
                        ->visible(fn (): bool => in_array('organised', $this->stepNames(), true))
                        ->schema([
                            Group::make([SignupQuestions::organisedVat()])->extraAttributes(['class' => 'contiu-signup-lines']),
                        ]),
                    Step::make(__('Tax profile'))
                        ->visible(fn (): bool => in_array('profile', $this->stepNames(), true))
                        ->schema([
                            View::make('filament.pages.auth.partials.signup-profile')
                                ->viewData(fn (): array => SignupQuestions::profileViewData(
                                    $this->deduction(),
                                    [...$this->data ?? [], 'name' => $this->user()->name, 'nif' => $this->account()->nif],
                                    eyebrow: __('Your tax profile'),
                                    stepNames: $this->stepNames(),
                                )),
                            Hidden::make('exemption_confirmed')->default(false),
                        ]),
                ])
                    ->submitAction($this->saveAction())
                    ->alpineSubmitHandler('$wire.save()')
                    ->extraAttributes(['class' => 'contiu-open-activity']),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    /** Outside a <form> (each step has its own), so it calls save() instead of submitting. */
    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('Save my tax profile'))
            ->action('save');
    }

    /**
     * The steps the answers lead to: all the questions, or only the VAT with organised accounting.
     *
     * @return list<string>
     */
    public function stepNames(): array
    {
        return $this->deduction()->path() === 'organised'
            ? ['activity', 'organised']
            : ['activity', 'situation', 'work', 'profile'];
    }

    public function deduction(): TaxProfileDeduction
    {
        return TaxProfileDeduction::from(SignupQuestions::answers([...$this->data ?? [], 'activity_opened' => 'yes'], turnoverInEuros: true));
    }

    public function save(): void
    {
        abort_if(CurrentAccount::readOnly(), 403);

        $data = $this->form->getState();
        $deduction = TaxProfileDeduction::from(SignupQuestions::answers([...$data, 'activity_opened' => 'yes'], turnoverInEuros: false));
        $settings = $deduction->settings();

        app(FinanceSettings::class)->set([
            ...$settings,
            // A VAT-exempt freelancer's payments start exempt, as at sign-up (CreateAccount).
            'payments_exempt_by_default' => $settings['vat_exempt'],
        ]);

        // "Já confirmei": the user checked the exemption in Segurança Social Direta.
        if (($data['exemption_confirmed'] ?? false) && filled($this->account()->nif)
            && ($confirmation = $deduction->exemptionConfirmation($this->user()->name, $this->account()->nif)) !== null) {
            app(ConfirmFiscalProfile::class)->handle($this->user(), $confirmation);
        }

        Notification::make()->title(__('Your activity is open in Contiu'))->success()->send();

        $this->redirect(Dashboard::getUrl());
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function account(): Account
    {
        /** @var Account */
        return Filament::getTenant();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('I have opened my activity');
    }
}
