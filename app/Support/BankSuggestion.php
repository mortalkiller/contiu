<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class BankSuggestion
{
    /**
     * @param  Collection<int, BankCandidate>  $candidates  ranked, best first
     */
    public function __construct(public readonly Collection $candidates) {}

    /**
     * The candidate to accept with one click — only when it is unambiguous: the only one, or the
     * only one whose name matches best. Ties give no suggestion so nothing is matched by luck.
     */
    public function best(): ?BankCandidate
    {
        $first = $this->candidates->first();

        if ($first === null) {
            return null;
        }

        $second = $this->candidates->get(1);

        return $second === null || $first->nameScore > $second->nameScore ? $first : null;
    }

    public function find(string $key): ?BankCandidate
    {
        return $this->candidates->first(fn (BankCandidate $candidate) => $candidate->key() === $key);
    }
}
