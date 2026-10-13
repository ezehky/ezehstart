<?php

namespace App\Console\Commands;

use App\Enums\PaymentVendorEnum;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * The health check, as a command rather than a public URL. An open health
 * endpoint tells anybody which payment vendors the site uses and whether their
 * keys work, which is more than a stranger needs to know.
 */
class CheckPaymentGatewaysCommand extends Command
{
    protected $signature = 'payments:health';

    protected $description = 'Make one authenticated call to every configured payment vendor and report the result';

    public function handle(): int
    {
        $results = app(PaymentService::class)->health();

        if (! $results) {
            $this->warn('No payment vendor has keys configured.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($results as $vendor => $error) {
            $label = PaymentVendorEnum::from($vendor)->label();

            if ($error === null) {
                $this->line("  <fg=green>ok</> · {$label}");

                continue;
            }

            $failed = true;
            $this->line("  <fg=red>failed</> · {$label} — {$error}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
