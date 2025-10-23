<?php

namespace DNADesign\Populate;

use Exception;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class Populate
{
    use Configurable;
    use Extensible;

    private static array $include_yaml_fixtures = [];

    /**
     * An array of classes to clear from the database before importing. While
     * populating SiteTree it may be worth clearing the 'SiteTree' table.
     */
    private static array $truncate_classes = [];

    private static array $truncate_tables = [];

    /**
     * Flag to determine if we're already run for this session (i.e to prevent
     * parent calls invoking {@link requireRecords} twice).
     */
    private static bool $ran = false;

    /**
     * Used internally to not truncate multiple tables multiple times
     */
    private static array $clearedTables = [];

    /**
     * Dir location where the cache files should be stored.
     * This directory should be included in the repositories .gitignore if it is configured to be within repo scope
     * If this variable starts with a / the location will be treated as an abolute location from the root of the server
     * if the variable does not start with a / the location will be prefixed by Director::baseFolder()
     */
    private static ?string $populate_cache_files_location = null;

    /**
     * Array of files that should be included when calculating the hash of the populate state.
     * This should include the .yml file where the populate config is set
     *
     */
    private static array $populate_cache_hash_files = [];

    /**
     * @param bool $force - allows you to bypass the ran check to run this multiple times
     * @throws Exception
     */
    public static function requireRecords(bool $force = false): bool
    {
        if (self::$ran && !$force) {
            return true;
        }

        self::$ran = true;

        if (!self::canBuildOnEnvironment()) {
            throw new Exception('requireRecords can only be run in development or test environments');
        }

        $cacheLocation = self::config()->get('populate_cache_files_location');
        $cacheFileExists = false;

        if ($cacheLocation) {
            $populateHash = self::getPopulateHash();

            if (str_starts_with($cacheLocation, '/')) {
                $populateCacheFile = sprintf(
                    '%s%s%s.sql',
                    $cacheLocation,
                    str_ends_with($cacheLocation, '/') ? '' : '/',
                    $populateHash
                );
            } else {
                $baseDir = Director::baseFolder();
                $populateCacheFile = sprintf(
                    '%s/%s%s%s.sql',
                    $baseDir,
                    $cacheLocation,
                    str_ends_with($cacheLocation, '/') ? '' : '/',
                    $populateHash
                );
            }


            $cacheFileExists = file_exists($populateCacheFile);
        }

        if ($cacheFileExists) {
            DB::alteration_message('Cache file located. Populating DB from cached SQL file.');
            $execCommand = sprintf(
                "mysql --user=%s --password=%s %s < %s",
                Environment::getEnv('SS_DATABASE_USERNAME'),
                Environment::getEnv('SS_DATABASE_PASSWORD'),
                Environment::getEnv('SS_DATABASE_NAME'),
                $populateCacheFile
            );

            exec($execCommand, $output);
            DB::alteration_message('Populate data imported using cached SQL file.');
        } else {
            /** @var PopulateFactory $factory */
            $factory = Injector::inst()->create(PopulateFactory::class);

            foreach (self::config()->get('truncate_objects') as $className) {
                self::truncateObject($className);
            }

            foreach (self::config()->get('truncate_tables') as $table) {
                self::truncateTable($table);
            }

            foreach (self::config()->get('include_yaml_fixtures') as $fixtureFile) {
                DB::alteration_message(sprintf('Processing %s', $fixtureFile), 'created');
                $fixture = new YamlFixture($fixtureFile);
                $fixture->writeInto($factory);

                $fixture = null;
            }

            $factory->processFailedFixtures();

            $populate = Injector::inst()->create(Populate::class);
            $populate->extend('onAfterPopulateRecords');
        }

        if (!$cacheFileExists) {
            DB::alteration_message('No populate cache file found.');

            if ($cacheLocation) {
                if (!file_exists($cacheLocation)) {
                    DB::alteration_message(
                        sprintf('Cache directory does not exist. Creating directory at `%s`.',$cacheLocation)
                    );
                    mkdir($cacheLocation);
                }

                $execCommand = sprintf(
                    "mysqldump --user=%s --password=%s --host=%s %s --result-file=%s 2>&1",
                    Environment::getEnv('SS_DATABASE_USERNAME'),
                    Environment::getEnv('SS_DATABASE_PASSWORD'),
                    Environment::getEnv('SS_DATABASE_SERVER'),
                    Environment::getEnv('SS_DATABASE_NAME'),
                    sprintf('%s%s.sql', $cacheLocation, $populateHash)
                );

                exec($execCommand, $output);

                var_dump($output);
            } else {
                DB::alteration_message('No cache file directory has been set. Populate cache file has not been created');
            }
        } else {
            DB::alteration_message('Skipping cached populate file creation - Populate cache file already exists.');
        }

        return true;
    }

    /**
     * Calculate a hash of the current state of the populate config.
     * This function generates a sha256 hash based on the `git hash-object` strings created for each file specified in
     * `populate_cache_hash_files` and `include_yaml_fixtures`
     */
    private static function getPopulateHash(): string
    {
        $baseDir = Director::baseFolder();
        $hashFiles = self::config()->get('populate_cache_hash_files');
        $hashFiles = array_merge($hashFiles, self::config()->get('include_yaml_fixtures'));

        $hashFiles = array_map(function ($value) use ($baseDir) {
            return sprintf('%s/%s', $baseDir, $value);
        }, $hashFiles);

        $execCommand = sprintf('git hash-object %s', implode(' ', $hashFiles));
        exec($execCommand, $hashes);

        return hash('sha256', serialize($hashes));
    }

    /**
     * Delete all the associated tables for a class
     */
    private static function truncateObject(string $className): void
    {
        if (in_array($className, ClassInfo::subclassesFor(File::class))) {
            foreach (DataList::create($className) as $obj) {
                /** @var File $obj */
                $obj->deleteFile();
            }
        }

        $tables = [];

        // All ancestors or children with tables
        $withTables = array_filter(
            array_merge(
                ClassInfo::ancestry($className),
                ClassInfo::subclassesFor($className)
            ),
            function ($next) {
                return DataObject::getSchema()->classHasTable($next);
            }
        );

        $classTables = [];

        foreach ($withTables as $className) {
            $classTables[$className] = DataObject::getSchema()->tableName($className);
        }

        // Establish tables which store object data that needs to be truncated
        foreach ($classTables as $className => $baseTable) {
            /** @var DataObject|Versioned $obj */
            $obj = Injector::inst()->get($className);

            // Include base tables
            $tables[$baseTable] = $baseTable;

            if (!$obj->hasExtension(Versioned::class)) {
                // No versioned tables to clear
                continue;
            }

            $stages = $obj->getVersionedStages();

            foreach ($stages as $stage) {
                $table = $obj->stageTable($baseTable, $stage);

                // Include staged table(s)
                $tables[$table] = $table;
            }

            $versionedTable = "{$baseTable}_Versions";

            // Include versions table
            $tables[$versionedTable] = $versionedTable;
        }

        $populate = Injector::inst()->create(Populate::class);
        $populate->extend('updateTruncateObjectTables', $tables, $className, $classTables);

        foreach ($tables as $table) {
            if (!DB::get_schema()->hasTable($table)) {
                // No table to clear
                continue;
            }

            self::truncateTable($table);
        }
    }

    /**
     * Attempts to truncate a table. Outputs messages to indicate if table has
     * already been truncated or cannot be truncated
     */
    private static function truncateTable(string $table): void
    {
        if (array_key_exists($table, self::$clearedTables)) {
            DB::alteration_message("$table already truncated", "deleted");

            return;
        }

        DB::alteration_message("Truncating table $table", "deleted");

        try {
            DB::get_conn()->clearTable($table);
        } catch (DatabaseException $databaseException) {
            DB::alteration_message("Couldn't truncate table $table as it doesn't exist", "deleted");
        }

        self::$clearedTables[$table] = true;
    }

    private static function canBuildOnEnvironment(): bool
    {
        // Populate (by default) is allowed to run on dev and test environments
        if (Director::isDev() || Director::isTest()) {
            return true;
        }

        // Check if developer/s have specified that Populate can run on live
        return (bool)self::config()->get('allow_build_on_live');
    }
}
