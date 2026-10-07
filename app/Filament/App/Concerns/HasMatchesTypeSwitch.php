<?php

namespace App\Filament\App\Concerns;

use Livewire\Attributes\Url;

/**
 * The "بمباريات | تفعيل فقط" switch shared by the managers' accounts
 * list and the employee's queue. Match and activation-only orders are
 * different jobs at the console, so each page shows one kind at a time.
 * Kept in the URL so a refresh (or the back button from the activation
 * page) lands on the same view.
 */
trait HasMatchesTypeSwitch
{
    #[Url(as: 'type')]
    public ?string $matchesType = null;

    /**
     * @return array{all: int, matches: int, activation: int}
     */
    abstract public function getMatchesTypeCounts(): array;

    /**
     * Tabs this page offers, in display order. Employees get no "all"
     * tab — mixing both kinds in one list is the confusion this exists
     * to prevent — while managers keep it for search and overview.
     *
     * @return list<string>
     */
    public function getMatchesTypeOptions(): array
    {
        return ['matches', 'activation', 'all'];
    }

    protected function getDefaultMatchesType(): string
    {
        return 'matches';
    }

    public function mountHasMatchesTypeSwitch(): void
    {
        $this->normalizeMatchesType();
    }

    public function updatedMatchesType(): void
    {
        $this->normalizeMatchesType();
        $this->resetPage();
    }

    protected function normalizeMatchesType(): void
    {
        if (! in_array($this->matchesType, $this->getMatchesTypeOptions(), true)) {
            $this->matchesType = $this->getDefaultMatchesType();
        }
    }

    /** The scope argument for Account::ofMatchesType() — null means no filter. */
    protected function activeMatchesType(): ?string
    {
        return in_array($this->matchesType, ['matches', 'activation'], true) ? $this->matchesType : null;
    }
}
