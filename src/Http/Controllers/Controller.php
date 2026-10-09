<?php

namespace TMAn1An\FormBuilder\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;
use TMAn1An\FormBuilder\FormBuilder;

abstract class Controller extends BaseController
{
    use AuthorizesRequests;

    protected function fb(): FormBuilder
    {
        return app(FormBuilder::class);
    }
}
