<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\Analytics\AdminDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * Get high-level overview metrics for the executive dashboard.
     */
    public function overview(): JsonResponse
    {
        $overview = $this->dashboardService->getOverview();

        $today = Carbon::today()->format('Y-m-d');
        $startOfMonth = Carbon::now()->startOfMonth();

        $overview['clinical'] = [
            'total_patients' => Patient::count(),
            'new_patients_this_month' => Patient::where('created_at', '>=', $startOfMonth)->count(),
            'bookings_today' => Appointment::whereDate('appointment_date', $today)->count(),
            'bookings_pending' => Appointment::where('status', AppointmentStatus::Pending->value)->count(),
            'bookings_confirmed' => Appointment::where('status', AppointmentStatus::Confirmed->value)->count(),
            'bookings_completed' => Appointment::where('status', AppointmentStatus::Completed->value)->count(),
            'today_doctor_schedules' => Doctor::where('status', 'active')
                ->withCount(['appointments' => function ($q) use ($today) {
                    $q->whereDate('appointment_date', $today)->whereNotIn('status', AppointmentStatus::releasedValues());
                }])
                ->get(),
        ];

        return response()->json([
            'data' => $overview,
        ], 200);
    }
}
