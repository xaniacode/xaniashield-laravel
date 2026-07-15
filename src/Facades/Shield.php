<?php

namespace XaniaShield\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use XaniaShield\Laravel\ShieldClient;

/**
 * @method static \XaniaShield\Laravel\ShieldResult checkRequest(\Illuminate\Http\Request $request, array $data = [])
 * @method static \XaniaShield\Laravel\ShieldResult analyze(array $data)
 * @method static bool configured()
 * @method static bool health()
 * @method static string timingField()
 * @method static string|null timingToken()
 *
 * @see \XaniaShield\Laravel\ShieldClient
 */
class Shield extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ShieldClient::class;
    }
}
