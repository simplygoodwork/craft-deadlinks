<?php

namespace simplygoodwork\deadlinks\migrations;

use craft\db\Migration;

/**
 * Install migration
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTable('{{%deadlinks_links}}', [
            'id' => $this->primaryKey(),
            'url' => $this->text()->notNull(),
            'urlHash' => $this->string(32)->notNull(), // MD5 hash for indexing
            'status' => $this->enum('status', ['alive', 'dead', 'unknown'])->notNull()->defaultValue('unknown'),
            'statusCode' => $this->smallInteger()->unsigned()->null(),
            'lastChecked' => $this->dateTime()->null(),
            'checkCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'archiveUrl' => $this->text()->null(),
            'archiveChecked' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create unique index on urlHash for fast lookups
        $this->createIndex(null, '{{%deadlinks_links}}', ['urlHash'], true);

        // Create index on status for filtering
        $this->createIndex(null, '{{%deadlinks_links}}', ['status']);

        // Create index on lastChecked for finding stale entries
        $this->createIndex(null, '{{%deadlinks_links}}', ['lastChecked']);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deadlinks_links}}');

        return true;
    }
}
