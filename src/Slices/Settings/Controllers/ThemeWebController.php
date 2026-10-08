<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ThemeWebController extends Controller
{
    /**
     * Render the interactive Theme Studio.
     */
    public function index(): View
    {
        return view('settings::theme');
    }
}
