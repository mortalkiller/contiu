<?php

namespace App\Models;

use App\Support\Appearance\Palette;
use App\Support\Appearance\PaletteRegistry;
use Database\Factories\UserPaletteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A colour palette a user made for themselves (Perfil → Aparência). Belongs to the user, not to
 * an account: it follows them into every account they open.
 */
#[Fillable(['name', 'primary_color', 'navigation_color', 'background_color'])]
class UserPalette extends Model
{
    /** @use HasFactory<UserPaletteFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The value stored in users.palette when this palette is the chosen one. */
    public function key(): string
    {
        return PaletteRegistry::CUSTOM_PREFIX.$this->getKey();
    }

    public function toPalette(): Palette
    {
        return Palette::custom(
            id: $this->key(),
            label: $this->name,
            primary: $this->primary_color,
            navigation: $this->navigation_color,
            background: $this->background_color,
        );
    }
}
