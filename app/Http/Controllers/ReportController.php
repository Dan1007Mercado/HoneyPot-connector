<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Guest;
use App\Models\Sale;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $thirtyDaysAgo = Carbon::today()->subDays(30);
        
        // Today's revenue
        $todayRevenue = Payment::whereDate('payment_date', $today)
            ->where('payment_status', 'completed')
            ->sum('amount');

        // Monthly revenue
        $monthlyRevenue = Payment::whereBetween('payment_date', [$thirtyDaysAgo, $today])
            ->where('payment_status', 'completed')
            ->sum('amount');

        // Occupancy data
        $totalRooms = Room::count();
        $occupiedRooms = Booking::whereHas('reservation', function($query) use ($today) {
            $query->where('check_in_date', '<=', $today)
                ->where('check_out_date', '>=', $today)
                ->where('status', 'confirmed');
        })->count();

        $currentOccupancy = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

        // Quick insights
        $bestPerformance = Payment::where('payment_status', 'completed')
            ->max('amount');

        $avgDailyRevenue = Payment::whereBetween('payment_date', [$thirtyDaysAgo, $today])
            ->where('payment_status', 'completed')
            ->avg('amount') ?? 0;

        // Recent reports with pagination
        try {
            $recentReports = Report::with('generatedByUser')
                ->latest()
                ->paginate(10);
        } catch (\Exception $e) {
            $recentReports = collect([]);
        }

        return view('reports', [
            'todayRevenue' => $todayRevenue,
            'todayRevenueGrowth' => 5,
            'currentOccupancy' => $currentOccupancy,
            'occupancyGrowth' => 7,
            'monthlyRevenue' => $monthlyRevenue,
            'revenueGrowth' => 3,
            'recreationalBookings' => 15,
            'recreationalGrowth' => 2,
            'bestPerformance' => $bestPerformance,
            'avgDailyRevenue' => $avgDailyRevenue,
            'peakSeasonOccupancy' => 85,
            'seasonalGrowth' => 18.5,
            'recentReports' => $recentReports,
            'defaultStartDate' => $thirtyDaysAgo->format('Y-m-d'),
            'defaultEndDate' => $today->format('Y-m-d'),
        ]);
    }

    public function generate(Request $request)
    {
        // Validate the request
        $request->validate([
            'report_type' => 'required|string|in:revenue,occupancy,guest',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'document_name' => 'required|string|max:255'
        ]);

        try {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $reportType = $request->report_type;
            $documentName = $request->document_name;
            
            // Generate report data based on type
            $reportData = $this->generateReportData($reportType, $startDate, $endDate);
            
            // Generate PDF
            $pdf = Pdf::loadView('reports.pdf', [
                'reportType' => $reportType,
                'reportName' => $this->getReportName($reportType),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'reportData' => $reportData,
                'logoPath' => $this->getHotelLogoPath()
            ])->setOption('defaultFont', 'DejaVu Sans');
            
            $pdf->setPaper('A4', 'portrait');
            $pdfContent = $pdf->output();
            
            // Upload to S3
            $cleanName = Str::slug($documentName);
            $dateRange = $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d');
            $uniqueId = Str::random(8);
            $filename = "{$cleanName}_{$dateRange}_{$uniqueId}.pdf";
            
            // S3 path: reports/[type]/year/month/day/filename.pdf
            $s3Path = 'reports/' . $reportType . '/' . date('Y/m/d/') . $filename;
            
            // Upload to S3
            Storage::disk('s3')->put($s3Path, $pdfContent, [
                'ContentType' => 'application/pdf',
                'ContentDisposition' => 'inline; filename="' . $filename . '"',
            ]);
            
            // Save report record to database (NO file_content)
            $report = Report::create([
                'name' => $documentName,
                'type' => $reportType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'file_path' => $s3Path,
                'file_size' => strlen($pdfContent),
                'mime_type' => 'application/pdf',
                'generated_by' => auth()->id(),
            ]);
            
            return back()->with([
                'success' => 'Report generated and uploaded to S3 successfully!',
                'download_url' => route('reports.download', $report->id),
                'view_url' => route('reports.view', $report->id),
                'report_id' => $report->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Report generation failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'report_type' => $request->input('report_type'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
            ]);
            return back()->with('error', 'Failed to generate report: ' . $e->getMessage());
        }
    }

    public function download($id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Check if file exists in S3
            if (!$report->file_path || !Storage::disk('s3')->exists($report->file_path)) {
                throw new \Exception('Report file not found in S3.');
            }
            
            // Generate temporary URL (valid for 10 minutes)
            $temporaryUrl = Storage::disk('s3')->temporaryUrl($report->file_path, now()->addMinutes(10));
            
            // Redirect to S3 URL
            return redirect()->away($temporaryUrl);
            
        } catch (\Exception $e) {
            Log::error('Report download failed: ' . $e->getMessage());
            return back()->with('error', 'Report download failed: ' . $e->getMessage());
        }
    }

    public function view($id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Check if file exists in S3
            if (!$report->file_path || !Storage::disk('s3')->exists($report->file_path)) {
                throw new \Exception('Report file not found in S3.');
            }
            
            // Generate temporary URL (valid for 30 minutes for viewing)
            $temporaryUrl = Storage::disk('s3')->temporaryUrl($report->file_path, now()->addMinutes(30));
            
            // Redirect to S3 URL
            return redirect()->away($temporaryUrl);
            
        } catch (\Exception $e) {
            Log::error('Report view failed: ' . $e->getMessage());
            return back()->with('error', 'Report view failed: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Delete from S3
            if ($report->file_path && Storage::disk('s3')->exists($report->file_path)) {
                Storage::disk('s3')->delete($report->file_path);
            }
            
            // Delete from database
            $report->delete();
            
            return redirect()->route('reports.index')->with('success', 'Report deleted successfully');
            
        } catch (\Exception $e) {
            Log::error('Report deletion failed: ' . $e->getMessage());
            return back()->with('error', 'Failed to delete report: ' . $e->getMessage());
        }
    }

    public function quickReport(Request $request, $type)
    {
        try {
            $startDate = Carbon::now()->subDays(30);
            $endDate = Carbon::now();
            $documentName = $this->getReportName($type) . ' - ' . now()->format('Y-m-d');
            
            $reportData = $this->generateReportData($type, $startDate, $endDate);
            
            $pdf = Pdf::loadView('reports.pdf', [
                'reportType' => $type,
                'reportName' => $this->getReportName($type),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'reportData' => $reportData,
                'logoPath' => $this->getHotelLogoPath()
            ]);
            
            $pdfContent = $pdf->output();
            
            // Upload to S3
            $cleanName = Str::slug($documentName);
            $dateRange = $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d');
            $uniqueId = Str::random(8);
            $filename = "{$cleanName}_{$dateRange}_{$uniqueId}.pdf";
            $s3Path = 'reports/' . $type . '/' . date('Y/m/d/') . $filename;
            
            Storage::disk('s3')->put($s3Path, $pdfContent);
            
            $report = Report::create([
                'name' => $documentName,
                'type' => $type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'file_path' => $s3Path,
                'file_size' => strlen($pdfContent),
                'mime_type' => 'application/pdf',
                'generated_by' => auth()->id(),
            ]);
            
            return response()->json([
                'success' => true,
                'report_id' => $report->id,
                'download_url' => route('reports.download', $report->id),
                'view_url' => route('reports.view', $report->id)
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Preview report (for AJAX)
    public function previewReport(Request $request)
    {
        try {
            $request->validate([
                'report_type' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);
            
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $reportType = $request->report_type;
            
            $reportData = $this->generateReportData($reportType, $startDate, $endDate);
            
            return response()->json([
                'success' => true,
                'preview' => view('reports.preview', [
                    'reportType' => $reportType,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'reportData' => $reportData
                ])->render()
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Async report generation
    public function generateAsync(Request $request)
    {
        return $this->generate($request);
    }

    // Check generation status
    public function checkGenerationStatus($jobId)
    {
        return response()->json([
            'status' => 'completed',
            'report_id' => $jobId
        ]);
    }

    // List reports API
    public function listReports(Request $request)
    {
        $reports = Report::with('generatedByUser')
            ->when($request->type, function($q, $type) {
                return $q->where('type', $type);
            })
            ->when($request->from, function($q, $from) {
                return $q->whereDate('created_at', '>=', Carbon::parse($from));
            })
            ->when($request->to, function($q, $to) {
                return $q->whereDate('created_at', '<=', Carbon::parse($to));
            })
            ->latest()
            ->paginate($request->per_page ?? 20);
            
        return response()->json($reports);
    }

    // Public view with token
    public function publicView($id, $token)
    {
        try {
            $report = Report::findOrFail($id);
            
            // You would need to add a share_token column and logic
            // For now, redirect to regular view
            return $this->view($id);
            
        } catch (\Exception $e) {
            return view('errors.404');
        }
    }

    // Public download with token
    public function publicDownload($id, $token)
    {
        try {
            $report = Report::findOrFail($id);
            
            // You would need to add a share_token column and logic
            // For now, redirect to regular download
            return $this->download($id);
            
        } catch (\Exception $e) {
            return view('errors.404');
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    private function generateReportData($reportType, $startDate, $endDate)
    {
        switch ($reportType) {
            case 'revenue':
                return $this->generateRevenueReport($startDate, $endDate);
            case 'occupancy':
                return $this->generateOccupancyReport($startDate, $endDate);
            case 'guest':
                return $this->generateGuestReport($startDate, $endDate);
            default:
                return [];
        }
    }

    private function generateRevenueReport($startDate, $endDate)
    {
        $totalRevenue = Payment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('payment_status', 'completed')
            ->sum('amount');

        $roomRevenue = Sale::whereBetween('sale_date', [$startDate, $endDate])
            ->sum('room_revenue') ?? 0;

        $dailyRevenue = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dayRevenue = Payment::whereDate('payment_date', $currentDate)
                ->where('payment_status', 'completed')
                ->sum('amount');
                
            $dayBookings = Booking::whereDate('booking_date', $currentDate)
                ->count();
                
            $dailyRevenue[] = [
                'date' => $currentDate->copy(),
                'room_revenue' => $dayRevenue * 0.8,
                'other_revenue' => $dayRevenue * 0.2,
                'total_revenue' => $dayRevenue,
                'bookings' => $dayBookings
            ];
            
            $currentDate->addDay();
        }

        $roomTypes = RoomType::all();
        $revenueByRoomType = [];
        
        foreach ($roomTypes as $roomType) {
            $bookingsCount = Booking::whereHas('reservation', function($query) use ($roomType, $startDate, $endDate) {
                $query->where('room_type_id', $roomType->id)
                      ->whereBetween('check_in_date', [$startDate, $endDate]);
            })->count();
            
            $estimatedRevenue = $bookingsCount * $roomType->base_price;
            
            $revenueByRoomType[] = [
                'room_type_name' => $roomType->type_name,
                'bookings' => $bookingsCount,
                'nights_sold' => $bookingsCount * rand(1, 3),
                'revenue' => $estimatedRevenue,
                'average_rate' => $bookingsCount > 0 ? $estimatedRevenue / $bookingsCount : $roomType->base_price
            ];
        }

        $daysDiff = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($daysDiff + 1);
        $previousEndDate = $startDate->copy()->subDay();
        
        $previousRevenue = Payment::whereBetween('payment_date', [$previousStartDate, $previousEndDate])
            ->where('payment_status', 'completed')
            ->sum('amount');
            
        $revenueGrowth = $previousRevenue > 0 
            ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
            : ($totalRevenue > 0 ? 100 : 0);

        return [
            'totalRevenue' => $totalRevenue,
            'roomRevenue' => $roomRevenue,
            'otherRevenue' => $totalRevenue - $roomRevenue,
            'dailyRevenue' => $dailyRevenue,
            'revenueByRoomType' => $revenueByRoomType,
            'revenueGrowth' => $revenueGrowth,
            'totalBookings' => Booking::whereBetween('booking_date', [$startDate, $endDate])->count(),
            'completedBookings' => Booking::whereBetween('booking_date', [$startDate, $endDate])
                ->where('booking_status', 'checked-out')
                ->count(),
            'avgDailyRate' => $totalRevenue / max(1, Booking::whereBetween('booking_date', [$startDate, $endDate])->count())
        ];
    }

    private function generateOccupancyReport($startDate, $endDate)
    {
        $totalRooms = Room::count();
        $dailyOccupancy = [];
        $currentDate = $startDate->copy();
        
        $totalOccupiedNights = 0;
        $totalAvailableNights = 0;
        
        while ($currentDate <= $endDate) {
            $occupiedRooms = Booking::whereHas('reservation', function($query) use ($currentDate) {
                $query->where('check_in_date', '<=', $currentDate)
                      ->where('check_out_date', '>', $currentDate)
                      ->where('status', 'confirmed');
            })->count();
            
            $availableRooms = $totalRooms;
            $occupancyRate = $availableRooms > 0 ? ($occupiedRooms / $availableRooms) * 100 : 0;
            
            $dayRevenue = Payment::whereDate('payment_date', $currentDate)
                ->where('payment_status', 'completed')
                ->sum('amount');
                
            $adr = $occupiedRooms > 0 ? $dayRevenue / $occupiedRooms : 0;
            $revpar = $dayRevenue / max(1, $availableRooms);
            
            $dailyOccupancy[] = [
                'date' => $currentDate->copy(),
                'available_rooms' => $availableRooms,
                'occupied_rooms' => $occupiedRooms,
                'occupancy_rate' => $occupancyRate,
                'adr' => $adr,
                'revpar' => $revpar
            ];
            
            $totalOccupiedNights += $occupiedRooms;
            $totalAvailableNights += $availableRooms;
            
            $currentDate->addDay();
        }

        $avgOccupancy = $totalAvailableNights > 0 ? ($totalOccupiedNights / $totalAvailableNights) * 100 : 0;

        if (!empty($dailyOccupancy)) {
            $peakDay = collect($dailyOccupancy)->sortByDesc('occupancy_rate')->first();
            $lowDay = collect($dailyOccupancy)->sortBy('occupancy_rate')->first();
        } else {
            $peakDay = $lowDay = null;
        }

        return [
            'totalRooms' => $totalRooms,
            'avgOccupiedRooms' => $totalOccupiedNights / max(1, $endDate->diffInDays($startDate) + 1),
            'avgOccupancy' => round($avgOccupancy, 1),
            'dailyOccupancy' => $dailyOccupancy,
            'peakDate' => $peakDay['date']->format('M d, Y') ?? 'N/A',
            'peakOccupancy' => round($peakDay['occupancy_rate'] ?? 0, 1),
            'lowDate' => $lowDay['date']->format('M d, Y') ?? 'N/A',
            'lowOccupancy' => round($lowDay['occupancy_rate'] ?? 0, 1),
            'occupancyGrowth' => 0,
            'avgDailyRate' => collect($dailyOccupancy)->avg('adr') ?? 0
        ];
    }

    private function generateGuestReport($startDate, $endDate)
    {
        $guests = Guest::whereHas('reservations', function($query) use ($startDate, $endDate) {
            $query->whereBetween('check_in_date', [$startDate, $endDate]);
        })->withCount(['reservations' => function($query) use ($startDate, $endDate) {
            $query->whereBetween('check_in_date', [$startDate, $endDate]);
        }])->with(['reservations' => function($query) use ($startDate, $endDate) {
            $query->whereBetween('check_in_date', [$startDate, $endDate]);
        }])->get();

        $totalGuests = $guests->count();
        
        $returningGuests = $guests->filter(function($guest) {
            return $guest->reservations_count > 1;
        })->count();
        
        $newGuests = $totalGuests - $returningGuests;

        $totalNights = 0;
        $totalStays = 0;
        
        foreach ($guests as $guest) {
            foreach ($guest->reservations as $reservation) {
                $totalNights += $reservation->num_nights;
                $totalStays++;
            }
        }
        
        $avgLengthOfStay = $totalStays > 0 ? $totalNights / $totalStays : 0;

        $topGuests = $guests->map(function($guest) {
            $totalRevenue = $guest->reservations->sum('total_amount');
            
            return [
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'stay_count' => $guest->reservations_count,
                'total_nights' => $guest->reservations->sum('num_nights'),
                'total_revenue' => $totalRevenue
            ];
        })->sortByDesc('total_revenue')->take(10)->values()->toArray();

        $guestTypes = [
            'walk_in' => $guests->where('guest_type', 'walk-in')->count(),
            'advance' => $guests->where('guest_type', 'advance')->count(),
        ];

        return [
            'totalGuests' => $totalGuests,
            'newGuests' => $newGuests,
            'returningGuests' => $returningGuests,
            'avgLengthOfStay' => round($avgLengthOfStay, 1),
            'topGuests' => $topGuests,
            'guestTypes' => $guestTypes,
        ];
    }

    private function getReportName($reportType)
    {
        $names = [
            'revenue' => 'Revenue Analysis Report',
            'occupancy' => 'Occupancy Performance Report',
            'guest' => 'Guest Demographics Report',
            'financial' => 'Financial Summary Report'
        ];
        
        return $names[$reportType] ?? 'Custom Report';
    }

    private function getHotelLogoPath()
    {
        $logoPath = 'images/logo.png';
        
        if (file_exists(public_path($logoPath))) {
            return $logoPath;
        }
        
        return null;
    }
}
