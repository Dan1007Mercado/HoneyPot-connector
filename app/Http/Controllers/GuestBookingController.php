<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment;
use App\Services\StripePaymentService;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GuestBookingController extends Controller
{
    protected $gmailService;
    protected $stripeService;

    public function __construct()
    {
        $this->gmailService = new GmailService();
        $this->stripeService = new StripePaymentService();
    }

    public function home()
    {
        Log::info('Loading home page with room types');
        
        $roomTypes = RoomType::with(['rooms' => function($query) {
            $query->where('room_status', 'available')->limit(5);
        }])
        ->withCount(['rooms' => function($query) {
            $query->where('room_status', 'available');
        }])
        ->get();
        
        Log::info('Found ' . $roomTypes->count() . ' room types');
        
        return view('home', compact('roomTypes'));
    }

    public function index()
    {
        Log::info('Hotel index page accessed');
        return $this->home();
    }

    // ADD THIS EMAIL CHECK METHOD
    public function checkEmail(Request $request)
    {
        try {
            Log::info('Guest email check request', $request->only(['email', 'first_name', 'last_name']));
            
            $request->validate([
                'email' => 'required|email|max:150',
                'first_name' => 'nullable|string|max:100',
                'last_name' => 'nullable|string|max:100'
            ]);

            $email = $request->email;
            $firstName = $request->first_name;
            $lastName = $request->last_name;

            // Check if email exists in guests table
            $guest = Guest::where('email', $email)->first();

            if (!$guest) {
                // New guest - email is available
                Log::info('Email is available for new guest: ' . $email);
                return response()->json([
                    'exists' => false,
                    'conflict' => false,
                    'guest_name' => null,
                    'message' => 'Email is available'
                ]);
            }

            // Email exists - check for name conflicts
            Log::info('Email exists for guest ID: ' . $guest->guest_id . ' - ' . $guest->first_name . ' ' . $guest->last_name);
            
            $nameConflict = false;
            $errorMessage = null;
            
            if ($firstName && $lastName) {
                // Check if the name matches the existing guest
                $nameConflict = strtolower($guest->first_name) !== strtolower($firstName) || 
                               strtolower($guest->last_name) !== strtolower($lastName);
                
                if ($nameConflict) {
                    $errorMessage = 'Email already registered to ' . $guest->first_name . ' ' . $guest->last_name;
                    Log::warning('Name conflict detected: ' . $errorMessage);
                }
            }

            if ($nameConflict) {
                return response()->json([
                    'exists' => true,
                    'conflict' => true,
                    'error_message' => $errorMessage,
                    'guest_name' => $guest->first_name . ' ' . $guest->last_name
                ]);
            }

            // Existing guest with matching name (or no name provided)
            Log::info('Email belongs to existing guest with matching name: ' . $guest->first_name . ' ' . $guest->last_name);
            return response()->json([
                'exists' => true,
                'conflict' => false,
                'guest_name' => $guest->first_name . ' ' . $guest->last_name,
                'message' => 'Welcome back ' . $guest->first_name . '!'
            ]);

        } catch (\Exception $e) {
            Log::error('Guest email check error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'exists' => false,
                'conflict' => false,
                'message' => 'Email validation failed. Please try again.'
            ], 500);
        }
    }

    public function checkAvailability(Request $request)
    {
        try {
            Log::info('Guest check availability request started', $request->only([
                'check_in_date', 'check_out_date', 'room_type_id', 'num_rooms',
            ]));
            
            $request->validate([
                'check_in_date' => 'required|date|after_or_equal:today',
                'check_out_date' => 'required|date|after:check_in_date',
                'room_type_id' => 'required|exists:room_types,room_type_id',
                'num_rooms' => 'required|integer|min:1'
            ]);

            Log::info('Validation passed for availability check');
            
            // FIXED: Using scope for clean availability check
            $availableRooms = Room::where('room_type_id', $request->room_type_id)
                ->availableBetween($request->check_in_date, $request->check_out_date)
                ->get();

            Log::info('Found ' . $availableRooms->count() . ' available rooms out of ' . $request->num_rooms . ' needed');

            $roomType = RoomType::find($request->room_type_id);
            if (!$roomType) {
                Log::error('Room type not found: ' . $request->room_type_id);
                return response()->json([
                    'success' => false,
                    'message' => 'Room type not found'
                ], 404);
            }
            
            $checkIn = Carbon::parse($request->check_in_date);
            $checkOut = Carbon::parse($request->check_out_date);
            $nights = $checkIn->diffInDays($checkOut);
            
            Log::info('Check-in: ' . $checkIn . ', Check-out: ' . $checkOut . ', Nights: ' . $nights);
            Log::info('Room price: ' . $roomType->base_price . ', Total amount: ' . ($roomType->base_price * $nights * $request->num_rooms));

            return response()->json([
                'success' => true,
                'available_rooms' => $availableRooms->count(),
                'rooms_needed' => $request->num_rooms,
                'is_available' => $availableRooms->count() >= $request->num_rooms,
                'room_type' => $roomType,
                'nights' => $nights,
                'total_amount' => $roomType->base_price * $nights * $request->num_rooms,
                'available_rooms_list' => $availableRooms->map(function($room) {
                    return [
                        'room_id' => $room->room_id,
                        'room_number' => $room->room_number,
                        'floor' => $room->floor,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            Log::error('Check availability error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error checking availability: ' . $e->getMessage()
            ], 500);
        }
    }

    public function roomDetails($roomTypeId)
    {
        Log::info('Loading room details for room type: ' . $roomTypeId);
        
        $roomType = RoomType::with(['rooms' => function($query) {
            $query->where('room_status', 'available');
        }])
        ->withCount(['rooms' => function($query) {
            $query->where('room_status', 'available');
        }])
        ->findOrFail($roomTypeId);
        
        $similarRooms = RoomType::where('room_type_id', '!=', $roomTypeId)
            ->where('is_active', true)
            ->limit(3)
            ->get();

        Log::info('Found room type: ' . $roomType->type_name . ' with ' . $roomType->rooms_count . ' available rooms');
        
        return view('guest.room-details', compact('roomType', 'similarRooms'));
    }

    public function prepareBooking(Request $request)
    {
        try {
            Log::info('Prepare booking request started', $request->only([
                'room_type_id', 'check_in_date', 'check_out_date', 'num_rooms', 'num_guests',
            ]));
            
            $request->validate([
                'room_type_id' => 'required|exists:room_types,room_type_id',
                'check_in_date' => 'required|date|after_or_equal:today',
                'check_out_date' => 'required|date|after:check_in_date',
                'num_rooms' => 'required|integer|min:1|max:5',
                'num_guests' => 'required|integer|min:1|max:20'
            ]);

            Log::info('Validation passed for prepare booking');
            
            $roomType = RoomType::findOrFail($request->room_type_id);
            $checkIn = Carbon::parse($request->check_in_date);
            $checkOut = Carbon::parse($request->check_out_date);
            $nights = $checkIn->diffInDays($checkOut);
            $totalAmount = $roomType->base_price * $nights * $request->num_rooms;
            
            Log::info('Room type: ' . $roomType->type_name . ', Nights: ' . $nights . ', Total: ' . $totalAmount);

            // FIXED: Using scope for clean availability check
            $availableRooms = Room::where('room_type_id', $request->room_type_id)
                ->availableBetween($request->check_in_date, $request->check_out_date)
                ->limit($request->num_rooms)
                ->get();

            Log::info('Available rooms count for booking: ' . $availableRooms->count() . ', Needed: ' . $request->num_rooms);

            if ($availableRooms->count() < $request->num_rooms) {
                Log::warning('Insufficient available rooms for booking');
                return response()->json([
                    'success' => false,
                    'message' => 'Sorry, the selected rooms are no longer available. Please try different dates or room type.'
                ], 400);
            }

            $tempReference = 'TEMP-' . strtoupper(uniqid());
            Log::info('Generated temp reference: ' . $tempReference);

            session([
                'temp_booking' => [
                    'reference' => $tempReference,
                    'room_type_id' => $roomType->room_type_id,
                    'room_type_name' => $roomType->type_name,
                    'check_in_date' => $request->check_in_date,
                    'check_out_date' => $request->check_out_date,
                    'num_rooms' => $request->num_rooms,
                    'num_guests' => $request->num_guests,
                    'nights' => $nights,
                    'room_price' => $roomType->base_price,
                    'total_amount' => $totalAmount,
                    'available_room_ids' => $availableRooms->pluck('room_id')->toArray(),
                    'expires_at' => Carbon::now()->addMinutes(30)
                ]
            ]);

            Log::info('Temp booking stored in session, expires at: ' . Carbon::now()->addMinutes(30));

            return response()->json([
                'success' => true,
                'temp_reference' => $tempReference,
                'booking_summary' => [
                    'room_type' => $roomType->type_name,
                    'check_in' => $request->check_in_date,
                    'check_out' => $request->check_out_date,
                    'nights' => $nights,
                    'num_rooms' => $request->num_rooms,
                    'num_guests' => $request->num_guests,
                    'room_price' => number_format($roomType->base_price, 2),
                    'subtotal' => number_format($roomType->base_price * $nights * $request->num_rooms, 2),
                    'tax' => number_format(0, 2),
                    'total' => number_format($totalAmount, 2)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Prepare booking error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error preparing booking: ' . $e->getMessage()
            ], 500);
        }
    }

    public function confirmBooking(Request $request)
    {
        try {
            Log::info('Confirm booking request started');
            
            $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email|max:150',
                'contact_number' => 'required|string|max:20',
                'special_requests' => 'nullable|string|max:500',
                'temp_reference' => 'required|string'
            ]);

            Log::info('Validation passed for confirm booking');

            $tempBooking = session('temp_booking');
            
            Log::info('Temporary booking state loaded.', ['present' => $tempBooking !== null]);
            
            if (!$tempBooking || $tempBooking['reference'] !== $request->temp_reference) {
                Log::warning('Invalid temp booking reference or no session');
                return response()->json([
                    'success' => false,
                    'message' => 'Booking session expired or invalid. Please start over.'
                ], 400);
            }

            if (Carbon::parse($tempBooking['expires_at'])->isPast()) {
                Log::warning('Temp booking expired');
                session()->forget('temp_booking');
                return response()->json([
                    'success' => false,
                    'message' => 'Booking session has expired. Please start over.'
                ], 400);
            }

            // FIXED: Using scope for clean availability re-check
            $availableRooms = Room::where('room_type_id', $tempBooking['room_type_id'])
                ->availableBetween($tempBooking['check_in_date'], $tempBooking['check_out_date'])
                ->whereIn('room_id', $tempBooking['available_room_ids'])
                ->limit($tempBooking['num_rooms'])
                ->get();

            if ($availableRooms->count() < $tempBooking['num_rooms']) {
                Log::warning('Rooms no longer available at confirmation stage');
                session()->forget('temp_booking');
                return response()->json([
                    'success' => false,
                    'message' => 'The selected rooms are no longer available. Please try again.'
                ], 400);
            }

            Log::info('Starting database transaction for booking confirmation');
            DB::beginTransaction();
            
            try {
                $guest = Guest::where('email', $request->email)->first();
                
                if (!$guest) {
                    Log::info('Creating new guest');
                    $guest = Guest::create([
                        'first_name' => $request->first_name,
                        'last_name' => $request->last_name,
                        'email' => $request->email,
                        'contact_number' => $request->contact_number,
                        'guest_type' => 'advance'   
                    ]);
                    Log::info('Guest created with ID: ' . $guest->guest_id);
                } else {
                    Log::info('Updating existing guest ID: ' . $guest->guest_id);
                    $guest->update([
                        'first_name' => $request->first_name,
                        'last_name' => $request->last_name,
                        'contact_number' => $request->contact_number
                    ]);
                }

                Log::info('Creating reservation for guest ID: ' . $guest->guest_id);
                $reservation = Reservation::create([
                    'guest_id' => $guest->guest_id,
                    'room_type_id' => $tempBooking['room_type_id'],
                    'check_in_date' => $tempBooking['check_in_date'],
                    'check_out_date' => $tempBooking['check_out_date'],
                    'num_guests' => $tempBooking['num_guests'],
                    'total_amount' => $tempBooking['total_amount'],
                    'status' => 'pending',
                    'reservation_type' => 'advance',
                    'booking_source' => 'online',
                    'special_requests' => $request->special_requests,
                    'expires_at' => Carbon::now()->addHours(24)
                ]);

                Log::info('Reservation created with ID: ' . $reservation->reservation_id);

                Log::info('Creating booking for reservation ID: ' . $reservation->reservation_id);
                $booking = Booking::create([
                    'reservation_id' => $reservation->reservation_id,
                    'booking_status' => 'pending',
                    'booking_date' => now()
                ]);

                Log::info('Booking created with ID: ' . $booking->booking_id);

                // FIXED: Use the freshly checked available rooms
                $rooms = $availableRooms;
                
                Log::info('Assigning ' . $rooms->count() . ' rooms to booking');
                
                foreach ($rooms as $room) {
                    BookingRoom::create([
                        'booking_id' => $booking->booking_id,
                        'room_id' => $room->room_id,
                        'room_price' => $tempBooking['room_price']
                    ]);
                    
                    Log::info('Room ' . $room->room_number . ' assigned to booking (status remains available until payment)');
                }

                $transactionId = 'BOOK-' . strtoupper(uniqid());
                Log::info('Generated transaction ID: ' . $transactionId);

                // Create payment with pending status
                $payment = Payment::create([
                    'booking_id' => $booking->booking_id,
                    'amount' => $tempBooking['total_amount'],
                    'payment_method' => 'online',
                    'payment_status' => 'pending',
                    'transaction_id' => $transactionId,
                    'payment_date' => now(),
                    
                ]);

                Log::info('Payment created with ID: ' . $payment->payment_id . ', Status: pending (online payment)');

                DB::commit();
                Log::info('Database transaction committed successfully');

                session()->forget('temp_booking');
                Log::info('Temp booking cleared from session');

                // Process online payment with Stripe
                Log::info('Creating Stripe session for online payment');
                return $this->processOnlinePayment($reservation, $payment);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Booking creation error in transaction:', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create booking: ' . $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Confirm booking outer error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error processing booking: ' . $e->getMessage()
            ], 500);
        }
    }

    public function processOnlinePayment($reservation, $payment)
    {
        try {
            Log::info('Processing online payment for reservation: ' . $reservation->reservation_id);
            
            // Use StripePaymentService
            $stripeResult = $this->stripeService->createPaymentSession($reservation);
            
            if (!$stripeResult['success']) {
                throw new \Exception($stripeResult['error']);
            }

            // Update payment with Stripe session info
            $payment->update([
                'transaction_id' => $stripeResult['session_id'],
                'stripe_payment_url' => $stripeResult['payment_url'],
                'payment_status' => 'pending'
            ]);

            Log::info('Stripe session created: ' . $stripeResult['session_id']);

            return response()->json([
                'success' => true,
                'message' => 'Online payment session created.',
                'payment_url' => $stripeResult['payment_url'],
                'session_id' => $stripeResult['session_id'],
                'payment_id' => $payment->payment_id,
                'reservation_id' => $reservation->reservation_id,
                'redirect_url' => $stripeResult['payment_url']
            ]);

        } catch (\Exception $e) {
            Log::error('Process online payment error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error processing online payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bookingSuccess($reservationId)
    {
        try {
            Log::info('Loading booking success page for reservation: ' . $reservationId);
            
            $reservation = Reservation::with(['guest', 'roomType', 'payments', 'bookings.rooms.room'])
                ->findOrFail($reservationId);

            Log::info('Reservation loaded, status: ' . $reservation->status);

            $sessionId = request()->query('session_id');
            if ($sessionId) {
                Log::info('Processing Stripe callback with session ID: ' . $sessionId);
                
                try {
                    // Check payment status via Stripe service
                    $paymentStatus = $this->stripeService->checkPaymentStatus($sessionId);
                    
                    Log::info('Stripe payment status: ' . $paymentStatus);
                    
                    if ($paymentStatus === 'paid') {
                        $payment = $reservation->payments()->where('transaction_id', $sessionId)->first();
                        if ($payment && $payment->payment_status === 'pending') {
                            Log::info('Updating payment ID: ' . $payment->payment_id . ' to completed');
                            
                            DB::beginTransaction();
                            
                            $payment->update([
                                'payment_status' => 'completed',
                                'payment_date' => now(),
                                'stripe_payment_intent_id' => $sessionId
                            ]);
                            
                            $reservation->update(['status' => 'confirmed']);
                            Log::info('Reservation status updated to confirmed');
                            
                            if ($reservation->bookings()->exists()) {
                                $reservation->bookings()->update(['booking_status' => 'reserved']);
                                Log::info('Booking status updated to reserved');
                                
                                // Update room status to occupied only now that payment is complete
                                foreach ($reservation->bookings as $booking) {
                                    foreach ($booking->rooms as $bookingRoom) {
                                        $room = $bookingRoom->room;
                                        $room->update(['room_status' => 'occupied']);
                                        Log::info('Room ' . $room->room_number . ' status updated to occupied');
                                    }
                                }
                            }
                            
                            // Send payment confirmation email
                            try {
                                $reservation->refresh()->load(['guest', 'roomType', 'bookings.rooms.room', 'payments']);
                                $emailSent = $this->gmailService->sendOnlineBookingConfirmation($reservation, $reservation->guest, $payment);
                                Log::info('Payment completion email sent: ' . ($emailSent ? 'YES' : 'NO'));
                            } catch (\Exception $e) {
                                Log::error('Failed to send payment completion email: ' . $e->getMessage());
                            }
                            
                            DB::commit();
                        }
                    } else if ($paymentStatus === 'unpaid') {
                        Log::info('Payment is still unpaid, showing pending status');
                        // Payment is still pending - don't update anything
                    } else {
                        Log::warning('Payment status is: ' . $paymentStatus . ' for session: ' . $sessionId);
                    }
                } catch (\Exception $e) {
                    Log::error('Stripe session retrieval error: ' . $e->getMessage());
                }
            }

            return view('guest.booking-success', compact('reservation'));

        } catch (\Exception $e) {
            Log::error('Booking success page error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            abort(404);
        }
    }

    public function bookingCancel($reservationId)
    {
        try {
            Log::info('Loading booking cancel page for reservation: ' . $reservationId);
            
            $reservation = Reservation::with(['guest', 'roomType'])
                ->findOrFail($reservationId);

            Log::info('Reservation current status: ' . $reservation->status);

            if (in_array($reservation->status, ['pending', 'reserved'])) {
                Log::info('Cancelling reservation and releasing rooms');
                
                $reservation->update(['status' => 'cancelled']);
                
                foreach ($reservation->bookings as $booking) {
                    foreach ($booking->rooms as $bookingRoom) {
                        $room = $bookingRoom->room;
                        $room->update(['room_status' => 'available']);
                        Log::info('Room ' . $room->room_number . ' released to available');
                    }
                }
                
                Log::info('Reservation cancelled successfully');
            }

            return view('guest.booking-cancel', compact('reservation'));

        } catch (\Exception $e) {
            Log::error('Booking cancel page error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            abort(404);
        }
    }

    public function processPayment(Request $request)
    {
        Log::info('Process payment from modal called');
        return $this->confirmBooking($request);
    }

    // Check payment status AJAX endpoint
    public function checkPaymentStatus(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'reservation_id' => 'required|exists:reservations,reservation_id'
        ]);

        try {
            $paymentStatus = $this->stripeService->checkPaymentStatus($request->session_id);
            $reservation = Reservation::find($request->reservation_id);
            
            if ($paymentStatus === 'paid') {
                // Update payment and reservation status
                $payment = Payment::where('transaction_id', $request->session_id)->first();
                
                if ($payment) {
                    DB::beginTransaction();
                    
                    $payment->update([
                        'payment_status' => 'completed',
                        'payment_date' => now()
                    ]);

                    $reservation->update([
                        'status' => 'confirmed'
                    ]);

                    foreach ($reservation->bookings as $booking) {
                        $booking->update([
                            'booking_status' => 'reserved'
                        ]);
                        
                        // Update room status to occupied
                        foreach ($booking->rooms as $bookingRoom) {
                            $room = $bookingRoom->room;
                            $room->update(['room_status' => 'occupied']);
                        }
                    }

                    // Send payment confirmation email
                    try {
                        $reservation->refresh()->load(['guest', 'roomType', 'bookings.rooms.room', 'payments']);
                        $emailSent = $this->gmailService->sendOnlineBookingConfirmation($reservation, $reservation->guest, $payment);
                    } catch (\Exception $e) {
                        Log::error('Failed to send payment completion email: ' . $e->getMessage());
                    }

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'payment_status' => 'paid',
                        'message' => 'Payment completed successfully!',
                        'redirect_url' => route('guest.booking.success', ['reservation' => $reservation->reservation_id])
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'payment_status' => $paymentStatus,
                'message' => $paymentStatus === 'unpaid' ? 'Payment is still pending' : 'Payment status: ' . $paymentStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Check payment status error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status: ' . $e->getMessage()
            ], 500);
        }
    }
}
