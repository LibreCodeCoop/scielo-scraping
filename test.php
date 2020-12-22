<?php

use ScieloScrapping\ScieloClient;

require 'vendor/autoload.php';

$client = new ScieloClient([
    'journal_slug' => 'csp'
]);
// $client->saveAllMetadata('output');
$client->getIssue(2020, 36, 1);
$client->downloadAllBinaries(2020, 36, 1);