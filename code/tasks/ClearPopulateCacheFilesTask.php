<?php

namespace DNADesign\Populate\tasks;

use DNADesign\Populate\Populate;
use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

class ClearPopulateCacheFilesTask extends BuildTask
{

    private static string $segment = 'clear-populate-cache-files';

    protected $title = 'Clear populate cache files'; // phpcs:ignore SlevomatCodingStandard.TypeHints

    private static ?bool $is_enabled = false;

    /**
     * @inheritDoc
     */
    public function getDescription()
    {
        return 'Clear SQL files that may be generated when the populate task is run.
        This can be destructive if not configured correctly so this task is disabled by default.';
    }

    /**
     * @param HTTPRequest $request
     * @throws Exception
     * @inheritDoc
     */
    public function run($request)
    {
        // Populate (by default) is allowed to run on dev and test environments
        if (!Director::isDev() && !Director::isTest()) {
            throw new Exception('this task can only be run in development or test environments');
        }

        $cachePath = $request->getVar('cache_path');

        if (!$cachePath) {
            throw new Exception(
                "This is a destructive dev task.
                Running this task will clear all sql files from the configured cache directory.
                To execute this task, a cache_path variable must be set. This variable must match the
                `populate_cache_files_location` value specified in your populate config.
                "
            );
        }

        $populate = Injector::inst()->get(Populate::class);
        $ConfiguredCacheLocation = $populate::config()->get('populate_cache_files_location');

        if (!$ConfiguredCacheLocation) {
            throw new Exception(
                "`populate_cache_files_location` is not defined in config. Unable to clear unconfigured cache files."
            );
        }

        if ($cachePath !== $ConfiguredCacheLocation) {
            throw new Exception(
                "`cache_path` variable does not match `populate_cache_files_location`
                 as defined in your populate config."
            );
        }

        if (!file_exists($cachePath)) {
            throw new Exception(
                "`cache_path` directory does not exist."
            );
        }

        $files = glob($cachePath . '/*.sql');

        if (!$files) {
            throw new Exception('`cache_path` directory does not contain any sql files.');
        }

        $this->log(sprintf('%s sql files located in `%s`. These files will be deleted.', count($files), $cachePath));

        foreach ($files as $file) {
            if (unlink($file)) {
                $this->log(sprintf('`%s` was successfully deleted.', $file));
            } else {
                $this->log(sprintf('Error: Unable to delete `%s`', $file));
            }
        }
    }

    protected function log(string $message): void
    {
        if (Director::is_cli()) {
            echo $message . PHP_EOL;
        } else {
            echo $message . '<br>';
        }
    }

}
