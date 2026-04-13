<?php

namespace simplygoodwork\deadlinks\records;

use craft\db\ActiveRecord;

/**
 * Link Record
 *
 * @property int $id
 * @property string $url
 * @property string $urlHash
 * @property string $status
 * @property int|null $statusCode
 * @property \DateTime|null $lastChecked
 * @property int $checkCount
 * @property string|null $archiveUrl
 * @property \DateTime|null $archiveChecked
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class LinkRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%deadlinks_links}}';
    }

    /**
     * Find a link record by URL
     *
     * @param string $url
     * @return static|null
     */
    public static function findByUrl(string $url): ?self
    {
        return self::findOne(['urlHash' => md5($url)]);
    }

    /**
     * Find or create a link record by URL
     *
     * @param string $url
     * @return static
     */
    public static function findOrCreateByUrl(string $url): self
    {
        $record = self::findByUrl($url);

        if ($record === null) {
            $record = new self();
            $record->url = $url;
            $record->urlHash = md5($url);
            $record->status = 'unknown';
            $record->checkCount = 0;
        }

        return $record;
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
        $this->lastChecked = new \DateTime();
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
        $this->lastChecked = new \DateTime();
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
        $this->lastChecked = new \DateTime();
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
        $this->archiveChecked = new \DateTime();
    }
}
