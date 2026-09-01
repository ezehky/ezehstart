<?php

namespace App\Traits;

use Illuminate\Validation\Rules\Password;

trait WithPasswordTools
{
    public string $passwordNote = 'Password min 8 chars, a symbol, a number, with both uppercase and lowercase chars.';

    protected function passwordStrengthRule()
    {
        return Password::min(8)->symbols()->mixedCase()->numbers();
    }
}
