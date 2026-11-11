<?php

namespace App\Filament\Support;

use App\Enums\CategoryType;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Models\Category;
use App\Models\Client;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * The "+" beside a select of clients, suppliers or categories: it opens the same form as that
 * record's own "New …" screen, saves the record and picks it. Works on a select with or without
 * an Eloquent relationship: the record is created here either way, so the account and the
 * model's own rules (a client's tax profile, for one) apply as on its screen.
 *
 * Projects and retainers are not here: creating them also builds their payment plan or periods,
 * which only their own screens do.
 */
final class QuickCreate
{
    public static function client(Select $select): Select
    {
        return $select
            ->createOptionForm(fn (Schema $schema): Schema => ClientForm::configure($schema->model(Client::class)))
            ->createOptionUsing(function (array $data, Schema $schema): int {
                $client = Client::query()->create($data);
                $schema->model($client)->saveRelationships();

                return $client->getKey();
            })
            ->createOptionModalHeading(__('New client'))
            ->createOptionAction(fn (Action $action): Action => $action->modalWidth(Width::ThreeExtraLarge)->databaseTransaction());
    }

    public static function supplier(Select $select): Select
    {
        return $select
            ->createOptionForm(fn (Schema $schema): Schema => SupplierForm::configure($schema))
            ->createOptionUsing(fn (array $data): int => Supplier::query()->create($data)->getKey())
            ->createOptionModalHeading(__('New supplier'))
            ->createOptionAction(fn (Action $action): Action => $action->modalWidth(Width::TwoExtraLarge));
    }

    /**
     * A category of the kind the select lists (income or expense): the form opens on that kind,
     * so the new category is one the select can show.
     */
    public static function category(Select $select, CategoryType $type): Select
    {
        return $select
            ->createOptionForm(fn (Schema $schema): Schema => CategoryForm::configure($schema))
            ->createOptionUsing(fn (array $data): int => Category::query()->create([...$data, 'type' => $data['type'] ?? $type])->getKey())
            ->createOptionModalHeading(__('New category'))
            ->createOptionAction(fn (Action $action): Action => $action->modalWidth(Width::Medium)->fillForm(['type' => $type, 'sort' => 99]));
    }
}
