<?php

namespace Rutgers\PerceptiveClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Rutgers\PerceptiveClient\PerceptiveClient
 */
class PerceptiveClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Rutgers\PerceptiveClient\PerceptiveClient::class;
    }
}
