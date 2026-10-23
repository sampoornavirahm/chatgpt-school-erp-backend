<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResetRequestAuthGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ensure long-lived workers and repeated requests resolve the bearer token afresh.
        Auth::forgetGuards();

        return $next($request);
    }
}
