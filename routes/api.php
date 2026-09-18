<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\AdminAnalyticsController;
use App\Http\Controllers\Api\Admin\AdminAppointmentController;
use App\Http\Controllers\Api\Admin\AdminCategoryController;
use App\Http\Controllers\Api\Admin\AdminCustomerController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminDoctorController;
use App\Http\Controllers\Api\Admin\AdminOperationsController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminPatientController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\Doctor\DoctorDashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MyAppointmentsController;
use App\Http\Controllers\Api\MyPatientProfileController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', [HealthController::class, 'index']);

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Authenticated User Profile
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::patch('/me', [AuthController::class, 'updateMe'])->middleware('auth:sanctum');
Route::get('/my-profile/health', [MyPatientProfileController::class, 'show'])->middleware('auth:sanctum');

// Customer Addresses (CRUD)
Route::apiResource('addresses', AddressController::class)->middleware('auth:sanctum');

// Checkout Foundation
Route::post('/checkout/validate', [CheckoutController::class, 'validate'])->middleware('auth:sanctum');

// Orders & Payments
Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show'])->middleware('auth:sanctum');
Route::post('/payments', [PaymentController::class, 'store'])->middleware('auth:sanctum');
Route::post('/webhooks/midtrans', [PaymentController::class, 'webhook']);

// Shipping & Courier Rates (Biteship)
Route::prefix('shipping')->group(function () {
    Route::post('/rates', [ShippingController::class, 'rates'])->middleware(['auth:sanctum', 'throttle:shipping']);
    Route::get('/destinations', [ShippingController::class, 'destinations'])->middleware('throttle:shipping');
    Route::post('/webhook/biteship', [ShippingWebhookController::class, 'handleBiteship'])->middleware('throttle:webhook');
});

// Categories & Products (Public Catalog)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products', [CategoryController::class, 'products']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

// -------------------------------------------------------------
// CLINICAL BOOKING SYSTEM (PUBLIC / PATIENT)
// -------------------------------------------------------------
Route::prefix('booking')->group(function () {
    Route::get('/services', [BookingController::class, 'getServices']);
    Route::get('/doctors', [BookingController::class, 'getDoctors']);
    Route::get('/available-slots', [BookingController::class, 'getAvailableSlots'])->middleware('throttle:60,1');
    Route::post('/', [BookingController::class, 'store'])->middleware(['auth:sanctum', 'throttle:booking']);
    Route::get('/lookup', [BookingController::class, 'lookup'])->middleware('throttle:booking');
});

// Customer Personal Appointments (Protected)
Route::prefix('my-appointments')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [MyAppointmentsController::class, 'index']);
    Route::get('/{id}', [MyAppointmentsController::class, 'show']);
    Route::patch('/{id}/cancel', [MyAppointmentsController::class, 'cancel']);
});

// -------------------------------------------------------------
// DOCTOR PORTAL (ROLE: DOCTOR)
// -------------------------------------------------------------
Route::prefix('doctor')->middleware(['auth:sanctum', 'doctor'])->group(function () {
    Route::get('/dashboard/overview', [DoctorDashboardController::class, 'overview']);
    Route::get('/appointments', [DoctorDashboardController::class, 'appointments']);
    Route::get('/appointments/{id}', [DoctorDashboardController::class, 'appointmentDetail']);
    Route::patch('/appointments/{id}/status', [DoctorDashboardController::class, 'updateStatus']);
    Route::post('/appointments/{id}/notes', [DoctorDashboardController::class, 'saveNotes']);
    Route::patch('/patients/{patientId}/medical-record', [DoctorDashboardController::class, 'updatePatientMedicalRecord']);
    Route::get('/patients', [DoctorDashboardController::class, 'patients']);
});

