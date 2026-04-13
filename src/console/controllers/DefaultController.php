<?php

namespace simplygoodwork\deadlinks\console\controllers;

use craft\console\Controller;
use simplygoodwork\deadlinks\records\LinkRecord;
use yii\console\ExitCode;

/**
 * Deadlinks Console Controller
 */
class DefaultController extends Controller
{
    /**
     * Show statistics about checked links
     *
     * @return int
     */
    public function actionStats(): int
    {
        $total = LinkRecord::find()->count();
        $alive = LinkRecord::find()->where(['status' => 'alive'])->count();
        $dead = LinkRecord::find()->where(['status' => 'dead'])->count();
        $unknown = LinkRecord::find()->where(['status' => 'unknown'])->count();
        $withArchive = LinkRecord::find()->where(['not', ['archiveUrl' => null]])->count();
        $archiveChecked = LinkRecord::find()->where(['not', ['archiveChecked' => null]])->count();

        $this->stdout("Deadlinks Statistics\n", \yii\helpers\Console::BOLD);
        $this->stdout("====================\n\n");
        $this->stdout("Total links: {$total}\n");
        $this->stdout("  Alive: {$alive}\n", \yii\helpers\Console::FG_GREEN);
        $this->stdout("  Dead: {$dead}\n", \yii\helpers\Console::FG_RED);
        $this->stdout("  Unknown: {$unknown}\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("\n");
        $this->stdout("Archive checks: {$archiveChecked}\n");
        $this->stdout("  With archive: {$withArchive}\n", \yii\helpers\Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Clear all link records and reset
     *
     * @return int
     */
    public function actionClearAll(): int
    {
        $this->stdout("Clearing all link records...\n");

        $count = LinkRecord::deleteAll();

        $this->stdout("Deleted {$count} link record(s).\n", \yii\helpers\Console::FG_GREEN);
        $this->stdout("All links will be re-checked on next page load.\n");

        return ExitCode::OK;
    }
}
