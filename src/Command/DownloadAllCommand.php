<?php

namespace ScieloScrapping\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadAllCommand extends BaseCommand
{
    protected static $defaultName = 'scielo:download-all';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->setup($input, $output) == Command::FAILURE) {
            return Command::FAILURE;
        }
        $progressBar = new ProgressBar($output, count($this->issues) * 3);
        $progressBar->start();
        $grid = $this->scieloClient->getGrid();
        foreach (['pt_BR', 'en', 'es'] as $lang) {
            $this->scieloClient->setLanguage($lang);
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
                        $this->scieloClient->downloadAllBinaries($year, $volume, $issueName, $this->articleId);
                        $progressBar->advance();
                    }
                }
            }
        }
        $progressBar->finish();
        $output->writeln('');
        return Command::SUCCESS;
    }
}
