<?php

namespace simplygoodwork\deadlinks\models;

use craft\base\Model;

/**
 * Deadlinks Settings Model
 */
class Settings extends Model
{
    /**
     * @var bool Whether link checking is enabled
     */
    public bool $enableLinkChecking = true;

    /**
     * @var int Timeout for link checks in seconds
     */
    public int $checkTimeout = 3;

    /**
     * @var int Cache duration for alive links in seconds (default: 1 hour)
     */
    public int $cacheAliveDuration = 3600;

    /**
     * @var int Cache duration for dead links in seconds (default: 24 hours)
     */
    public int $cacheDeadDuration = 86400;

    /**
     * @var array Domains to exclude from checking
     */
    public array $excludedDomains = [];

    /**
     * @var int Maximum number of concurrent link checks
     */
    public int $maxConcurrentChecks = 10;

    /**
     * @var bool Whether to enable Internet Archive lookup
     */
    public bool $enableArchiveLookup = true;

    /**
     * @var string Route for the confirmation page
     */
    public string $confirmationRoute = 'deadlink-confirmation';

    /**
     * @var string|null Custom Craft template path for confirmation page (e.g., '_partials/deadlink-confirmation')
     *
     * When set, the plugin will render this template instead of the built-in one.
     * All variables are passed in a `deadlinks` object to avoid conflicts:
     * - deadlinks.url - The dead link URL
     * - deadlinks.linkStatus - The LinkStatus model
     * - deadlinks.archiveUrl - Wayback Machine archive URL (if found)
     * - deadlinks.archiveChecked - Whether archive lookup is complete
     * - deadlinks.archiveCheckInProgress - Whether archive lookup is in progress
     * - deadlinks.waybackSearchUrl - URL to search Wayback Machine manually
     */
    public ?string $confirmationTemplate = null;

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['enableLinkChecking', 'enableArchiveLookup'], 'boolean'],
            [['checkTimeout', 'cacheAliveDuration', 'cacheDeadDuration', 'maxConcurrentChecks'], 'integer', 'min' => 1],
            [['confirmationRoute', 'confirmationTemplate'], 'string'],
            [['excludedDomains'], 'safe'],
        ];
    }
}
