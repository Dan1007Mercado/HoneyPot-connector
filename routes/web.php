<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\GuestBookingController;
use App\Http\Controllers\GuestCheckController;
use App\Http\Controllers\MonitoredLoginController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// =========================================================================
// PUBLIC ROUTES
// =========================================================================

Route::get('/', function () {
    return view('login');
})->name('home');

Route::get('/hotel-website', [GuestBookingController::class, 'home'])->name('guest.home.page');

// PUBLIC EMAIL VALIDATION ROUTE - FIXED FOR RENDER
Route::post('/check-email', [ReservationController::class, 'checkEmail'])->name('check.email.public');

// Stripe Payment Callback Routes
Route::prefix('payments')->group(function () {
    Route::get('/qr-code-display', function(Request $request) {
        return view('components.payments.qr-code-modal', [
            'payment_url' => $request->get('payment_url'),
            'session_id' => $request->get('session_id'),
            'reservation_id' => $request->get('reservation_id'),
            'amount' => $request->get('amount')
        ]);
    })->name('payments.qr-code-display');
    
    Route::get('/stripe/success', [PaymentController::class, 'stripeSuccess'])->name('payments.stripe.success');
    Route::get('/stripe/cancel', [PaymentController::class, 'stripeCancel'])->name('payments.stripe.cancel');
});

// Gmail OAuth Callback
Route::get('/oauth2callback', function(Request $request) {
    $code = $request->query('code');

    if (!$code) {
        return 'No authorization code received';
    }

    $client = new Google_Client();
    $client->setAuthConfig(storage_path('app/credentials.json'));
    $client->setRedirectUri('http://localhost:8000/oauth2callback');
    $client->setAccessType('offline');
    $client->setScopes([Google_Service_Gmail::GMAIL_SEND]);

    // Exchange code for token
    $accessToken = $client->fetchAccessTokenWithAuthCode($code);

    // Save token
    $tokenPath = storage_path('app/gmail-token.json');
    if (!file_exists(dirname($tokenPath))) {
        mkdir(dirname($tokenPath), 0700, true);
    }
    file_put_contents($tokenPath, json_encode($accessToken));

    return 'Gmail authorization successful! Token saved.';
});

// Stripe Webhook
Route::post('/stripe/webhook', [PaymentController::class, 'handleWebhook'])->withoutMiddleware(['csrf']);

// =========================================================================
// HOTEL WEBSITE & GUEST BOOKING
// =========================================================================

Route::get('/hotel', [GuestBookingController::class, 'home'])->name('guest.home');

Route::prefix('hotel')->group(function () {
    Route::post('/check-availability', [GuestBookingController::class, 'checkAvailability'])->name('guest.check-availability');
    Route::get('/room/{roomType}', [GuestBookingController::class, 'roomDetails'])->name('guest.room.details');
    Route::post('/booking/prepare', [GuestBookingController::class, 'prepareBooking'])->name('guest.booking.prepare');
    Route::post('/booking/confirm', [GuestBookingController::class, 'confirmBooking'])->name('guest.booking.confirm');
    Route::get('/booking/success/{reservation}', [GuestBookingController::class, 'bookingSuccess'])->name('guest.booking.success');
    Route::get('/booking/cancel/{reservation}', [GuestBookingController::class, 'bookingCancel'])->name('guest.booking.cancel');
    Route::get('/booking/check-payment-status', [GuestBookingController::class, 'checkPaymentStatus'])->name('guest.booking.check-payment-status');
    // Public email check for hotel website
    Route::post('/check-email', [GuestBookingController::class, 'checkEmail'])->name('guest.check-email');
});

// =========================================================================
// AUTHENTICATION
// =========================================================================

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::match(['get', 'post'], '/security/monitored-login', MonitoredLoginController::class)->name('security.monitored-login');

// =========================================================================
// PROTECTED ROUTES (AUTHENTICATED)
// =========================================================================

