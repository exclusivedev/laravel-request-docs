<?php

namespace ExclusiveDev\LaravelRequestDocs;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ExclusiveDev\LaravelRequestDocs\LaravelRequestDocs
 */
class LaravelRequestDocsFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-request-docs';
    }
}
