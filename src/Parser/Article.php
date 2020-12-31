<?php

namespace ScieloScrapping\Parser;

use BadMethodCallException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Rogervila\ArrayDiffMultidimensional;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;

class Article
{
    /**
     * Logger
     *
     * @var Logger
     */
    private $logger;
    /**
     * http browser
     *
     * @var HttpBrowser
     *
     */
    private $browser;
    private $template;
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
        'base_directory' => 'output',
        'assets_folder' => 'assets'
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

    private function getAllData(): array
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

    private function save()
    {
        $diff = ArrayDiffMultidimensional::compare($this->originalFileArray, $this->getAllData());
        if ($this->originalFileArray && !$diff) {
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

    private function getMetadataFilename()
    {
        if ($this->metadataFilename) {
            return $this->metadataFilename;
        }
        $this->metadataFilename = 'metadata_' . $this->getEndOfDoi() . '.json';
        return $this->metadataFilename;
    }

    private function getBinaryDirectory()
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

    private function getBasedir()
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

    /**
     * Get all article metadata
     *
     * @param Crawler $crawler
     * @return Article
     */
    private function incrementMetadata(Crawler $crawler, string $currentLang)
    {
        if (!$this->getDoi()) {
            $nodes = $crawler->filter('meta[name="citation_doi"]');
            if ($nodes->count()) {
                $this->setDoi($nodes->attr('content'));
            } else {
                $this->logger->error('Without DOI', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $this->getBasedir()
                ]);
            }
        }

        if (!$this->getTitle($currentLang)) {
            $nodes = $crawler->filter('meta[name="citation_title"]');
            if ($nodes->count()) {
                $this->setTitle($nodes->attr('content'), $currentLang);
            } else {
                $this->logger->error('Without Title', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $this->getBasedir()
                ]);
            }
        }
        if (!$this->getPublished()) {
            $nodes = $crawler->filter('meta[name="citation_publication_date"]');
            if ($nodes->count()) {
                $this->setPublished($nodes->attr('content'));
            } else {
                $this->logger->error('Without publication_date', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $this->getBasedir()
                ]);
            }
        }
        if (!$this->getKeywords($currentLang)) {
            $nodes = $crawler->filter('meta[name="citation_keywords"]');
            if ($nodes->count()) {
                $this->setKeywords($nodes->each(fn($meta) => $meta->attr('content')), $currentLang);
            } else {
                $this->logger->error('Without keywords', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $this->getBasedir()
                ]);
            }
        }
        $authors = $crawler->filter('.contribGroup span[class="dropdown"]')->each(function ($node) {
            $return = [];
            $name = $node->filter('[id*="contribGroupTutor"] span');
            if ($name->count()) {
                $return['name'] = $name->text();
            }
            $orcid = $node->filter('[class*="orcid"]');
            if ($orcid->count()) {
                $return['orcid'] = $orcid->attr('href');
            }
            foreach ($node->filter('ul') as $nodeElement) {
                if ($nodeElement->childNodes->count() <= 1) {
                    continue;
                }
                $text = trim(preg_replace('!\s+!', ' ', $nodeElement->childNodes->item(1)->nodeValue));
                switch ($text) {
                    case '†':
                        $return['decreased'] = 'decreased';
                        $this->logger->error('Author decreased', [
                            'method' => 'ScieloClient::getArticleMetadata',
                            'article' => $this->getBasedir()
                        ]);
                        break;
                    default:
                        $return['foundation'] = $text;
                }
            }
            return $return;
        });
        if ($authors) {
            $this->setAuthors($authors);
        }
        return $this;
    }

    private function getRawCrawler(string $url, string $lang)
    {
        $rawFilename = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory(),
            $lang . '.raw.html'
        ]);
        if (file_exists($rawFilename)) {
            return new Crawler(file_get_contents($rawFilename));
        }
        $crawler = $this->browser->request('GET', $url);
        if ($this->browser->getResponse()->getStatusCode() == 404) {
            $this->logger->error('404', [
                'method' => 'Article::getRawCrawler',
                'article' => $this->getBasedir(),
                'url' => $url
            ]);
            return;
        }
        file_put_contents($rawFilename, $crawler->outerHtml());
        return $crawler;
    }

    private function getAllAssets(Crawler $crawler)
    {
        $path = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory()
        ]);
        if (!is_dir($path)) {
            mkdir($path);
        }
        $crawler->filter('.modal-body img')->each(function ($img) use ($path) {
            $src = $img->attr('src');
            $filename = $path . DIRECTORY_SEPARATOR . basename($src);
            if (file_exists($filename)) {
                return;
            }
            $this->downloadBinaryAssync($src, $filename);
        });
    }

    private function getBaseUrl()
    {
        $protocol = $this->browser->getServerParameter('HTTPS') ? 'https' : 'http';
        $host = $this->browser->getServerParameter('HTTP_HOST');
        return $protocol . '://' . $host;
    }

    public function downloadBinaryAssync($url, $destination)
    {
        if (file_exists($destination)) {
            return;
        }
        $fileHandler = fopen($destination, 'w');
        // $client = new HttplugClient();
        // $request = $client->createRequest('GET', $this->getBaseUrl() . $url);
        // $client->sendAsyncRequest($request)
        //     ->then(
        //         function (Response $response) use ($fileHandler) {
        //             fwrite($fileHandler, $response->getBody());
        //         }
        //     );

        try {
            new AsyncResponse(
                HttpClient::create(),
                'GET',
                $this->getBaseUrl() . $url,
                [],
                function ($chunk, AsyncContext $context) use ($fileHandler) {
                    if ($chunk->isLast()) {
                        yield $chunk;
                    };
                    fwrite($fileHandler, $chunk->getContent());
                }
            );
        } catch (\Throwable $th) {
            $this->logger->error('Invalid request on donload binary', ['method' => 'downloadBinaryAssync', 'url' => $url]);
        }
    }

    private function extractBody(Crawler $crawler, string $lang)
    {
        $bodyFilename = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory(),
            $lang . '.html'
        ]);
        if (file_exists($bodyFilename)) {
            return;
        }
        $selectors = [
            '#standalonearticle'
        ];
        $html = '';
        foreach ($selectors as $selector) {
            try {
                $html .= $crawler->filter($selector)->outerHtml();
            } catch (\Throwable $th) {
                $this->logger->error('Invalid selector', [
                    'method' => 'getAllArcileData',
                    'selector' => $selector,
                    'directory' => $this->getBasedir()
                ]);
            }
        }
        $html = str_replace('{{body}}', $this->formatHtml($html), $this->getTemplate());
        file_put_contents($bodyFilename, $html);
    }

    private function formatHtml(string $html)
    {
        return preg_replace('/\/media\/assets\/csp\S+\/([\da-z-.]+)/i', '$1', $html);
    }

    private function getTemplate()
    {
        if ($this->template) {
            return $this->template;
        }
        $this->template = file_get_contents($this->settings['assets_folder'] . DIRECTORY_SEPARATOR . '/template.html');
        return $this->template;
    }

    public function downloadBinaries()
    {
        foreach ($this->getFormats() as $format => $data) {
            foreach ($data as $lang => $url) {
                $path = $this->getBasedir() . DIRECTORY_SEPARATOR . $this->getBinaryDirectory();
                if (!is_dir($path)) {
                    mkdir($path, 0666, true);
                }
                switch ($format) {
                    case 'text':
                        $crawler = $this->getRawCrawler($url, $lang);
                        if (!$crawler) {
                            break;
                        }
                        $this->extractBody($crawler, $lang);
                        $this->getAllAssets($crawler);
                        $this->incrementMetadata($crawler, $lang);
                        break;
                    case 'pdf':
                        $this->downloadBinaryAssync(
                            $url,
                            $path . DIRECTORY_SEPARATOR . $lang . '.pdf'
                        );
                        break;
                }
            }
        }
    }

    public function __destruct()
    {
        $this->save();
    }
}
