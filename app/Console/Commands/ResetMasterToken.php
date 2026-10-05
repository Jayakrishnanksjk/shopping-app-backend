<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\MasterToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ResetMasterToken extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'master-token:reset
                            {--user= : Email or ID of the existing user to attach the token to}
                            {--name=pearl-xp-system@example.com : Email of the system user to create/reuse}';

    /**
     * The console command description.
     */
    protected $description = 'Revoke the current master API token and issue a new never-expiring one (printed once)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $user = $this->resolveUser();

            if (! $user) {
                $this->error('Could not resolve a user for the master token.');

                return self::FAILURE;
            }

            // The master token must behave like a full admin everywhere.
            $adminRole = Role::firstOrCreate(
                ['name' => RoleEnum::ADMIN],
                ['system_reserve' => true]
            );

            if (! $user->hasRole(RoleEnum::ADMIN)) {
                $user->assignRole($adminRole);
                $this->info("Assigned admin role to: {$user->email}");
            }

            $plainTextToken = MasterToken::mint($user);

            Log::info('Master token reset via CLI', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'ip' => 'cli',
            ]);

            $this->newLine();
            $this->info('Master token generated successfully.');
            $this->line("  User : {$user->email} (ID: {$user->id})");
            $this->line('  Role : ' . RoleEnum::ADMIN);
            $this->line('  Expiry: never (until the next reset)');
            $this->newLine();
            $this->comment('Copy this token now — it is stored only as a hash and cannot be shown again:');
            $this->newLine();
            $this->line('  ' . $plainTextToken);
            $this->newLine();
            $this->comment('Any previously issued master token has been revoked.');
            $this->newLine();

            return self::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('master-token:reset failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('Failed to reset master token: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve the user the master token should authenticate as.
     */
    private function resolveUser(): ?User
    {
        if ($emailOrId = $this->option('user')) {
            $user = is_numeric($emailOrId)
                ? User::find($emailOrId)
                : User::where('email', $emailOrId)->first();

            if (! $user) {
                $this->error("No user found matching: {$emailOrId}");

                return null;
            }

            $this->info("Using existing user: {$user->email}");

            return $user;
        }

        $email = (string) $this->option('name');

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->info("Reusing system user: {$user->email}");

            return $user;
        }

        $user = User::create([
            'name'            => 'Pearl XP System',
            'email'           => $email,
            'password'        => Hash::make(Str::random(40)),
            'status'          => 1,
            'system_reserve'  => 1,
            'country_code'    => (string) '1',
        ]);

        $this->info("Created system user: {$user->email}");

        return $user;
    }
}
