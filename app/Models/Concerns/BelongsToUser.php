<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the authenticated user.
 *
 * The global scope and the creating-hook only fire when a user is
 * authenticated, so background jobs, importers and Artisan commands
 * (which run without an auth context) keep seeing every record.
 */
trait BelongsToUser
{
    protected static function bootBelongsToUser(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') === null && auth()->check()) {
                $model->setAttribute('user_id', auth()->id());
            }
        });

        static::addGlobalScope('user', function (Builder $builder): void {
            if (auth()->check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', auth()->id());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
