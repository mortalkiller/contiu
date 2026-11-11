<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\ManageArticles;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Resources\Articles\Tables\ArticlesTable;
use App\Models\Article;
use App\Models\IntegrationRecord;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The optional catalogue of what is billed often. It has no place in the menu: it is reached from
 * the fiscal documents list.
 */
class ArticleResource extends Resource
{
    /** Set on each article by getEloquentQuery() while a provider is connected: its record there, if any. */
    public const string PROVIDER_RECORD_ATTRIBUTE = 'provider_record_id';

    protected static ?string $model = Article::class;

    protected static ?string $slug = 'artigos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('article');
    }

    public static function getPluralModelLabel(): string
    {
        return __('articles');
    }

    /**
     * Articles have no policy, so the answer comes from the account gates: members of the account
     * may use them, an accountant may not, and a read-only account is refused every write.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $account = CurrentAccount::get();

        if ($account === null || ($record !== null && $record->account_id !== $account->getKey())) {
            return Response::deny();
        }

        if (! in_array($action, ['viewAny', 'view', 'create', 'update', 'delete'], true)) {
            return Response::deny();
        }

        return Gate::forUser(Filament::auth()->user())->inspect($action, $record ?? Article::class);
    }

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    /**
     * Each article with how many document lines use it and, while a provider is connected, whether
     * it already exists there. Both are read in the same query as the articles themselves.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('lines');
        $connection = Invoicing::connection();

        if ($connection === null) {
            return $query;
        }

        return $query->addSelect([
            self::PROVIDER_RECORD_ATTRIBUTE => IntegrationRecord::query()
                ->select('integration_records.id')
                ->where('integration_connection_id', $connection->getKey())
                ->where('record_type', (new Article)->getMorphClass())
                ->whereColumn('record_id', 'articles.id')
                ->limit(1),
        ]);
    }

    /** Whether the article already exists at the connected provider. */
    public static function isAtProvider(Article $article): bool
    {
        return $article->getAttribute(self::PROVIDER_RECORD_ATTRIBUTE) !== null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArticles::route('/'),
        ];
    }
}