Route::middleware('auth')->group(function () {
    
    // General Dashboard & Guests
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/guests', [TransactionController::class, 'index'])->name('guests');
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    
    // Guest Check-in/Out
    Route::prefix('guest-check')->group(function () {
        Route::get('/', [GuestCheckController::class, 'index'])->name('guest-check.index');
        Route::post('/check-in/{booking}', [GuestCheckController::class, 'checkIn'])->name('guest-check.checkin');
        Route::post('/check-out/{booking}', [GuestCheckController::class, 'checkOut'])->name('guest-check.checkout');
        Route::post('/quick-checkin/{booking}', [GuestCheckController::class, 'quickCheckIn'])->name('guest-check.quick-checkin');
        Route::post('/quick-checkout/{booking}', [GuestCheckController::class, 'quickCheckOut'])->name('guest-check.quick-checkout');
    });
    // Legacy Route
    Route::get('/Guest-Checkin', [GuestCheckController::class, 'index'])->name('checkin');
    
    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/generate', [ReportController::class, 'generate'])->name('reports.generate');
        Route::get('/download/{id}', [ReportController::class, 'download'])->name('reports.download');
        Route::get('/view/{id}', [ReportController::class, 'view'])->name('reports.view');
        Route::delete('/{id}', [ReportController::class, 'destroy'])->name('reports.destroy');
        Route::get('/quick/{type}', [ReportController::class, 'quickReport'])->name('reports.quick');
    });
    
    // Legacy Report Routes
    Route::get('/report', [ReportController::class, 'index'])->name('report'); // Single: 'report'
    Route::post('/report/generate', [ReportController::class, 'generate'])->name('report.generate');
    Route::get('/report/download/{id}', [ReportController::class, 'download'])->name('report.download');
    Route::get('/report/view/{id}', [ReportController::class, 'view'])->name('report.view');
    
    // Reservations
    Route::prefix('reservations')->group(function () {
        // Image Helper
        Route::get('/room-images/{filename}', function ($filename) {
            $path = storage_path('app/public/room-images/' . $filename);
            if (!file_exists($path)) abort(404);
            return response()->file($path);
        })->name('reservations.room-images');
        
        // CRUD & Actions
        Route::get('/', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/create', [ReservationController::class, 'create'])->name('reservations.create');
        Route::post('/', [ReservationController::class, 'store'])->name('reservations.store');
        Route::get('/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::get('/{reservation}/edit', [ReservationController::class, 'edit'])->name('reservations.edit');
        Route::put('/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
        Route::delete('/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy');
        Route::post('/confirm/{reservation}', [ReservationController::class, 'confirm'])->name('reservations.confirm');
        Route::get('/{reservation}/payment-details', [ReservationController::class, 'getPaymentDetails'])->name('reservations.payment-details');
        
        // AJAX
        Route::post('/available-rooms', [ReservationController::class, 'getAvailableRooms'])->name('reservations.available-rooms');
        Route::post('/hold-rooms', [ReservationController::class, 'holdRooms'])->name('reservations.hold-rooms');
        Route::post('/check-availability', [ReservationController::class, 'checkAvailability'])->name('reservations.check-availability');
        
        // PROTECTED EMAIL CHECK (for staff/admin only)
        Route::post('/check-email', [ReservationController::class, 'checkEmail'])->name('reservations.check-email');
        
        // Redirect
        Route::get('/bookings', function (Request $request) {
            $checkInDate = $request->get('check_in_date', today()->format('Y-m-d'));
            return redirect()->route('reservations.index', ['check_in_date' => $checkInDate]);
        })->name('bookings.index');
    });
    
    // Rooms
    Route::prefix('rooms')->group(function () {
        Route::get('/', [RoomsController::class, 'index'])->name('rooms');
        Route::post('/', [RoomsController::class, 'store'])->name('rooms.store');
        Route::put('/{room}', [RoomsController::class, 'update'])->name('rooms.update');
        Route::delete('/{room}', [RoomsController::class, 'destroy'])->name('rooms.destroy');
    });

    // Payment Processing (Staff)
    Route::prefix('payments')->group(function () {
        Route::get('/reservations/{reservation}/payment-details', [PaymentController::class, 'getPaymentDetails'])->name('payments.details');
        Route::post('/process-cash', [PaymentController::class, 'processCashPayment'])->name('payments.process-cash');
        Route::post('/process-card', [PaymentController::class, 'processCardPayment'])->name('payments.process-card');
        Route::post('/process-online', [PaymentController::class, 'processOnlinePayment'])->name('payments.process-online');
        Route::get('/qrcode', [PaymentController::class, 'getPaymentQRCode'])->name('payments.qrcode');
        Route::get('/check-status', [PaymentController::class, 'checkPaymentStatus'])->name('payments.check-status');
    });

    // =================================================================
    // ADMIN ROUTES
    // =================================================================
    Route::middleware(['auth', 'admin'])->group(function () {
        Route::prefix('employee')->group(function () {
            Route::get('/', [UsersController::class, 'index'])->name('users.index');
            Route::post('/', [UsersController::class, 'store'])->name('users.store');
            Route::put('/{user}', [UsersController::class, 'update'])->name('users.update');
            Route::delete('/{user}', [UsersController::class, 'destroy'])->name('users.destroy');
        });

        Route::prefix('admin')->group(function () {
            Route::get('/receptionist/dashboard', [DashboardController::class, 'index'])->name('admin.receptionist.dashboard');
            Route::get('/receptionist/reservations', [ReservationController::class, 'index'])->name('admin.receptionist.reservations');
            Route::get('/receptionist/rooms', [RoomsController::class, 'index'])->name('admin.receptionist.rooms');
            Route::get('/receptionist/guests', [TransactionController::class, 'index'])->name('admin.receptionist.guests');
            Route::get('/receptionist/report', [ReportController::class, 'index'])->name('admin.receptionist.report');
            Route::get('/receptionist/guest-check', [GuestCheckController::class, 'index'])->name('admin.receptionist.guest-check');
        });
    });

    // =================================================================
    // RECEPTIONIST ROUTES
    // =================================================================
    Route::middleware(['auth', 'receptionist'])->group(function () {
        Route::get('/receptionist/dashboard', [DashboardController::class, 'index'])->name('receptionist.dashboard');

        Route::prefix('receptionist/reservations')->group(function () {
            Route::get('/room-images/{filename}', function ($filename) {
                $path = storage_path('app/public/room-images/' . $filename);
                if (!file_exists($path)) abort(404);
                return response()->file($path);
            })->name('receptionist.reservations.room-images');
            
            Route::get('/', [ReservationController::class, 'index'])->name('receptionist.reservations');
            Route::get('/create', [ReservationController::class, 'create'])->name('receptionist.reservations.create');
            Route::post('/', [ReservationController::class, 'store'])->name('receptionist.reservations.store');
            Route::get('/{reservation}', [ReservationController::class, 'show'])->name('receptionist.reservations.show');
            Route::get('/{reservation}/edit', [ReservationController::class, 'edit'])->name('receptionist.reservations.edit');
            Route::put('/{reservation}', [ReservationController::class, 'update'])->name('receptionist.reservations.update');
            Route::delete('/{reservation}', [ReservationController::class, 'destroy'])->name('receptionist.reservations.destroy');
            Route::post('/confirm/{reservation}', [ReservationController::class, 'confirm'])->name('receptionist.reservations.confirm');
            Route::get('/{reservation}/payment-details', [ReservationController::class, 'getPaymentDetails'])->name('receptionist.reservations.payment-details');
            
            // AJAX
            Route::post('/get-available-rooms', [ReservationController::class, 'getAvailableRooms'])->name('receptionist.reservations.get-available-rooms');
            Route::post('/hold-rooms', [ReservationController::class, 'holdRooms'])->name('receptionist.reservations.hold-rooms');
            Route::post('/check-availability', [ReservationController::class, 'checkAvailability'])->name('receptionist.reservations.check-availability');
            
        });

        Route::prefix('receptionist/rooms')->group(function () {
            Route::get('/', [RoomsController::class, 'index'])->name('receptionist.rooms');
            Route::post('/', [RoomsController::class, 'store'])->name('receptionist.rooms.store');
            Route::put('/{room}', [RoomsController::class, 'update'])->name('receptionist.rooms.update');
            Route::delete('/{room}', [RoomsController::class, 'destroy'])->name('receptionist.rooms.destroy');
        });

        Route::prefix('receptionist/guest-check')->group(function () {
            Route::get('/', [GuestCheckController::class, 'index'])->name('receptionist.guest-check');
            Route::post('/check-in/{booking}', [GuestCheckController::class, 'checkIn'])->name('receptionist.guest-check.checkin');
            Route::post('/check-out/{booking}', [GuestCheckController::class, 'checkOut'])->name('receptionist.guest-check.checkout');
            Route::post('/quick-checkin/{booking}', [GuestCheckController::class, 'quickCheckIn'])->name('receptionist.guest-check.quick-checkin');
            Route::post('/quick-checkout/{booking}', [GuestCheckController::class, 'quickCheckOut'])->name('receptionist.guest-check.quick-checkout');
        });

        Route::prefix('receptionist/reports')->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('receptionist.reports');
            Route::post('/generate', [ReportController::class, 'generate'])->name('receptionist.reports.generate');
            Route::get('/download/{id}', [ReportController::class, 'download'])->name('receptionist.reports.download');
            Route::get('/view/{id}', [ReportController::class, 'view'])->name('receptionist.reports.view');
        });

        Route::get('/receptionist/guests', [TransactionController::class, 'index'])->name('receptionist.guests');
        Route::get('/receptionist/report', [ReportController::class, 'index'])->name('receptionist.report');
        Route::get('/receptionist/Guest-Checkin', [GuestCheckController::class, 'index'])->name('receptionist.checkin');
    });
    
});

