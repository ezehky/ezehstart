<?php

namespace App\Traits;

use Flux\Flux;
use Illuminate\Validation\ValidationException;

trait WithFormResponseMessage
{
    private $errorExceptionCatchStopper = '';

    public array $overrideValidationAttributes = [];

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

    private function getFieldLastWord(string $field)
    {
        $ex = explode('.', $field);

        return kBreakText(end($ex), lowercase: true);
    }

    protected function validationAttributes(): array
    {
        // WithAuthWorker carries this trait into a plain controller as well, and a
        // controller has no rule set to name its fields from. Nothing calls this
        // there — Livewire does — so an empty list is the honest answer.
        $rules = method_exists($this, 'getRules')
            ? collect($this->getRules())->keys()->toArray()
            : [];

        $attributes = [];
        foreach ($rules as $value) {
            $attributes[$value] = $this->getFieldLastWord($value);
        }
        // Overrides
        foreach ($this->overrideValidationAttributes as $key => $value) {
            $attributes[$key] = $value;
        }

        return $attributes;
    }
}
