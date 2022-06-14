<?php

namespace PowerComponents\LivewirePowerGrid\Tests\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Category extends Model
{
    use SoftDeletes;

    protected $table = 'categories';

    protected $fillable = [
        'name',
    ];

    public function dishes()
    {
        return $this->hasMany(Dish::class, 'category_id');
    }
}
