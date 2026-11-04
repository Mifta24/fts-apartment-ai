<?php

use App\Http\Controllers\Admin\ApartmentSettingController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HandoverController;
use App\Http\Controllers\Admin\KnowledgeItemController;
use App\Http\Controllers\Admin\UnitInventoryController;
use App\Http\Controllers\Admin\UnitTypeController;
use App\Http\Controllers\Admin\ViewingController;
use App\Http\Controllers\ApartmentPageController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConciergeChatController;
use App\Http\Controllers\ReservationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [ApartmentPageController::class, 'index'])->name('home');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('unit-types', UnitTypeController::class)->except('show');
        Route::get('unit-types/{unitType}/inventory', [UnitInventoryController::class, 'index'])->name('unit-types.inventory.index');
        Route::post('unit-types/{unitType}/inventory', [UnitInventoryController::class, 'store'])->name('unit-types.inventory.store');
        Route::resource('knowledge-items', KnowledgeItemController::class)->except('show');

        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::patch('bookings/{booking}/status', [BookingController::class, 'updateStatus'])->name('bookings.status');

        Route::get('viewings', [ViewingController::class, 'index'])->name('viewings.index');
        Route::patch('viewings/{viewing}/status', [ViewingController::class, 'updateStatus'])->name('viewings.status');

        Route::get('settings', [ApartmentSettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [ApartmentSettingController::class, 'update'])->name('settings.update');

        Route::get('handovers', [HandoverController::class, 'index'])->name('handovers.index');
        Route::get('handovers/{handover}', [HandoverController::class, 'show'])->name('handovers.show');
        Route::post('handovers/{handover}/reply', [HandoverController::class, 'reply'])->name('handovers.reply');
        Route::post('handovers/{handover}/resolve', [HandoverController::class, 'resolve'])->name('handovers.resolve');
    });
});

Route::prefix('{apartmentSlug}')->group(function () {
    Route::get('/', [ApartmentPageController::class, 'show'])->name('apartment.show');
    Route::get('units', [ApartmentPageController::class, 'units'])->name('apartment.units');
    Route::get('units/{unitSlug}', [ApartmentPageController::class, 'unit'])->name('apartment.unit');
    Route::get('facilities', [ApartmentPageController::class, 'facilities'])->name('apartment.facilities');
    Route::get('facilities/{facilityId}', [ApartmentPageController::class, 'facility'])->whereNumber('facilityId')->name('apartment.facility');
    Route::get('info', [ApartmentPageController::class, 'info'])->name('apartment.info');
    Route::get('staff', [ApartmentPageController::class, 'staff'])->name('apartment.staff');
    Route::get('apply', [ApartmentPageController::class, 'reservationScene'])->name('apartment.reservation');
    Route::get('reservation', fn (Request $request, string $apartmentSlug) => redirect()->route('apartment.reservation', ['apartmentSlug' => $apartmentSlug, ...$request->query()], 301));

    Route::prefix('apply')->name('reservation.')->middleware('throttle:20,1')->group(function () {
        Route::get('availability', [ReservationController::class, 'availability'])->name('availability');
        Route::post('quote', [ReservationController::class, 'quote'])->name('quote');
        Route::post('/', [ReservationController::class, 'store'])->name('store');
        Route::post('viewing', [ReservationController::class, 'storeViewing'])->name('viewing');
    });

    Route::prefix('concierge')->name('concierge.')->group(function () {
        Route::post('start', [ConciergeChatController::class, 'start'])->middleware('throttle:concierge-start')->name('start');
        Route::post('message', [ConciergeChatController::class, 'message'])->middleware('throttle:concierge-message')->name('message');
        Route::post('handover', [ConciergeChatController::class, 'leaveMessage'])->middleware('throttle:concierge-handover')->name('handover');
        Route::post('rate', [ConciergeChatController::class, 'rate'])->middleware('throttle:concierge-feedback')->name('rate');
        Route::post('transcript', [ConciergeChatController::class, 'sendTranscript'])->middleware('throttle:concierge-transcript')->name('transcript');
        Route::get('history', [ConciergeChatController::class, 'history'])->middleware('throttle:concierge-history')->name('history');
    });
});
