<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

class TaxWebController extends Controller
{
    public function index(): View
    {
        app('view')->share('title', (string) trans('firefly.tax'));
        app('view')->share('mainTitleIcon', 'fa-file-invoice-dollar');

        return view('extensions.tax.index');
    }

    public function show(int $id): View
    {
        app('view')->share('title', (string) trans('firefly.tax'));
        app('view')->share('mainTitleIcon', 'fa-file-invoice-dollar');

        return view('extensions.tax.show', ['profileId' => $id]);
    }
}
