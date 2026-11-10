<?php

namespace App\Models\Concerns;

trait DeletesAtomically
{
    /** Keep the model deletion and the records changed by its events in one transaction. */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }
}
