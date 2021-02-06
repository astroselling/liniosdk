<?php

namespace Astroselling\LinioSdk\Facades;

use Illuminate\Support\Facades\Facade;

class LinioSdk extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'liniosdk';
    }
}
