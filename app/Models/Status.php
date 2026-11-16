<?php

namespace App\Models;

use Database\Factories\StatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Job status lookup. Names the booking rules rely on are listed in App\Enums\JobStatus.
 */
#[Fillable(['name'])]
class Status extends Model
{
    /** @use HasFactory<StatusFactory> */
    use HasFactory;
}
