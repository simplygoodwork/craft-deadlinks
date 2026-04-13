<?php

namespace simplygoodwork\deadlinks;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use simplygoodwork\deadlinks\models\Settings;
use simplygoodwork\deadlinks\services\ArchiveService;
use simplygoodwork\deadlinks\services\LinkCheckerService;
use yii\base\Event;
use yii\web\Response;

/**
 * Deadlinks Plugin
 *
 * Automatically detects dead external links and offers archived versions from the Wayback Machine.
 *
 * @method static Deadlinks getInstance()
 * @method Settings getSettings()
 * @property ArchiveService $archive
 * @property LinkCheckerService $linkChecker
 * @author Good Work <hello@simplygoodwork.com>
 * @copyright Good Work
 * @license MIT
 */
class Deadlinks extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'archive' => ArchiveService::class,
                'linkChecker' => LinkCheckerService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register console commands
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'simplygoodwork\deadlinks\console\controllers';
        }

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        // Register site URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $settings = $this->getSettings();
                $event->rules[$settings->confirmationRoute] = 'deadlinks/deadlink/confirmation';
            }
        );

        // Check and rewrite external links after response is prepared
        Event::on(
            Response::class,
            Response::EVENT_AFTER_PREPARE,
            function(Event $event) {
                /** @var Response $response */
                $response = $event->sender;

                $request = Craft::$app->getRequest();

                // Skip console requests (queue worker) and CP requests
                if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                    return;
                }

                // Skip the confirmation page itself to avoid rewriting the "Try Original Link"
                $settings = $this->getSettings();
                $pathInfo = trim($request->getPathInfo(), '/');
                if ($pathInfo === trim($settings->confirmationRoute, '/')) {
                    return;
                }

                // Only process HTML responses
                $contentType = $response->getHeaders()->get('content-type');
                if ($contentType && !str_contains($contentType, 'text/html')) {
                    return;
                }

                // Get the response content
                $content = $response->content;
                if (empty($content) || !is_string($content)) {
                    return;
                }

                // Only process if it looks like HTML
                if (!str_contains($content, '</html>')) {
                    return;
                }

                $response->content = $this->linkChecker->processHtml($content);
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Settings
    {
        return new Settings();
    }
}
