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

    /**
     * Name the gate this screen sits behind, and close the screen to an account that
     * does not hold it.
     *
     * The enforcement is the point: a hidden sidebar entry is a courtesy, and a page
     * whose key is only remembered for its buttons is still reachable by anyone who
     * types the URL. kPageGate() lets non-admin routes through, so a shared screen
     * opened from the member workspace is unaffected.
     *
     * @param  string  $pageGate  The navigation key this screen sits under.
     * @param  GateAccessEnum  $required  The minimum access needed to open it.
     */
    protected function setPageGate(string $pageGate, GateAccessEnum $required = GateAccessEnum::VIEW): void
    {
        $this->pageGate = $pageGate;
        $this->gateModify = GateAccessEnum::MODIFY->value;
        $this->gateCreate = GateAccessEnum::CREATE->value;
        $this->gateFull = GateAccessEnum::FULL->value;

        kPageGate($pageGate, $required);
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
