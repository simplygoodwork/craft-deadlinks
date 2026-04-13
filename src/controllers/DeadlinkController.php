<?php

namespace simplygoodwork\deadlinks\controllers;

use Craft;
use craft\web\Controller;
use simplygoodwork\deadlinks\Deadlinks;
use simplygoodwork\deadlinks\models\LinkStatus;
use simplygoodwork\deadlinks\queue\jobs\CheckArchiveJob;
use simplygoodwork\deadlinks\queue\jobs\CheckLinkJob;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Deadlink Controller
 *
 * Handles the confirmation page for dead links
 */
class DeadlinkController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['confirmation'];

    /**
     * Display the confirmation page for a dead link
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionConfirmation(): Response
    {
        $url = Craft::$app->getRequest()->getQueryParam('url');

        if (empty($url)) {
            throw new BadRequestHttpException('URL parameter is required');
        }

        // Validate that it's a proper URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new BadRequestHttpException('Invalid URL provided');
        }

        // Get link status from cache or database
        $linkStatus = Deadlinks::getInstance()->linkChecker->getLinkStatus($url);

        // Queue jobs if needed (for direct visits to confirmation page)
        $this->queueJobsIfNeeded($url, $linkStatus);

        // Determine archive status
        $archiveUrl = $linkStatus?->archiveUrl;
        $archiveChecked = $linkStatus?->archiveChecked;
        $archiveService = Deadlinks::getInstance()->archive;

        // Generate fallback search URL
        $waybackSearchUrl = $archiveService->getWaybackSearchUrl($url);

        // Determine if archive check is in progress
        $archiveCheckInProgress = ($linkStatus?->status === 'dead' && $archiveChecked === null);

        // Build scoped variables to avoid conflicts with user templates
        $deadlinks = [
            'url' => $url,
            'linkStatus' => $linkStatus,
            'archiveUrl' => $archiveUrl,
            'archiveChecked' => $archiveChecked,
            'archiveCheckInProgress' => $archiveCheckInProgress,
            'waybackSearchUrl' => $waybackSearchUrl,
        ];

        $settings = Deadlinks::getInstance()->getSettings();
        $view = Craft::$app->getView();

        // Use custom template if configured, otherwise use built-in template
        if (!empty($settings->confirmationTemplate)) {
            $html = $view->renderTemplate($settings->confirmationTemplate, [
                'deadlinks' => $deadlinks,
            ]);
        } else {
            // Load and render the template directly from the plugin's templates folder
            $pluginPath = dirname(__DIR__, 2);
            $templatePath = $pluginPath . '/templates/confirmation.twig';
            $template = file_get_contents($templatePath);

            $html = $view->renderString($template, [
                'deadlinks' => $deadlinks,
            ]);
        }

        return $this->asRaw($html);
    }

    /**
     * Queue link check and archive lookup jobs if needed
     *
     * @param string $url
     * @param LinkStatus|null $linkStatus
     * @return void
     */
    private function queueJobsIfNeeded(string $url, ?LinkStatus $linkStatus): void
    {
        $settings = Deadlinks::getInstance()->getSettings();
        $cache = Craft::$app->getCache();

        // If link hasn't been checked yet, queue a link check
        if ($linkStatus === null) {
            $jobCacheKey = CheckLinkJob::getJobStatusCacheKey($url);
            if ($cache->get($jobCacheKey) === false) {
                $cache->set($jobCacheKey, true, 300);
                Craft::$app->getQueue()->push(new CheckLinkJob(['url' => $url]));
            }
            return;
        }

        // If link is dead and archive hasn't been checked, queue archive lookup
        if ($linkStatus->status === 'dead' &&
            $linkStatus->archiveChecked === null &&
            $settings->enableArchiveLookup) {
            $jobCacheKey = CheckArchiveJob::getJobStatusCacheKey($url);
            if ($cache->get($jobCacheKey) === false) {
                $cache->set($jobCacheKey, true, 300);
                Craft::$app->getQueue()->push(new CheckArchiveJob(['url' => $url]));
            }
        }
    }
}
