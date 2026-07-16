<?php
declare(strict_types=1);

namespace Webhooks\Cli\Interactor;

use SugarCraft\Forms\ItemList\StringItem;

/**
 * Value objects for TUI state management.
 *
 * These readonly structs encapsulate filter state to keep
 * the main InteractiveTui class clean and testable.
 */
final class FilterState
{
    public function __construct(
        public readonly string $filterType = 'all',
        public readonly string $filterUser = '',
        public readonly string $filterOrg = '',
        public readonly int $filterTimeHours = 24,
    ) {}

    /**
     * Create a new FilterState with updated type filter.
     */
    public function withFilterType(string $filterType): self
    {
        return new self($filterType, $this->filterUser, $this->filterOrg, $this->filterTimeHours);
    }

    /**
     * Create a new FilterState with updated user filter.
     */
    public function withFilterUser(string $filterUser): self
    {
        return new self($this->filterType, $filterUser, $this->filterOrg, $this->filterTimeHours);
    }

    /**
     * Create a new FilterState with updated org filter.
     */
    public function withFilterOrg(string $filterOrg): self
    {
        return new self($this->filterType, $this->filterUser, $filterOrg, $this->filterTimeHours);
    }

    /**
     * Create a new FilterState with updated time filter.
     */
    public function withFilterTimeHours(int $filterTimeHours): self
    {
        return new self($this->filterType, $this->filterUser, $this->filterOrg, $filterTimeHours);
    }
}

/**
 * Value object for repository filter toggle state.
 *
 * @readonly
 * @param array<string, bool> $repos repo_name => selected (true = hidden, false = visible)
 */
final class RepoFilterState
{
    /**
     * @param array<string, bool> $repos Repository selection state
     * @param bool $showToggleList Whether the repo toggle list is visible
     */
    public function __construct(
        public readonly array $repos = [],
        public readonly bool $showToggleList = false,
    ) {}

    /**
     * Create a new RepoFilterState with an updated repo toggle.
     */
    public function withRepoToggled(string $repo, bool $hidden): self
    {
        $newRepos = $this->repos;
        $newRepos[$repo] = $hidden;
        return new self($newRepos, $this->showToggleList);
    }

    /**
     * Create a new RepoFilterState showing/hiding the toggle list.
     */
    public function withShowToggleList(bool $show): self
    {
        return new self($this->repos, $show);
    }

    /**
     * Create a new RepoFilterState with all repos set to visible (not hidden).
     */
    public function withAllVisible(): self
    {
        $newRepos = [];
        foreach ($this->repos as $repo => $hidden) {
            $newRepos[$repo] = false;
        }
        return new self($newRepos, $this->showToggleList);
    }

    /**
     * Create a new RepoFilterState with all repos set to hidden.
     */
    public function withAllHidden(): self
    {
        $newRepos = [];
        foreach ($this->repos as $repo => $hidden) {
            $newRepos[$repo] = true;
        }
        return new self($newRepos, $this->showToggleList);
    }

    /**
     * Count of repos that are visible (not hidden).
     *
     * @return int
     */
    public function visibleCount(): int
    {
        return count(array_filter($this->repos, static fn(bool $hidden): bool => !$hidden));
    }

    /**
     * Count of repos that are hidden.
     *
     * @return int
     */
    public function hiddenCount(): int
    {
        return count(array_filter($this->repos, static fn(bool $hidden): bool => $hidden));
    }
}
