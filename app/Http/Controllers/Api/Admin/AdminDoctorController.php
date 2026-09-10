<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminDoctorController extends Controller
{
    /**
     * List all doctors with appointment counts & user profiles.
     */
    public function index(): JsonResponse
    {
        $doctors = Doctor::with('user')
            ->withCount('appointments')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $doctors,
        ]);
    }

    /**
     * Create new doctor & associated user account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'title' => 'nullable|string|max:255',
            'specialization' => 'required|string|max:255',
            'license_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'experience' => 'nullable|string|max:100',
            'bio' => 'nullable|string',
            'schedule_days' => 'nullable|string',
            'available_days' => 'nullable|array',
            'work_start_time' => 'nullable|date_format:H:i',
            'work_end_time' => 'nullable|date_format:H:i',
            'avatar_color' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'doctor',
            'phone' => $validated['phone'] ?? null,
            'is_active' => true,
        ]);

        $doctor = Doctor::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'title' => $validated['title'] ?? 'Dermatologist & Specialist',
            'specialization' => $validated['specialization'],
            'license_number' => $validated['license_number'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'experience' => $validated['experience'] ?? '5+ Tahun',
            'bio' => $validated['bio'] ?? null,
            'schedule_days' => $validated['schedule_days'] ?? 'Senin – Jumat',
            'available_days' => $validated['available_days'] ?? [1, 2, 3, 4, 5],
            'work_start_time' => $validated['work_start_time'] ?? '09:00:00',
            'work_end_time' => $validated['work_end_time'] ?? '17:00:00',
            'avatar_color' => $validated['avatar_color'] ?? 'from-rose-500 to-pink-500',
            'skills' => $validated['skills'] ?? ['Clinical Dermatology'],
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dokter berhasil ditambahkan!',
            'data' => $doctor->load('user'),
        ], 201);
    }

    /**
     * Update doctor profile and schedules.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $doctor = Doctor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'license_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'experience' => 'nullable|string|max:100',
            'bio' => 'nullable|string',
            'schedule_days' => 'nullable|string',
            'available_days' => 'nullable|array',
            'work_start_time' => 'nullable|date_format:H:i',
            'work_end_time' => 'nullable|date_format:H:i',
            'status' => 'nullable|string|in:active,inactive',
            'avatar_color' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        $doctor->update(array_filter($validated, fn ($val) => ! is_null($val)));

        if ($doctor->user && ! empty($validated['name'])) {
            $doctor->user->name = $validated['name'];
            $doctor->user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Data dokter berhasil diperbarui.',
            'data' => $doctor->load('user'),
        ]);
    }

    /**
     * Toggle active status.
     */
    public function toggle(int $id): JsonResponse
    {
        $doctor = Doctor::findOrFail($id);
        $doctor->status = ($doctor->status === 'active') ? 'inactive' : 'active';
        $doctor->save();

        return response()->json([
            'success' => true,
            'message' => 'Status dokter berhasil diubah menjadi '.$doctor->status,
            'data' => $doctor,
        ]);
    }
}
