<?php

namespace App\Http\Controllers;

class ConfigController extends Controller
{
    /**
     * Public, unauthenticated - lets the frontend know at runtime whether
     * to show the register flow, and where custom domains should point,
     * without needing a rebuild to change either.
     */
    public function index()
    {
        return [
            'registration_enabled' => (bool) config('features.registration_enabled'),
            'custom_domain_target' => config('features.custom_domain_target'),
        ];
    }
}