// -------------------------------------------------------------
// ADMIN BACK OFFICE (ROLE: ADMIN)
// -------------------------------------------------------------
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Executive Overview Dashboard
    Route::get('/dashboard/overview', [AdminDashboardController::class, 'overview']);

    // E-Commerce Analytics Suite
    Route::prefix('analytics')->group(function () {
        Route::get('/sales', [AdminAnalyticsController::class, 'sales']);
        Route::get('/sales/export', [AdminAnalyticsController::class, 'exportSales']);
        Route::get('/orders', [AdminAnalyticsController::class, 'orders']);
        Route::get('/products', [AdminAnalyticsController::class, 'products']);
        Route::get('/customers', [AdminAnalyticsController::class, 'customers']);
        Route::get('/payments', [AdminAnalyticsController::class, 'payments']);
        Route::get('/shipping', [AdminAnalyticsController::class, 'shipping']);
    });

    // Admin Orders Management & Operational Actions
    Route::prefix('orders')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index']);
        Route::get('/{id}', [AdminOrderController::class, 'show']);
        Route::post('/{id}/process', [AdminOrderController::class, 'process']);
        Route::post('/{id}/ship', [AdminOrderController::class, 'ship']);
        Route::post('/{id}/deliver', [AdminOrderController::class, 'deliver']);
        Route::post('/{id}/complete', [AdminOrderController::class, 'complete']);
        Route::post('/{id}/cancel', [AdminOrderController::class, 'cancel']);
        Route::post('/{id}/refund', [AdminOperationsController::class, 'refund']);
    });

    // Admin Products Management
    Route::prefix('products')->group(function () {
        Route::get('/', [AdminProductController::class, 'index']);
        Route::post('/', [AdminProductController::class, 'store']);
        Route::get('/{id}', [AdminProductController::class, 'show']);
        Route::post('/{id}', [AdminProductController::class, 'update']);
        Route::put('/{id}', [AdminProductController::class, 'update']);
        Route::patch('/{id}/toggle', [AdminProductController::class, 'toggle']);
        Route::post('/{id}/stock', [AdminProductController::class, 'adjustStock']);
    });

    // Admin Categories Management
    Route::prefix('categories')->group(function () {
        Route::get('/', [AdminCategoryController::class, 'index']);
        Route::post('/', [AdminCategoryController::class, 'store']);
        Route::get('/{id}', [AdminCategoryController::class, 'show']);
        Route::put('/{id}', [AdminCategoryController::class, 'update']);
        Route::patch('/{id}/toggle', [AdminCategoryController::class, 'toggle']);
        Route::delete('/{id}', [AdminCategoryController::class, 'destroy']);
    });

    // Admin Customers Management
    Route::prefix('customers')->group(function () {
        Route::get('/', [AdminCustomerController::class, 'index']);
        Route::get('/{id}', [AdminCustomerController::class, 'show']);
        Route::patch('/{id}/toggle', [AdminCustomerController::class, 'toggle']);
        Route::delete('/{id}', [AdminCustomerController::class, 'destroy']);
    });

    // Admin Clinical Appointments Management
    Route::prefix('appointments')->group(function () {
        Route::get('/', [AdminAppointmentController::class, 'index']);
        Route::get('/{id}', [AdminAppointmentController::class, 'show']);
        Route::patch('/{id}/status', [AdminAppointmentController::class, 'updateStatus']);
        Route::patch('/{id}', [AdminAppointmentController::class, 'update']);
        Route::delete('/{id}', [AdminAppointmentController::class, 'destroy']);
    });

    // Admin Patients Management
    Route::prefix('patients')->group(function () {
        Route::get('/', [AdminPatientController::class, 'index']);
        Route::get('/{id}', [AdminPatientController::class, 'show']);
        Route::patch('/{id}', [AdminPatientController::class, 'update']);
    });

    // Admin Doctors Management
    Route::prefix('doctors')->group(function () {
        Route::get('/', [AdminDoctorController::class, 'index']);
        Route::post('/', [AdminDoctorController::class, 'store']);
        Route::patch('/{id}', [AdminDoctorController::class, 'update']);
        Route::patch('/{id}/toggle', [AdminDoctorController::class, 'toggle']);
    });

    // Advanced Operations & Background Sync
    Route::prefix('operations')->group(function () {
        Route::get('/alerts', [AdminOperationsController::class, 'alerts']);
        Route::post('/expire-pending', [AdminOperationsController::class, 'expirePending']);
        Route::post('/sync-shipments', [AdminOperationsController::class, 'syncShipments']);
    });
});
