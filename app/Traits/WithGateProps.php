<?php

namespace App\Traits;

use App\Enums\GateAccessEnum;

trait WithGateProps
{
    use WithFormResponseMessage;

    public string $pageGate = 'dashboard';

    public string $gateModify;

    public string $gateCreate;

    public string $gateFull;

    protected function setPageGate(string $pageGate): void
    {
        $this->pageGate = $pageGate;
        $this->gateModify = GateAccessEnum::MODIFY->value;
        $this->gateCreate = GateAccessEnum::CREATE->value;
        $this->gateFull = GateAccessEnum::FULL->value;
    }

    protected function checkGate(GateAccessEnum $access = GateAccessEnum::MODIFY, ?string $msg = null): void
    {
        if ($msg === null) {
            $msg = match ($access) {
                GateAccessEnum::CREATE => 'You do not have access to create new items in this area.',
                GateAccessEnum::MODIFY => 'You do not have access to modify items in this area.',
                default => 'You do not have access to affect items in this area.',
            };
        }

        $this->respondError($msg, ! kGate($this->pageGate, $access));
    }
}
