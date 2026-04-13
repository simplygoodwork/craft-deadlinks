<?php

namespace simplygoodwork\deadlinks\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use simplygoodwork\deadlinks\Deadlinks;
use simplygoodwork\deadlinks\records\LinkRecord;

/**
 * Check Link Job
 *
 * Asynchronously checks if a URL is alive or dead, and queues archive lookup if dead
 */
class CheckLinkJob extends BaseJob
{
    /**
     * @var string The URL to check
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
        $settings = $plugin->getSettings();

        // Find or create the database record
        $record = LinkRecord::findOrCreateByUrl($this->url);

        // Check the link status
        $this->checkLink($record);

        // Save to database
        $record->save();

        // Clear the "job queued" marker
        $cache = Craft::$app->getCache();
        $jobCacheKey = self::getJobStatusCacheKey($this->url);
        $cache->delete($jobCacheKey);

        Craft::info("Link check complete for {$this->url}: {$record->status} ({$record->statusCode})", __METHOD__);

        // If dead, queue the archive check
        if ($record->status === 'dead' && $settings->enableArchiveLookup && $record->archiveChecked === null) {
            Craft::$app->getQueue()->push(new CheckArchiveJob([
                'url' => $this->url,
            ]));
            Craft::info("Queued archive lookup for dead link: {$this->url}", __METHOD__);
        }
    }

    /**
     * Check if the URL is alive or dead and update the record
     *
     * @param LinkRecord $record
     * @return void
     */
    private function checkLink(LinkRecord $record): void
    {
        $settings = Deadlinks::getInstance()->getSettings();

        // First try HEAD request
        $result = $this->makeRequest($this->url, true, $settings->checkTimeout);

        Craft::debug("HEAD request for {$this->url}: httpCode={$result['httpCode']}, error='{$result['error']}', errno={$result['errno']}", __METHOD__);

        // If HEAD failed with empty reply or method not allowed, try GET
        if ($result['errno'] === 52 || $result['httpCode'] === 405) {
            Craft::debug("HEAD failed, trying GET for {$this->url}", __METHOD__);
            $result = $this->makeRequest($this->url, false, $settings->checkTimeout);
            Craft::debug("GET request for {$this->url}: httpCode={$result['httpCode']}, error='{$result['error']}', errno={$result['errno']}", __METHOD__);
        }

        // DNS failure (errno 6) = site doesn't exist = dead
        if ($result['errno'] === 6) {
            $record->markDead(0);
            return;
        }

        // Other network errors = unknown (might be temporary)
        if ($result['error'] !== '' || $result['errno'] !== 0 || $result['httpCode'] === 0) {
            $record->markUnknown();
        } elseif ($result['httpCode'] >= 200 && $result['httpCode'] < 400) {
            // Successful response (including redirects)
            $record->markAlive($result['httpCode']);
        } else {
            // 4xx or 5xx error
            $record->markDead($result['httpCode']);
        }
    }

    /**
     * Make an HTTP request
     *
     * @param string $url
     * @param bool $headOnly Use HEAD request instead of GET
     * @param int $timeout
     * @return array{httpCode: int, error: string, errno: int}
     */
    private function makeRequest(string $url, bool $headOnly, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Deadlinks-Checker/1.0)',
        ]);

        curl_exec($ch);
        $result = [
            'httpCode' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'error' => curl_error($ch),
            'errno' => curl_errno($ch),
        ];
        curl_close($ch);

        return $result;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Checking link status: {$this->url}";
    }

    /**
     * Get the cache key for tracking job status
     *
     * @param string $url
     * @return string
     */
    public static function getJobStatusCacheKey(string $url): string
    {
        return 'deadlinks_linkjob_' . md5($url);
    }
}
