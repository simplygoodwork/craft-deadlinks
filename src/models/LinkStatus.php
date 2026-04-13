<?php

namespace simplygoodwork\deadlinks\models;

use craft\base\Model;
use DateTime;

/**
 * Link Status Model
 *
 * Stores the status of checked links for caching purposes
 */
class LinkStatus extends Model
{
    /**
     * @var string The URL that was checked
     */
    public string $url = '';

    /**
     * @var string Status of the link (alive, dead, unknown)
     */
    public string $status = 'unknown';

    /**
     * @var int|null HTTP status code returned
     */
    public ?int $statusCode = null;

    /**
     * @var DateTime|null When the link was last checked
     */
    public ?DateTime $lastChecked = null;

    /**
     * @var int Number of times this link has been checked
     */
    public int $checkCount = 0;

    /**
     * @var string|null URL of archived version (if found)
     */
    public ?string $archiveUrl = null;

    /**
     * @var DateTime|null When the archive was last checked
     */
    public ?DateTime $archiveChecked = null;

    /**
     * Check if the cached status is still valid
     *
     * @param int $aliveDuration Cache duration for alive links in seconds
     * @param int $deadDuration Cache duration for dead links in seconds
     * @return bool
     */
    public function isValid(int $aliveDuration, int $deadDuration): bool
    {
        if ($this->lastChecked === null) {
            return false;
        }

        $now = new DateTime();
        $age = $now->getTimestamp() - $this->lastChecked->getTimestamp();

        if ($this->status === 'alive') {
            return $age < $aliveDuration;
        }

        if ($this->status === 'dead') {
            return $age < $deadDuration;
        }

        // For 'unknown' status, cache for 5 minutes
        return $age < 300;
    }

    /**
     * Mark the link as alive
     *
     * @param int $statusCode
     * @return void
     */
    public function markAlive(int $statusCode): void
    {
        $this->status = 'alive';
        $this->statusCode = $statusCode;
        $this->lastChecked = new DateTime();
        $this->checkCount++;
    }

    /**
     * Mark the link as dead
     *
     * @param int|null $statusCode
     * @return void
     */
    public function markDead(?int $statusCode = null): void
    {
        $this->status = 'dead';
        $this->statusCode = $statusCode;
        $this->lastChecked = new DateTime();
        $this->checkCount++;
    }

    /**
     * Mark the link check as failed/unknown
     *
     * @return void
     */
    public function markUnknown(): void
    {
        $this->status = 'unknown';
        $this->lastChecked = new DateTime();
        $this->checkCount++;
    }

    /**
     * Set the archive URL
     *
     * @param string|null $archiveUrl
     * @return void
     */
    public function setArchiveUrl(?string $archiveUrl): void
    {
        $this->archiveUrl = $archiveUrl;
        $this->archiveChecked = new DateTime();
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['url'], 'required'],
            [['url', 'status', 'archiveUrl'], 'string'],
            [['statusCode', 'checkCount'], 'integer'],
            [['status'], 'in', 'range' => ['alive', 'dead', 'unknown']],
        ];
    }

    /**
     * Convert to array for caching
     *
     * @return array
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'url' => $this->url,
            'status' => $this->status,
            'statusCode' => $this->statusCode,
            'lastChecked' => $this->lastChecked?->getTimestamp(),
            'checkCount' => $this->checkCount,
            'archiveUrl' => $this->archiveUrl,
            'archiveChecked' => $this->archiveChecked?->getTimestamp(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Convert timestamps back to DateTime objects
        if (isset($config['lastChecked']) && is_int($config['lastChecked'])) {
            $config['lastChecked'] = (new DateTime())->setTimestamp($config['lastChecked']);
        }
        if (isset($config['archiveChecked']) && is_int($config['archiveChecked'])) {
            $config['archiveChecked'] = (new DateTime())->setTimestamp($config['archiveChecked']);
        }

        parent::__construct($config);
    }
}
