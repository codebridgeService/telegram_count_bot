<?php

use App\Jobs\SendStatsSummaryJob;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/public.php';
require __DIR__.'/api/auth.php';
require __DIR__.'/api/protected.php';
require __DIR__.'/api/admin.php';
require __DIR__.'/api/customer.php';
require __DIR__.'/api/owner.php';


Route::get('/test-send-summary', function () {
    SendStatsSummaryJob::dispatch('day');

    return response()->json([
        'ok' => true,
        'message' => 'SendStatsSummaryJob dispatched successfully',
    ]);
});