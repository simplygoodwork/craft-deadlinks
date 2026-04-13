<?php

namespace simplygoodwork\deadlinks\services;

use Craft;
use craft\base\Component;
use simplygoodwork\deadlinks\Deadlinks;

/**
 * Archive Service
 *
 * Handles communication with the Internet Archive Wayback Machine API
 */
class ArchiveService extends Component
{
    /**
     * Wayback Machine Availability API endpoint
     */
    private const WAYBACK_API_URL = 'https://archive.org/wayback/available';

    /**
     * Check if an archived version of a URL exists.
     *
     * Tries multiple URL variants (with/without www, with/without trailing slash)
     * because the Wayback API only matches exact URLs.
     *
     * @param string $url The URL to check
     * @return array|null Returns archive data array if found, empty array if no archive exists, or null if API call failed
     */
    public function checkArchive(string $url): ?array
    {
        $settings = Deadlinks::getInstance()->getSettings();

        if (!$settings->enableArchiveLookup) {
            return null;
        }

        $variants = $this->getUrlVariants($url);
        $hadApiFailure = false;

        foreach ($variants as $variant) {
            $result = $this->queryWaybackApi($variant);

            if ($result === null) {
                // API failure — note it but try remaining variants
                $hadApiFailure = true;
                continue;
            }

            // Found an archive — return it immediately
            if (!empty($result)) {
                return $result;
            }
        }

        // If any API call failed, return null so the job retries later
        if ($hadApiFailure) {
            return null;
        }

        // All variants checked, none had an archive
        return [];
    }

    /**
     * Query the Wayback Availability API for a single URL
     *
     * @param string $url
     * @return array|null Archive data if found, empty array if not found, null if API failed
     */
    private function queryWaybackApi(string $url): ?array
    {
        // The Wayback API requires the URL to be passed unencoded
        $apiUrl = self::WAYBACK_API_URL . '?url=' . $url;

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Deadlinks-Checker/1.0 (Craft CMS Plugin)',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || $httpCode !== 200) {
            Craft::warning("Wayback Machine API error for {$url}: HTTP {$httpCode}, Error: {$error}", __METHOD__);
            return null;
        }

        if (stripos($response, '<!DOCTYPE html>') !== false || stripos($response, '<html') !== false) {
            Craft::warning("Wayback Machine API returned HTML (service may be offline) for {$url}", __METHOD__);
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::warning("Wayback Machine API returned invalid JSON for {$url}: " . json_last_error_msg(), __METHOD__);
            return null;
        }

        if (!isset($data['archived_snapshots']['closest']['available']) ||
            $data['archived_snapshots']['closest']['available'] !== true) {
            return [];
        }

        $snapshot = $data['archived_snapshots']['closest'];

        return [
            'url' => $snapshot['url'] ?? null,
            'timestamp' => $snapshot['timestamp'] ?? null,
            'status' => $snapshot['status'] ?? null,
        ];
    }

    /**
     * Generate URL variants to try against the Wayback API.
     *
     * The API only matches exact URLs, so we need to try combinations
     * of with/without www and with/without trailing slash.
     *
     * @param string $url
     * @return array Unique URL variants, starting with the original
     */
    private function getUrlVariants(string $url): array
    {
        $variants = [$url];

        // Toggle trailing slash
        if (str_ends_with($url, '/')) {
            $variants[] = rtrim($url, '/');
        } else {
            $variants[] = $url . '/';
        }

        // Toggle www for each variant so far
        $withWwwToggled = [];
        foreach ($variants as $variant) {
            if (preg_match('#^(https?://)www\.(.+)#i', $variant, $m)) {
                $withWwwToggled[] = $m[1] . $m[2]; // remove www
            } elseif (preg_match('#^(https?://)([^/].+)#i', $variant, $m)) {
                $withWwwToggled[] = $m[1] . 'www.' . $m[2]; // add www
            }
        }

        $variants = array_merge($variants, $withWwwToggled);

        return array_unique($variants);
    }

    /**
     * Get the direct Wayback Machine URL for a given URL
     * This is a fallback URL that may or may not have an archived version
     *
     * @param string $url
     * @return string
     */
    public function getWaybackSearchUrl(string $url): string
    {
        return 'https://web.archive.org/web/*/' . $url;
    }

    /**
     * Parse timestamp from Wayback Machine format (YYYYMMDDHHmmss) to DateTime
     *
     * @param string $timestamp
     * @return \DateTime|null
     */
    public function parseTimestamp(string $timestamp): ?\DateTime
    {
        $date = \DateTime::createFromFormat('YmdHis', $timestamp);
        return $date ?: null;
    }
}
