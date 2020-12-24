<?php

namespace ScieloScrapping\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadMetadataCommand extends BaseCommand
{
    protected static $defaultName = 'scielo:download-metadata';

    protected function configure()
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug of journal')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Year of journal')
            ->addOption('volume', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Volume number')
            ->addOption('issue', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Issue name')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output directory', 'output')
            ->addOption('assets', null, InputOption::VALUE_OPTIONAL, 'Assets directory', 'assets');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->setup($input, $output) == Command::FAILURE) {
            return Command::FAILURE;
        }
        $this->scieloClient->saveAllMetadata($this->years, $this->volumes, $this->issues);
        return Command::SUCCESS;
    }
}