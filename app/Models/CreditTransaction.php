<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const HOLD = 'hold';

    public const HOLD_RELEASE = 'hold_release';

    public const CHARGE = 'charge';

    protected $guarded = [];
}
