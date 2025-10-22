<?php

namespace DNADesign\Populate\tasks;

use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * @codeCoverageIgnore this is for development or for first time setup for client. Can stay in code for future need
 */
class ClearPopulateCacheFilesTask extends BuildTask
{

    private static string $segment = 'clear-populate-cache-files';

    protected $title = 'Clear populate cache files'; // phpcs:ignore SlevomatCodingStandard.TypeHints

    /**
     * @inheritDoc
     */
    public function getDescription()
    {
        return 'Clear SQL files that may be generated when the populate task is run';
    }

    /**
     * @param HTTPRequest $request
     * @throws Exception
     * @inheritDoc
     */
    public function run($request)
    {
        // Populate (by default) is allowed to run on dev and test environments
        if (Director::isDev() || Director::isTest()) {
            throw new Exception('this task can only be run in development or test environments');
        }
    }

}
