<?php
declare(strict_types=1);

namespace Webhooks\Cli\Interactor;

/**
 * Pane enum for navigation between different UI areas in the interactive TUI.
 */
enum Pane {
    case Browse;      // Main activity list
    case FilterType;  // Type filter selection
    case FilterUser;  // User filter input
    case FilterRepo;  // Repo toggle list
    case FilterOrg;   // Org filter input
    case FilterTime;  // Time filter selection
    case Detail;      // Item detail view
    case Help;        // Help overlay

    /**
     * Navigate to the next pane in circular order.
     * Note: Help is excluded from navigation cycle - reachable only via '?' key.
     */
    public function next(): self {
        return match($this) {
            self::Browse => self::FilterType,
            self::FilterType => self::FilterUser,
            self::FilterUser => self::FilterOrg,
            self::FilterOrg => self::FilterRepo,
            self::FilterRepo => self::FilterTime,
            self::FilterTime => self::Browse,
            self::Help => self::Browse,
            self::Detail => self::Browse,
        };
    }

    /**
     * Navigate to the previous pane.
     * Note: Help is excluded from navigation cycle - reachable only via '?' key.
     */
    public function previous(): self {
        return match($this) {
            self::Browse => self::FilterTime,
            self::FilterType => self::Browse,
            self::FilterUser => self::FilterType,
            self::FilterOrg => self::FilterUser,
            self::FilterRepo => self::FilterOrg,
            self::FilterTime => self::FilterRepo,
            self::Help => self::FilterTime,
            self::Detail => self::Browse,
        };
    }
}
