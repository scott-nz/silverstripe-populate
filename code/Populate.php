<?php

namespace DNADesign\Populate;

use Exception;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
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
     * Set to true to enable the creating and use of cached SQL files
     */
    private static bool $sql_cache_enabled = false;

    /**
     * Dir location where the cache files should be stored.
     * This directory should be included in the repositories .gitignore if it is configured to be within repo scope
     * If this variable starts with a / the location will be treated as an absolute location from the root of the server
     * if the variable does not start with a / the location will be prefixed by Director::baseFolder()
     */
    private static string $cache_files_location = '.populate_sql_cache';

    /**
     * Array of files that should be included when calculating the hash of the populate state.
     * This should include the .yml file where the populate config is set
     */
    private static array $cache_hash_files = [];

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

        $cacheEnabled = self::config()->get('sql_cache_enabled');
        $cacheLocation = self::config()->get('cache_files_location');
        $cacheFileExists = false;

        $controller = Controller::curr();
        $request = $controller->getRequest();
        $ignoreCache = $request->getVar('ignoreCache');

        if ($cacheEnabled && $cacheLocation && !$ignoreCache) {
            DB::alteration_message('SQL cache for populate task enabled.');
            DB::alteration_message('Checking to see if an SQL file already exists.');

            $cacheFileExists = self::cacheFileExists();
        }

        if ($cacheEnabled && $cacheFileExists && !$ignoreCache) {
            self::populateFromCache();
        } else {
            if ($cacheFileExists && $ignoreCache) {
                DB::alteration_message('Task has been configured to ignore cached SQL file.');
            }

            self::populateFromYmlConfig();
        }

        if ($cacheEnabled && !$ignoreCache && !$cacheFileExists) {
            DB::alteration_message('No populate cache file found.');

            if ($cacheLocation) {
                self::generatePopulateCacheFile($cacheLocation);
            } else {
                DB::alteration_message('No cache file directory has been set. Populate cache file has not been created');
            }
        } else if ($cacheEnabled) {
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
        $hashFiles = self::config()->get('cache_hash_files');
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

    /**
     * Get the file path for a cache file based on configured `cache_files_location` and generated populate hash
     * If `cache_files_location` starts with a / the file location is relative to the server root.
     * If `cache_files_location` does not start with a / the location is relative to the sites base dir.
     */
    private static function getCacheFilePath(): string
    {
        $cacheLocation = self::config()->get('cache_files_location');
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

        return $populateCacheFile;
    }

    /**
     * Execute mysql command to import database from cache file.
     */
    private static function populateFromCache(): void
    {
        $populateCacheFilePath = self::getCacheFilePath();

        DB::alteration_message('Populating DB from cached SQL file.');
        $execCommand = sprintf(
            "mysql --user=%s --password=%s %s < %s",
            Environment::getEnv('SS_DATABASE_USERNAME'),
            Environment::getEnv('SS_DATABASE_PASSWORD'),
            Environment::getEnv('SS_DATABASE_NAME'),
            $populateCacheFilePath
        );

        exec($execCommand, $output);
        DB::alteration_message('Populate data imported using cached SQL file.');
    }

    /**
     * Populate database based on the yml configuration
     */
    private static function populateFromYmlConfig(): void
    {
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

    private static function generatePopulateCacheFile(string $cacheLocation)
    {
        $populateHash = self::getPopulateHash();

        if (!file_exists($cacheLocation)) {
            DB::alteration_message(
                sprintf('Cache directory does not exist. Creating directory at `%s`.',$cacheLocation)
            );
            mkdir($cacheLocation);
        }

        DB::alteration_message('Running `mysqldump` to create cache file.');
        $execCommand = sprintf(
            "mysqldump --user=%s --password=%s --host=%s %s --result-file=%s 2>&1",
            Environment::getEnv('SS_DATABASE_USERNAME'),
            Environment::getEnv('SS_DATABASE_PASSWORD'),
            Environment::getEnv('SS_DATABASE_SERVER'),
            Environment::getEnv('SS_DATABASE_NAME'),
            sprintf('%s/%s.sql', $cacheLocation, $populateHash)
        );

        DB::alteration_message($execCommand);

        exec($execCommand, $output);

        var_dump($output);
    }

    /**
     * Returns true if a cache file exists.
     * If the overrideCache GET var is set, the existing cache file will be deleted and the function will return false
     */
    private static function cacheFileExists(): bool
    {
        $controller = Controller::curr();
        $request = $controller->getRequest();
        $overrideCache = $request->getVar('overrideCache');

        $populateCacheFilePath = self::getCacheFilePath();
        $cacheFileExists = file_exists($populateCacheFilePath);

        if ($cacheFileExists) {
            DB::alteration_message('Cache file located.');

            if ($overrideCache) {
                DB::alteration_message(
                    sprintf('`cacheOverride` variable has been set. Deleting file `%s`', $populateCacheFilePath)
                );

                unlink($populateCacheFilePath);
                return false;
            }
        }

        return $cacheFileExists;
    }
}
