<?php

declare(strict_types=1);

namespace Racin\Database\Migrations;

use Racin\Database\Schema\Schema;

/**
 * A migration file in database/migrations returns an instance of an anonymous subclass:
 *
 *     return new class extends Migration {
 *         public function up(Schema $schema): void
 *         {
 *             $schema->create('posts', function (Blueprint $table) { ... });
 *         }
 *
 *         public function down(Schema $schema): void
 *         {
 *             $schema->dropIfExists('posts');
 *         }
 *     };
 */
abstract class Migration
{
    /** Connection name from config/database.php; null = default */
    public ?string $connection = null;

    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
    }
}
