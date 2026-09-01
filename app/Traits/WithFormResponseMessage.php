<?php

namespace App\Traits;

use Flux\Flux;
use Illuminate\Validation\ValidationException;

trait WithFormResponseMessage
{
    private $errorExceptionCatchStopper = '';

    public function respondPrimary(
        string $message = 'You have made no changes to save!!',
        bool $if = false,
        ?callable $callback = null,
        bool $flash = false,
        string $heading = ''
    ): bool {
        if ($if) {
            // Show in toast
            if (! $flash) {
                Flux::toast(heading: $heading, text: $message, variant: 'warning');
            }

            // Flash message
            if ($flash) {
                session()->flash('primary', $message);
            }

            // Run callable function
            if (is_callable($callback)) {
                $callback();
            }

            throw ValidationException::withMessages(['errorExceptionCatchStopper' => 'Stop Code']);
        }

        return true;
    }

    public function respondError(
        mixed $message,
        bool $if = false,
        ?callable $callback = null,
        string $field = '',
        bool $flash = false,
        string $heading = ''
    ): bool {
        if ($if) {
            // Show in toast
            if (! $field && ! $flash) {
                Flux::toast(heading: $heading, text: $message, variant: 'danger');
            }

            // Run callable function
            if (is_callable($callback)) {
                $callback();
            }

            $messages = ['errorExceptionCatchStopper' => 'Stop Code'];

            // Inline field message
            if ($field) {
                $messages = [$field => $message];
            }
            // Flash message
            elseif ($flash) {
                session()->flash('error', $message);
            }

            throw ValidationException::withMessages($messages);
        }

        return true;
    }

    public function respondSuccess(string $message = 'Saved!!', bool $flash = false, string $heading = ''): bool
    {
        if ($flash) {
            session()->flash('success', $message);

            return true;
        }

        // Show in toast
        Flux::toast(heading: $heading, text: $message, variant: 'success');

        return true;
    }

    protected function createAttributes(array $rules): array
    {
        $attributes = [];
        foreach (collect($rules)->keys()->toArray() as $value) {
            $ex = explode('.', $value);
            $str = kBreakText(end($ex));
            $attributes[$value] = str($str)->lower();
        }

        return $attributes;
    }
}
