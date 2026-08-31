<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'graph' => 'array',
    ];
}
