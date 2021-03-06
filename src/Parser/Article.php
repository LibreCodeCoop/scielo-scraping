<?php

namespace ScieloScrapping\Parser;

use ScieloScrapping\Service\ArticleService;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;

class Article extends ArticleService
{
    /**
     * http browser
     *
     * @var HttpBrowser
     *
     */
    private $browser;
    private $template;

    private $settings = [
        'assets_folder' => 'assets'
    ];

    public function __construct(array $settings)
    {
        parent::__construct($settings);

        if (isset($settings['browser'])) {
            $this->browser = $settings['browser'];
            unset($settings['browser']);
        }

        $this->settings = array_merge($this->settings, $settings);
    }

    private function getBrowser(): HttpBrowser
    {
        if (!$this->browser) {
            $this->browser = new HttpBrowser(HttpClient::create());
        }
        return $this->browser;
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
        $crawler = $this->getBrowser()->request('GET', $url);
        if ($this->getBrowser()->getResponse()->getStatusCode() == 404) {
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
        $protocol = $this->getBrowser()->getServerParameter('HTTPS') ? 'https' : 'http';
        $host = $this->getBrowser()->getServerParameter('HTTP_HOST');
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
}
