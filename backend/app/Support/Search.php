<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Search
{
    public static function contains(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $value = '%'.addcslashes($term, '%_\\').'%';

        return $query->where($column, $operator, $value, $boolean);
    }
}
