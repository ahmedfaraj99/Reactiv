<?php

namespace App\Filament\App\Concerns;

use Livewire\Attributes\Url;

/**
 * The "الكل | بمباريات | تفعيل فقط" switch shared by the managers'
 * accounts list and the employee's queue. Match and activation-only
 * orders are different jobs at the console, so each page lets staff
 * look at one kind at a time. Kept in the URL so a refresh (or the
 * back button from the activation page) lands on the same view.
 */
trait HasMatchesTypeSwitch
{
    #[Url(as: 'type')]
    public string $matchesType = 'all';

    /**
     * @return array{all: int, matches: int, activation: int}
     */
    abstract public function getMatchesTypeCounts(): array;

    public function updatedMatchesType(): void
    {
        if (! in_array($this->matchesType, ['all', 'matches', 'activation'], true)) {
            $this->matchesType = 'all';
        }

        $this->resetPage();
    }

    /** The scope argument for Account::ofMatchesType() — null means no filter. */
    protected function activeMatchesType(): ?string
    {
        return in_array($this->matchesType, ['matches', 'activation'], true) ? $this->matchesType : null;
    }
}
