<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:create-admin
        {--name= : Full name of the administrator}
        {--email= : Login e-mail address}
        {--phone= : Optional phone number}';

    /**
     * @var string
     */
    protected $description = 'Create an administrator account with a password you choose (the seeders must not be used for this in production)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nama lengkap admin');
        $email = $this->option('email') ?: $this->ask('Email login admin');
        $phone = $this->option('phone');
        $password = $this->secret('Password (minimal 12 karakter, kombinasi huruf besar, kecil, angka)');
        $confirmation = $this->secret('Ulangi password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:30'],
                'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return Command::FAILURE;
        }

        $admin = User::create([
            'name' => trim((string) $name),
            'email' => strtolower(trim((string) $email)),
            'phone' => $phone ?: null,
            'role' => 'admin',
            'is_active' => true,
            'password' => $password,
        ]);

        $this->info("Admin {$admin->email} berhasil dibuat.");

        return Command::SUCCESS;
    }
}
