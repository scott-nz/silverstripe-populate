<?php

namespace DNADesign\Populate;

use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;

class PopulateTask extends BuildTask
{
    private static string $segment = 'PopulateTask';

    protected $description = "
        Truncate the database and populate new records based on a yml configuration.

        Parameters:
        - ignoreCache: If this parameter is set, cached SQL files will be ignored.
        - overrideCache: If this parameter is set and a matching cache file exists, " .
    "it will be deleted and a new cache file will be generated after the Populate task is completed.";

    /**
     * @param HTTPRequest $request
     * @throws Exception
     */
    public function run($request)
    {
        Populate::requireRecords();
    }
}
