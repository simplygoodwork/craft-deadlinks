<?php

namespace simplygoodwork\deadlinks\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use simplygoodwork\deadlinks\Deadlinks;
use simplygoodwork\deadlinks\records\LinkRecord;

/**
 * Check Archive Job
 *
 * Asynchronously checks the Internet Archive Wayback Machine for an archived version of a URL
 */
class CheckArchiveJob extends BaseJob
{
    /**
     * @var string The URL to check for archived versions
     */
    public string $url = '';

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (empty($this->url)) {
            return;
        }

        $plugin = Deadlinks::getInstance();
        $archiveService = $plugin->archive;

        // Find the database record (should exist from CheckLinkJob)
        $record = LinkRecord::findByUrl($this->url);

        if ($record === null) {
            Craft::warning("No link record found for archive check: {$this->url}", __METHOD__);
            return;
        }

        // Check the archive
        $archiveResult = $archiveService->checkArchive($this->url);

        // Handle the three possible outcomes:
        // null = API failed (timeout, offline, error) - don't update record, allow retry later
        // empty array = no archive exists (definitive answer) - mark as checked with no archive
        // array with data = archive found (definitive answer) - mark as checked with archive URL

        if ($archiveResult === null) {
            // API call failed - don't update archiveChecked, allow retry on next page load
            Craft::warning("Archive check failed for {$this->url} (will retry later)", __METHOD__);
            // Still clear the job marker so it can be queued again
            $cache = Craft::$app->getCache();
            $jobCacheKey = self::getJobStatusCacheKey($this->url);
            $cache->delete($jobCacheKey);
            return;
        }

        // We got a definitive answer from the API
        if (!empty($archiveResult['url'])) {
            // Archive found
            $record->setArchiveUrl($archiveResult['url']);
            Craft::info("Found archive for {$this->url}: {$archiveResult['url']}", __METHOD__);
        } else {
            // No archive exists (but we successfully checked)
            $record->setArchiveUrl(null);
            Craft::info("No archive found for {$this->url} (checked successfully)", __METHOD__);
        }

        // Save to database
        $record->save();

        // Clear the "job queued" marker
        $cache = Craft::$app->getCache();
        $jobCacheKey = self::getJobStatusCacheKey($this->url);
        $cache->delete($jobCacheKey);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Checking Internet Archive for: {$this->url}";
    }

    /**
     * Get the cache key for tracking job status
     *
     * @param string $url
     * @return string
     */
    public static function getJobStatusCacheKey(string $url): string
    {
        return 'deadlinks_job_' . md5($url);
    }
}
