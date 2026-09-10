<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BookingSystemSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Services
        $servicesData = [
            [
                'code' => 'SRV001',
                'name' => 'Deep Pore Cleansing & Extraction',
                'description' => 'Pembersihan pori-pori mendalam dengan ekstraksi komedo lembut, high frequency, dan soothing mask.',
                'duration_minutes' => 60,
                'price' => 150000,
                'category' => 'Facial & Pores',
                'is_active' => true,
            ],
            [
                'code' => 'SRV002',
                'name' => 'Acne Care & Calming Treatment',
                'description' => 'Terapi anti-inflamasi khusus jerawat aktif, red light therapy, dan serum penenang kemerahan.',
                'duration_minutes' => 60,
                'price' => 200000,
                'category' => 'Acne & Sensitivity',
                'is_active' => true,
            ],
            [
                'code' => 'SRV003',
                'name' => 'Luminous Brightening & Glow',
                'description' => 'Facial pencerah intensif dengan infus Vitamin C konsentrat, galvanic microcurrent, dan brightening peel.',
                'duration_minutes' => 75,
                'price' => 250000,
                'category' => 'Brightening & Anti-Aging',
                'is_active' => true,
            ],
            [
                'code' => 'SRV004',
                'name' => 'Signature Hair & Scalp Ritual',
                'description' => 'Perawatan kulit kepala revitalisasi, hair spa nourishing mask, potong rambut, dan blow dry styling.',
                'duration_minutes' => 45,
                'price' => 100000,
                'category' => 'Hair & Scalp',
                'is_active' => true,
            ],
        ];

        foreach ($servicesData as $srv) {
            Service::firstOrCreate(['code' => $srv['code']], $srv);
        }

        // 2. Seed Doctor Users & Doctors
        $doctorsData = [
            [
                'email' => 'doctor.yoshi@lumiere.com',
                'name' => 'dr. Yoshi Sp.D.V.E',
                'title' => 'Dermatologist & Aesthetic Specialist',
                'specialization' => 'Dermatologi & Estetika Medis',
                'experience' => '8+ Tahun Pengalaman',
                'license_number' => 'SIP.440/1092/Dinkes/2022',
                'phone' => '+6281299887766',
                'rating' => 4.9,
                'review_count' => 340,
                'avatar_color' => 'from-rose-500 to-pink-500',
                'bio' => 'Fokus pada penanganan jerawat kronis, skin barrier repair, serta peremajaan kulit klinis non-invasif.',
                'schedule_days' => 'Senin, Rabu, Jumat, Sabtu',
                'available_days' => [1, 3, 5, 6],
                'work_start_time' => '10:00:00',
                'work_end_time' => '20:00:00',
                'skills' => ['Acne Management', 'Skin Barrier Restoration', 'Laser & Peeling', 'Anti-Aging'],
            ],
            [
                'email' => 'doctor.sinta@lumiere.com',
                'name' => 'Sinta Putri Dipl.CIBTAC',
                'title' => 'Senior Aesthetician & Skin Therapist',
                'specialization' => 'Skin Therapist & Facialist',
                'experience' => '6+ Tahun Pengalaman',
                'license_number' => 'STR.992/AES/2021',
                'phone' => '+6281299887755',
                'rating' => 4.8,
                'review_count' => 280,
                'avatar_color' => 'from-pink-500 to-purple-500',
                'bio' => 'Ahli dalam teknik pijat limfatik wajah, deep cleansing alami, dan ritual relaksasi peremajaan kulit.',
                'schedule_days' => 'Selasa, Kamis, Sabtu, Minggu',
                'available_days' => [2, 4, 6, 0],
                'work_start_time' => '10:00:00',
                'work_end_time' => '20:00:00',
                'skills' => ['Deep Cleansing Facial', 'Lymphatic Drainage Massage', 'Scalp Care', 'Glow Infusion'],
            ],
            [
                'email' => 'doctor.alana@lumiere.com',
                'name' => 'dr. Alana Widjaja M.Biomed (AAM)',
                'title' => 'Anti-Aging & Laser Specialist',
                'specialization' => 'Anti-Aging & Glow Therapy',
                'experience' => '7+ Tahun Pengalaman',
                'license_number' => 'SIP.440/3382/Dinkes/2023',
                'phone' => '+6281299887744',
                'rating' => 5.0,
                'review_count' => 195,
                'avatar_color' => 'from-amber-500 to-rose-500',
                'bio' => 'Spesialis terapi peremajaan seluler, brightening infusion, dan penanganan hiperpigmentasi / flek hitam.',
                'schedule_days' => 'Senin, Selasa, Kamis, Jumat',
                'available_days' => [1, 2, 4, 5],
                'work_start_time' => '10:00:00',
                'work_end_time' => '20:00:00',
                'skills' => ['Melasma & Pigmentation', 'Collagen Stimulation', 'Microcurrent Facial', 'Luminous Glow'],
            ],
        ];

        foreach ($doctorsData as $docData) {
            $user = User::firstOrCreate(
                ['email' => $docData['email']],
                [
                    'name' => $docData['name'],
                    'password' => Hash::make('password'),
                    'role' => 'doctor',
                    'phone' => $docData['phone'],
                    'is_active' => true,
                ]
            );

            Doctor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $docData['name'],
                    'title' => $docData['title'],
                    'specialization' => $docData['specialization'],
                    'license_number' => $docData['license_number'],
                    'phone' => $docData['phone'],
                    'experience' => $docData['experience'],
                    'rating' => $docData['rating'],
                    'review_count' => $docData['review_count'],
                    'avatar_color' => $docData['avatar_color'],
                    'bio' => $docData['bio'],
                    'schedule_days' => $docData['schedule_days'],
                    'available_days' => $docData['available_days'],
                    'work_start_time' => $docData['work_start_time'],
                    'work_end_time' => $docData['work_end_time'],
                    'skills' => $docData['skills'],
                    'status' => 'active',
                ]
            );
        }

        // 3. Demo Patients & Appointments
        $customerUser = User::where('email', 'customer@lumiere.com')->first();
        $patient1 = Patient::firstOrCreate(
            ['phone' => '+6281234567890'],
            [
                'user_id' => $customerUser ? $customerUser->id : null,
                'name' => 'Aurelia Vance',
                'email' => 'customer@lumiere.com',
                'date_of_birth' => '1996-05-14',
                'gender' => 'female',
                'address' => 'Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan',
                'allergies' => 'Alergi alkohol berkonsentrasi tinggi & fragrance artifisial',
                'medical_history' => 'Riwayat dermatitis atopik ringan saat cuaca kering',
                'emergency_contact' => '+6281122334455 (Ibu Vance)',
                'status' => 'active',
            ]
        );

        $patient2 = Patient::firstOrCreate(
            ['phone' => '081298765432'],
            [
                'name' => 'Jessica Alexander',
                'email' => 'jessica.alexander@example.com',
                'date_of_birth' => '1998-11-20',
                'gender' => 'female',
                'address' => 'Apartemen Senopati Suites Tower 2, Jakarta Selatan',
                'allergies' => 'Tidak ada alergi yang diketahui',
                'medical_history' => 'Jerawat papul hormonal di area rahang',
                'emergency_contact' => '081299998888',
                'status' => 'active',
            ]
        );

        $doctorYoshi = Doctor::where('name', 'like', '%Yoshi%')->first();
        $serviceFacial = Service::where('code', 'SRV001')->first();
        $serviceAcne = Service::where('code', 'SRV002')->first();

        if ($doctorYoshi && $serviceFacial) {
            $today = Carbon::today()->format('Y-m-d');

            // Appointment 1: Today Confirmed
            $appt1 = Appointment::firstOrCreate(
                ['booking_code' => 'LMR-BKG-'.Carbon::today()->format('Ymd').'-A101'],
                [
                    'patient_id' => $patient1->id,
                    'doctor_id' => $doctorYoshi->id,
                    'service_id' => $serviceFacial->id,
                    'appointment_date' => $today,
                    'start_time' => '10:00:00',
                    'end_time' => '11:00:00',
                    'consultation_mode' => 'offline',
                    'complaint' => 'Kulit kusam dan komedo menumpuk di area hidung & dagu',
                    'patient_notes' => 'Mohon ekstraksi lembut karena kulit sensitif',
                    'status' => 'confirmed',
                ]
            );
            $appt1->logStatusChange('confirmed', null, 'Initial seed confirmation');

            // Appointment 2: Past Completed with Medical Record
            $pastDate = Carbon::today()->subDays(7)->format('Y-m-d');
            $appt2 = Appointment::firstOrCreate(
                ['booking_code' => 'LMR-BKG-'.Carbon::today()->subDays(7)->format('Ymd').'-B202'],
                [
                    'patient_id' => $patient1->id,
                    'doctor_id' => $doctorYoshi->id,
                    'service_id' => $serviceAcne->id,
                    'appointment_date' => $pastDate,
                    'start_time' => '14:00:00',
                    'end_time' => '15:00:00',
                    'consultation_mode' => 'offline',
                    'complaint' => 'Breakout jerawat aktif setelah perjalanan dinas luar kota',
                    'doctor_notes' => 'Pasien mengalami inflamasi acne vulgaris derajat ringan-sedang.',
                    'diagnosis' => 'Acne Vulgaris papulopustular ringan + Dehidrasi stratum corneum',
                    'treatment_plan' => 'Lumiere Red Light Therapy + Serum Centella & Niacinamide 5%',
                    'prescription' => 'Krim malam Clindamycin gel 1%, Cleanser Gentle Low pH',
                    'status' => 'completed',
                ]
            );
            $appt2->logStatusChange('completed', null, 'Initial seed completed');
        }
    }
}
