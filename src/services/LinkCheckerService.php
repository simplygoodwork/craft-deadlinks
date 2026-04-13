<?php

namespace simplygoodwork\deadlinks\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use simplygoodwork\deadlinks\Deadlinks;
use simplygoodwork\deadlinks\models\LinkStatus;
use simplygoodwork\deadlinks\queue\jobs\CheckLinkJob;
use simplygoodwork\deadlinks\records\LinkRecord;

/**
 * Link Checker Service
 *
 * Checks external links via database and queues jobs for unchecked links
 */
class LinkCheckerService extends Component
{
    /**
     * Process rendered HTML - check database and rewrite dead links
     *
     * @param string $html
     * @return string Modified HTML with dead links rewritten
     */
    public function processHtml(string $html): string
    {
        $settings = Deadlinks::getInstance()->getSettings();

        if (!$settings->enableLinkChecking) {
            return $html;
        }

        // Extract all external links from the HTML
        $links = $this->extractExternalLinks($html);

        if (empty($links)) {
            return $html;
        }

        // Check database for link statuses and queue jobs for unchecked links
        $deadLinks = $this->getDeadLinks($links);

        // Rewrite dead links in the HTML
        return $this->rewriteDeadLinks($html, $deadLinks);
    }

    /**
     * Extract external links from HTML
     *
     * @param string $html
     * @return array Array of unique external URLs
     */
    private function extractExternalLinks(string $html): array
    {
        $settings = Deadlinks::getInstance()->getSettings();
        $currentDomain = parse_url(UrlHelper::siteUrl(), PHP_URL_HOST);
        $links = [];

        // Match all anchor tags with href attributes
        preg_match_all('/<a[^>]+href=(["\'])([^"\']+)\1[^>]*>/i', $html, $matches);

        if (empty($matches[2])) {
            return [];
        }

        foreach ($matches[2] as $url) {
            // Skip if already a confirmation link
            if (str_contains($url, $settings->confirmationRoute)) {
                continue;
            }

            // Only process external HTTP(S) links
            if (!preg_match('/^https?:\/\//i', $url)) {
                continue;
            }

            $urlHost = parse_url($url, PHP_URL_HOST);

            // Skip internal links
            if ($urlHost === $currentDomain) {
                continue;
            }

            // Skip localhost and internal/private IPs
            if ($this->isInternalHost($urlHost)) {
                continue;
            }

            // Skip excluded domains
            if (in_array($urlHost, $settings->excludedDomains, true)) {
                continue;
            }

            $links[$url] = true; // Use array key for deduplication
        }

        return array_keys($links);
    }

    /**
     * Check if a host is internal (localhost, private IP, etc.)
     *
     * @param string|null $host
     * @return bool
     */
    private function isInternalHost(?string $host): bool
    {
        if ($host === null) {
            return true;
        }

        // Check for localhost variants
        $localhostPatterns = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        if (in_array(strtolower($host), $localhostPatterns, true)) {
            return true;
        }

        // Check for .local, .localhost, .test, .invalid, .example TLDs (non-routable)
        $internalTlds = ['.local', '.localhost', '.test', '.invalid', '.example'];
        foreach ($internalTlds as $tld) {
            if (str_ends_with(strtolower($host), $tld)) {
                return true;
            }
        }

        // Check for private IP ranges
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            // Private IPv4 ranges: 10.x.x.x, 172.16-31.x.x, 192.168.x.x
            // Link-local: 169.254.x.x
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check database for link statuses and queue jobs for unchecked links
     *
     * @param array $urls
     * @return array Array of URLs that are confirmed dead
     */
    private function getDeadLinks(array $urls): array
    {
        $settings = Deadlinks::getInstance()->getSettings();
        $deadLinks = [];

        foreach ($urls as $url) {
            $record = LinkRecord::findByUrl($url);

            if ($record === null) {
                // Not in database - queue a job to check it
                $this->queueLinkCheck($url);
                continue;
            }

            $linkStatus = $this->recordToLinkStatus($record);

            // Check if data is stale and needs re-checking
            if (!$linkStatus->isValid($settings->cacheAliveDuration, $settings->cacheDeadDuration)) {
                $this->queueLinkCheck($url);
                continue;
            }

            // Only add to dead links if confirmed dead
            if ($linkStatus->status === 'dead') {
                $deadLinks[] = $url;
            }
        }

        return $deadLinks;
    }

    /**
     * Convert a LinkRecord to a LinkStatus model
     *
     * @param LinkRecord $record
     * @return LinkStatus
     */
    private function recordToLinkStatus(LinkRecord $record): LinkStatus
    {
        // Convert DateTime strings from database to timestamps
        $lastChecked = null;
        if ($record->lastChecked !== null) {
            $lastChecked = is_string($record->lastChecked)
                ? strtotime($record->lastChecked)
                : $record->lastChecked->getTimestamp();
        }

        $archiveChecked = null;
        if ($record->archiveChecked !== null) {
            $archiveChecked = is_string($record->archiveChecked)
                ? strtotime($record->archiveChecked)
                : $record->archiveChecked->getTimestamp();
        }

        return new LinkStatus([
            'url' => $record->url,
            'status' => $record->status,
            'statusCode' => $record->statusCode,
            'lastChecked' => $lastChecked,
            'checkCount' => $record->checkCount,
            'archiveUrl' => $record->archiveUrl,
            'archiveChecked' => $archiveChecked,
        ]);
    }

    /**
     * Queue a link check job if not already queued
     *
     * @param string $url
     * @return void
     */
    private function queueLinkCheck(string $url): void
    {
        $cache = Craft::$app->getCache();
        $jobCacheKey = CheckLinkJob::getJobStatusCacheKey($url);

        // Check if job is already queued (use cache to prevent duplicate jobs)
        if ($cache->get($jobCacheKey) !== false) {
            return;
        }

        // Mark that we've queued a job (expires after 5 minutes)
        $cache->set($jobCacheKey, true, 300);

        // Add the job to the queue
        Craft::$app->getQueue()->push(new CheckLinkJob([
            'url' => $url,
        ]));
    }

    /**
     * Rewrite dead links in HTML to point to confirmation page
     *
     * @param string $html
     * @param array $deadLinks Array of dead URLs
     * @return string
     */
    private function rewriteDeadLinks(string $html, array $deadLinks): string
    {
        $settings = Deadlinks::getInstance()->getSettings();

        foreach ($deadLinks as $url) {
            $confirmationUrl = UrlHelper::url($settings->confirmationRoute, ['url' => $url]);

            // Escape special regex characters in the URL
            $escapedUrl = preg_quote($url, '/');

            // Replace the href attribute value
            $html = preg_replace(
                '/(<a[^>]+href=)(["\'])' . $escapedUrl . '\2/i',
                '$1$2' . $confirmationUrl . '$2',
                $html
            );
        }

        return $html;
    }

    /**
     * Get link status from database
     *
     * @param string $url
     * @return LinkStatus|null
     */
    public function getLinkStatus(string $url): ?LinkStatus
    {
        $record = LinkRecord::findByUrl($url);

        if ($record === null) {
            return null;
        }

        return $this->recordToLinkStatus($record);
    }
}
