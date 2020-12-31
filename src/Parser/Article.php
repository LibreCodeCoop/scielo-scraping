<?php

namespace ScieloScrapping\Parser;

use BadMethodCallException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Rogervila\ArrayDiffMultidimensional;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

class Article
{
    private $outputDir;
    private $metadataFilename;
    private $binaryDirectory;
    private $originalFileRaw;
    private $originalFileArray = [];
    private $data = [
        'id' => null,
        'doi' => null,
        'year' => null,
        'volume' => null,
        'issueName' => null,
        'category' => null,
        'updated' => null,
        'published' => null,
        'keywords' => [],
        'resume' => [],
        'title' => [],
        'formats' => [],
        'authors' => []
    ];

    private $settings = [
        'base_directory' => 'output'
    ];

    public function __construct(array $settings)
    {
        if (isset($settings['logger'])) {
            $this->logger = $settings['logger'];
            unset($settings['logger']);
        } else {
            $this->logger = new Logger('SCIELO');
            $this->logger->pushHandler(new StreamHandler('logs/scielo.log', Logger::DEBUG));
        }

        if (isset($settings['browser'])) {
            $this->browser = $settings['browser'];
            unset($settings['browser']);
        } else {
            $this->browser = new HttpBrowser(HttpClient::create());
        }

        $this->settings = array_merge($this->settings, $settings);
    }

    public function getAllData(): array
    {
        return $this->data;
    }

    public function __call(string $name, $arguments)
    {
        preg_match('/(?P<action>set|get)(?P<property>.*)/', $name, $matches);
        if (!isset($matches['property']) || !isset($matches['action'])) {
            throw new BadMethodCallException('Invalid method: ' . $name);
        }
        $property = $matches['property'];
        $property = strtolower($property[0]) . substr($property, 1);
        if (!array_key_exists($property, $this->data)) {
            throw new BadMethodCallException('No such method: ' . $name);
        }
        if (isset($arguments[1])) {
            if ($matches['action'] == 'set') {
                $this->data[$property][$arguments[1]] = $arguments[0];
                return $this;
            }
            return $this->data[$property][$arguments[1]];
        }
        if ($matches['action'] == 'set') {
            $this->data[$property] = $arguments[0];
            return $this;
        }
        if (isset($arguments[0])) {
            if (isset($this->data[$property][$arguments[0]])) {
                return $this->data[$property][$arguments[0]];
            }
            return;
        }
        return $this->data[$property];
    }

    public function load(string $year, string $volume, string $issueName, string $articleId, string $doi)
    {
        if (!$doi) {
            $this->logger->error(
                'DOI not found',
                [
                    'year' => $year,
                    'volume' => $volume,
                    'issueName' => $issueName,
                    'articleId' => $articleId,
                    'doi' => $doi,
                    'method' => 'Article::load'
                ]
            );
            throw new \Exception('DOI not found', 1);
        }
        $this->setYear($year);
        $this->setVolume($volume);
        $this->setIssueName($issueName);
        $this->setId($articleId);
        $outputDir = $this->getBasedir();
        if (!is_dir($outputDir)) {
            return false;
        }
        $this->setDoi($doi);
        $jsonFile = $outputDir . DIRECTORY_SEPARATOR . $this->getMetadataFilename();
        if (!file_exists($jsonFile)) {
            return false;
        }
        return $this->loadFromFile($jsonFile);
    }

    public function loadFromFile(string $filename)
    {
        if (!file_exists($filename)) {
            return false;
        }
        $this->originalFileRaw = file_get_contents($filename);
        if (!$this->originalFileRaw) {
            return false;
        }
        $this->originalFileArray = json_decode($this->originalFileRaw, true);
        if (!$this->originalFileArray) {
            $this->logger->error('Invalid metadata content', [
                'filename' => $filename,
                'method' => 'Article::loadFromFile'
            ]);
            throw new \Exception('Invalid metadata content', 1);
        }
        foreach ($this->originalFileArray as $property => $data) {
            if (array_key_exists($property, $this->data)) {
                $this->{'set' . strtoupper($property[0]) . substr($property, 1)}($data);
            }
        }
        return $this;
    }

    public function save()
    {
        $diff = ArrayDiffMultidimensional::compare($this->originalFileArray, $this->getAllData());
        if (!$diff) {
            return;
        }
        $outputDir = $this->getBasedir();
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0666, true);
        }
        $filename = $this->getMetadataFilename();
        file_put_contents(
            $outputDir . DIRECTORY_SEPARATOR . $filename,
            json_encode($this->data)
        );
    }

    public function getMetadataFilename()
    {
        if ($this->metadataFilename) {
            return $this->metadataFilename;
        }
        $this->metadataFilename = 'metadata_' . $this->getEndOfDoi() . '.json';
        return $this->metadataFilename;
    }

    public function getBinaryDirectory()
    {
        if ($this->binaryDirectory) {
            return $this->binaryDirectory;
        }
        $this->binaryDirectory = $this->getEndOfDoi();
        return $this->binaryDirectory;
    }

    private function getEndOfDoi()
    {
        $doi = $this->getDoi();
        if (!$doi) {
            $this->logger->error('DOI is required', [
                'data' => $this->data,
                'method' => 'Article::getEndOfDoi'
            ]);
            throw new \Exception('DOI is required', 1);
        }
        $array = explode('/', $doi);
        if (isset($array[1])) {
            return $array[1];
        }
        $this->logger->error('DOI incomplete', [
            'basedir' => $this->getBasedir(),
            'method' => 'Article::getEndOfDoi'
        ]);
        return $array[0];
    }

    public function getBasedir()
    {
        if ($this->outputDir) {
            return $this->outputDir;
        }
        $filtered = array_filter($this->data, fn($v) => $v !== null);
        $total = array_reduce(
            ['year', 'volume', 'issueName', 'id'],
            fn($c, $i) => $c += isset($filtered[$i]) ? 1 : 0
        );
        if ($total != 4) {
            $this->logger->error('Required elements to generate filename not found', [
                'data' => $this->data,
                'method' => 'Article::getFilename'
            ]);
            return;
        }
        $this->outputDir = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->settings['base_directory'],
                $this->data['year'],
                $this->data['volume'],
                $this->data['issueName'],
                $this->data['id']
            ]
        );
        return $this->outputDir;
    }
}
