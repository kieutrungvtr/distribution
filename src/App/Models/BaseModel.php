<?php


namespace PLSys\DistrbutionQueue\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class BaseModel extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new OnlyActiveModels());
    }

}

class OnlyActiveModels implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        //Get column name deleted_at was qualify with table name
        $table = $model->getTable();
        if (substr($table, -1) === 's') {
            $prefixColumn = substr($table, 0, -1);
        } else {
            $prefixColumn = $table;
        }
        $deletedAtColumn = $prefixColumn . '_deleted_at';
        $builder->whereNull($deletedAtColumn);
    }
}