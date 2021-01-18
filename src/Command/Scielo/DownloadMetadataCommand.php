<?php

namespace ScieloScrapping\Command\Scielo;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadMetadataCommand extends BaseCommand
{
    protected static $defaultName = 'scielo:download-metadata';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->setup($input, $output) == Command::FAILURE) {
            return Command::FAILURE;
        }
        $progressBar = new ProgressBar($output, count($this->issues));
        $progressBar->start();
        $grid = $this->scieloClient->getGrid();
        foreach ($this->years as $year) {
            foreach ($this->volumes as $volume) {
                if (!isset($grid[$year][$volume])) {
                    continue;
                }
                foreach ($grid[$year][$volume] as $issueName => $data) {
                    if ($this->issues && !in_array($issueName, $this->issues)) {
                        continue;
                    }
                    $this->scieloClient->getIssue($year, $volume, $issueName, $this->articleId);
                    $progressBar->advance();
                }
            }
        }
        $progressBar->finish();
        $output->writeln('');
        return Command::SUCCESS;
    }
}