// =========================================================================
// PUBLIC GUEST BOOKING MODAL AJAX
// =========================================================================

Route::prefix('booking')->group(function () {
    Route::post('/confirm', [GuestBookingController::class, 'confirmBooking'])->name('guest.booking.confirm');
    Route::post('/process-payment', [GuestBookingController::class, 'confirmBooking'])->name('guest.booking.process-payment');
});

// =========================================================================
// PUBLIC REPORTS (SHARED LINKS)
// =========================================================================

Route::prefix('public')->group(function () {
    Route::get('/report/{id}/{token}', [ReportController::class, 'publicView'])
        ->name('reports.public.view')
        ->middleware('throttle:60,1');
    
    Route::get('/report/download/{id}/{token}', [ReportController::class, 'publicDownload'])
        ->name('reports.public.download')
        ->middleware('throttle:30,1');
});

// =========================================================================
// API ROUTES (ASYNC REPORTING)
// =========================================================================

Route::prefix('api')->middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/report/preview', [ReportController::class, 'previewReport'])->name('api.report.preview');
    Route::post('/report/generate-async', [ReportController::class, 'generateAsync'])->name('api.report.generate-async');
    Route::get('/report/status/{jobId}', [ReportController::class, 'checkGenerationStatus'])->name('api.report.status');
    Route::get('/reports/list', [ReportController::class, 'listReports'])->name('api.reports.list');
});

// =========================================================================
// FALLBACK
// =========================================================================

Route::fallback(function () {
    return view('errors.404');
});
