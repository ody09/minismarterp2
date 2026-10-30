<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'erp:create-admin {email} {--name=Administrator}';

    protected $description = 'Create an administrator with a securely prompted password (no default credentials)';

    public function handle(): int
    {
        try {
            $password = $this->secret('Password', false);
            $confirmation = $this->secret('Confirm password', false);
        } catch (\RuntimeException) {
            $this->error('Hidden password input is unavailable. Use an interactive terminal that supports hidden input.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->option('name'), 'email' => mb_strtolower(trim($this->argument('email'))),
            'password' => $password, 'password_confirmation' => $confirmation,
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => AccessControlSeeder::class, '--force' => true]);
        $user = new User;
        $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            'role_id' => Role::where('code', 'admin')->firstOrFail()->id, 'is_active' => true])->save();
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
