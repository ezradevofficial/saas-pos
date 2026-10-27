<?php

use Illuminate\Support\Facades\Route;
use Modules\Salon\Booking\ResolveBooking;
use Modules\Salon\Booking\SecurityHeaders;
use Modules\Salon\Http\Controllers\PublicBookingController;
use Modules\Salon\SalonServiceProvider;

// SAL-15, SAL-26: the public booking page, prefix /api/v1. No tenant token: the branch is found by its code alone
// (ResolveBooking, a security-definer lookup that returns only the tenant id, ADR 002). Every route is rate-limited per
// address; writes more tightly. Parameters are plain strings (model binding would run before the tenant is entered).

Route::middleware([SecurityHeaders::class, 'throttle:'.SalonServiceProvider::BOOKING_LIMITER])->prefix('salon-booking/{code}')->where(['code' => '[A-Za-z0-9]{6,24}'])
    ->middleware(ResolveBooking::class)->group(function () {
        Route::get('/', [PublicBookingController::class, 'show']);
        Route::get('slots', [PublicBookingController::class, 'slots']);
        Route::post('appointments', [PublicBookingController::class, 'store'])->middleware('throttle:'.SalonServiceProvider::BOOKING_WRITE_LIMITER);
        Route::post('appointments/{number}/pay', [PublicBookingController::class, 'pay'])->where('number', '[A-Za-z0-9-]{1,40}')->middleware('throttle:'.SalonServiceProvider::BOOKING_WRITE_LIMITER);
        Route::get('appointments/{number}', [PublicBookingController::class, 'status'])->where('number', '[A-Za-z0-9-]{1,40}')->middleware('throttle:'.SalonServiceProvider::BOOKING_WRITE_LIMITER);
    });
