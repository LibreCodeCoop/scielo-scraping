<?php

namespace ScieloScrapping;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ScieloScrapping\Parser\Article;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;

class ScieloClient
{
    /**
     * http browser
     *
     * @var HttpBrowser
     *
     */
    private $browser;
    /**
     * All data of grid
     *
     * @var array
     */
    private $grid = [];
    private $template;
    /**
     * Logger
     *
     * @var Logger
     */
    private $logger;
    /**
     * Current language
     *
     * @var string
     */
    private $lang;
    /**
     * Languages
     *
     * @var array
     */
    private $langs = [
        'pt_BR' => 'pt_BR',
        'pt-BR' => 'pt_BR',
        'pt' => 'pt_BR',
        'es' => 'es_ES',
        'en' => 'en_US'
    ];
    private $settings = [
        'journal_slug' => null,
        'base_directory' => 'output',
        'base_url' => 'https://www.scielosp.org',
        'assets_folder' => 'assets',
        'logger' => null,
        'browser' => null,
        'default_language' => 'pt_BR'
    ];
    public function __construct(array $settings = [])
    {
        $this->settings = array_merge($this->settings, $settings);
        if ($this->settings['browser']) {
            $this->browser = $this->settings['browser'];
        } else {
            $this->browser = new HttpBrowser(HttpClient::create());
        }
        if ($this->settings['logger']) {
            $this->logger = $this->settings['logger'];
        } else {
            $this->logger = new Logger('SCIELO');
            $this->logger->pushHandler(new StreamHandler('logs/scielo.log', Logger::DEBUG));
        }
    }

    private function getGridUrl()
    {
        return $this->settings['base_url'] . '/j/' . $this->settings['journal_slug'] . '/grid';
    }

    public function getGrid()
    {
        if ($this->grid) {
            return $this->grid;
        }
        if (file_exists($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json')) {
            $grid = file_get_contents($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json');
            $grid = json_decode($grid, true);
            if ($grid) {
                return $this->grid = $grid;
            }
        }
        $crawler = $this->browser->request('GET', $this->getGridUrl());
        $grid = [];
        $crawler->filter('#issueList table tbody tr')->each(function ($tr) use (&$grid) {
            $td = $tr->filter('td');

            $links = [];
            $td->last()->filter('.btn')->each(function ($linkNode) use (&$links) {
                $link = $linkNode->link();
                $url = $link->getUri();
                $tokens = explode('/', $url);
                $issueCode = $tokens[sizeof($tokens) - 2];
                $links[$issueCode] = [
                    'text' => $linkNode->text(),
                    'url' => $url
                ];
            });

            $grid[$td->first()->text()] = [
                $tr->filter('th')->text() => $links
            ];
        });
        if (!is_dir($this->settings['base_directory'])) {
            mkdir($this->settings['base_directory'], 0666);
        }
        file_put_contents($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json', json_encode($grid));
        $this->grid = $grid;
        return $grid;
    }

    public function saveAllMetadata(array $selectedYears = [], array $selectedVolumes = [], array $selectedIssues = [])
    {
        $grid = $this->getGrid();
        foreach ($selectedYears as $year) {
            foreach ($selectedVolumes as $volume) {
                if (!isset($grid[$year][$volume])) {
                    continue;
                }
                foreach ($grid[$year][$volume] as $issueName => $data) {
                    if ($selectedIssues && !in_array($issueName, $selectedIssues)) {
                        continue;
                    }
                    $this->getIssue($year, $volume, $issueName);
                }
            }
        }
    }

    public function downloadAllBinaries(?string $year = '*', ?string $volume = '*', ?string $issue = '*', ?string $articleId = '*')
    {
        if (!$this->settings['base_directory'] || !is_dir($this->settings['base_directory'])) {
            return;
        }
        try {
            $finder = Finder::create()
                ->files()
                ->name('metadata_*.json')
                ->in(implode(DIRECTORY_SEPARATOR, [$this->settings['base_directory'], $year, $volume, $issue, $articleId]));
        } catch (\Throwable $th) {
            return;
        }
        foreach ($finder as $file) {
            $article = new Article([
                'base_directory' => $this->settings['base_directory'],
                'logger' => $this->logger
            ]);
            $article->loadFromFile($file->getRealPath());
            $this->downloadBinaries($article, dirname($file->getRealPath()));
        }
    }

    private function downloadBinaries(Article $article, string $basedir)
    {
        foreach ($article->getFormats() as $format => $data) {
            foreach ($data as $lang => $url) {
                $path = implode(
                    DIRECTORY_SEPARATOR,
                    [$basedir, $article->getBinaryDirectory()]
                );
                if (!is_dir($path)) {
                    mkdir($path, 0666, true);
                }
                switch ($format) {
                    case 'text':
                        $this->getAllArcileData($url, $path, $article, $lang);
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

    private function getFeedUrl(string $url)
    {
        return preg_replace(['/j/', '/\/i/'], ['feed', ''], $url);
    }

    public function setLanguage(string $lang)
    {
        $this->browser->request('GET', $this->settings['base_url'] . '/set_locale/' . $lang);
        $this->lang = $this->langs[$lang];
    }

    public function getIssue(string $year, string $volume, string $issueName, $articleId = null)
    {
        $grid = $this->getGrid();

        $htmlUrl = $grid[$year][$volume][$issueName]['url'];
        $this->getIssueFromHtml($htmlUrl, $year, $volume, $issueName, $articleId);
    }

    /**
     * Get crawler of issue
     *
     * @param string $url
     * @param string $year
     * @param string $volume
     * @param string $issueName
     * @param string $filename
     * @return [Crawler,Crawler]
     */
    private function getIssueCrawlers(string $url, string $year, string $volume, string $issueName)
    {
        $basepath = implode(
            DIRECTORY_SEPARATOR,
            [$this->settings['base_directory'], $year, $volume, $issueName]
        );
        if (!is_dir($basepath)) {
            mkdir($basepath, 0666, true);
        }
        $htmlFile = $basepath . DIRECTORY_SEPARATOR . $this->lang . '.html';
        if (file_exists($htmlFile)) {
            $crawler['html'] = new Crawler(file_get_contents($htmlFile));
        } else {
            $crawler['html'] = $this->browser->request('GET', $url);
            $fileLang = $this->langs[$crawler['html']->filter('html')->attr('lang')];
            if ($this->lang == $fileLang) {
                file_put_contents($htmlFile, $crawler['html']->outerHtml());
            } else {
                $this->browser->request('GET', $this->settings['base_url'] . '/set_locale/' . substr($this->lang, 0, 2));
                return $this->getIssueCrawlers($url, $year, $volume, $issueName);
            }
        }
        $xmlFile = $basepath . DIRECTORY_SEPARATOR . 'issue.xml';
        if (file_exists($xmlFile)) {
            $crawler['xml'] = new Crawler(file_get_contents($xmlFile));
        } else {
            $crawler['xml'] = $this->browser->request('GET', $this->getFeedUrl($url));
            file_put_contents($xmlFile, $crawler['xml']->outerHtml());
        }
        return $crawler;
    }

    private function getIssueFromHtml(string $url, string $year, string $volume, string $issueName, $articleId = null)
    {
        $crawlers = $this->getIssueCrawlers($url, $year, $volume, $issueName);
        $crawlers['html']->filter('.articles>li')
            ->each(function (Crawler $node, $index) use ($year, $volume, $issueName, $articleId, $crawlers) {
                $id = $this->getArticleId($node);
                if ($articleId && $articleId != $id) {
                    return;
                }
                $article = new Article([
                    'base_directory' => $this->settings['base_directory'],
                    'logger' => $this->logger
                ]);
                $doi = $crawlers['xml']->filter('entry')->eq($index)->filter('id')->text();
                $article->load($year, $volume, $issueName, $id, $doi);
                foreach ($node->filter('h2')->first() as $nodeElement) {
                    $title = trim($nodeElement->childNodes->item(0)->data);
                }
                $article->setId($id);
                $article->setDoi($doi);
                $article->setYear($year);
                $article->setVolume($volume);
                $article->setIssueName($issueName);
                $article->setTitle($title, $this->lang);
                $article->setCategory(strtolower($node->filter('h2 span')->text('article')) ?: 'article');
                $article->setResume($this->getResume($node));
                $article->setFormats($this->getTextPdfUrl($node));
                $article->setAuthors($node->filter('a[href*="//search"]')->each(fn($a) => ['name' => $a->text()]));

                $published = $crawlers['xml']->filter('entry')->eq($index)->filter('updated')->text();
                $article->setPublished((\DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $published))->format('Y-m-d H:i:s'));
                $updated = $crawlers['xml']->filter('entry')->eq($index)->filter('updated')->text();
                $article->setUpdated((\DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $updated))->format('Y-m-d H:i:s'));

                $article->save();
            });
    }

    private function getTextPdfUrl(Crawler $node)
    {
        $return = [];
        $node->filter('ul.links li')->each(function ($li) use (&$return) {
            $prefix = substr($li->text(), 0, 3);
            $prefixList = [
                'Tex' => 'text',
                'PDF' => 'pdf'
            ];
            if (!isset($prefixList[$prefix])) {
                return;
            }
            $type = $prefixList[$prefix];
            $li->filter('a')->each(function ($a) use (&$return, $type) {
                $lang = $a->text();
                if (isset($this->langs[$lang])) {
                    $lang = $this->langs[$lang];
                }
                $return[$type][$lang] = $a->attr('href');
            });
        });
        return $return;
    }

    private function getAllArcileData(string $url, string $path, Article $article, string $lang)
    {
        if (file_exists($path . DIRECTORY_SEPARATOR . $lang . '.html')) {
            $crawler = new Crawler(file_get_contents($path . DIRECTORY_SEPARATOR . $lang . '.raw.html'));
            $this->getAllArcileDataCallback($path, $lang, $crawler, $article);
        } else {
            $crawler = $this->browser->request('GET', $this->settings['base_url'] . $url);
            if ($this->browser->getResponse()->getStatusCode() == 404) {
                $this->logger->error('404', ['url' => $url, 'path' => $path, 'lang' => $lang, 'method' => 'getAllArticleData']);
                return $article;
            }
            file_put_contents($path . DIRECTORY_SEPARATOR . $lang . '.raw.html', $crawler->outerHtml());
            $this->getAllArcileDataCallback($path, $lang, $crawler, $article);
        }
    }

    private function getAllArcileDataCallback(string $path, string $lang, Crawler $crawler, Article $article)
    {
        if (!file_exists($path . DIRECTORY_SEPARATOR . $lang . '.html')) {
            $selectors = [
                '#standalonearticle'
            ];
            $html = '';
            foreach ($selectors as $selector) {
                try {
                    $html .= $crawler->filter($selector)->outerHtml();
                } catch (\Throwable $th) {
                    $this->logger->error('Invalid selector', ['method' => 'getAllArcileData', 'selector' => $selector, 'article' => $article]);
                }
            }
            $html = str_replace('{{body}}', $this->formatHtml($html), $this->getTemplate());
            file_put_contents($path . DIRECTORY_SEPARATOR . $lang . '.html', $html);
        }
        $this->getAllAssets($crawler, $path);
        $article = $this->getArticleMetadata($crawler, $article, $lang);
        $article->save();
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

    private function getAllAssets(Crawler $crawler, $path)
    {
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

    /**
     * Get all article metadata
     *
     * @param Crawler $crawler
     * @param Article $article
     * @return Article
     */
    private function getArticleMetadata(Crawler $crawler, Article $article, string $currentLang)
    {
        if (!$article->getDoi()) {
            $nodes = $crawler->filter('meta[name="citation_doi"]');
            if ($nodes->count()) {
                $article->setDoi($nodes->attr('content'));
            } else {
                $this->logger->error('Without DOI', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $article->getBasedir()
                ]);
            }
        }

        if (!$article->getTitle($currentLang)) {
            $nodes = $crawler->filter('meta[name="citation_title"]');
            if ($nodes->count()) {
                $article->setTitle($nodes->attr('content'), $currentLang);
            } else {
                $this->logger->error('Without Title', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $article->getBasedir()
                ]);
            }
        }
        if (!$article->getPublished()) {
            $nodes = $crawler->filter('meta[name="citation_publication_date"]');
            if ($nodes->count()) {
                $article->setPublished($nodes->attr('content'));
            } else {
                $this->logger->error('Without publication_date', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $article->getBasedir()
                ]);
            }
        }
        if (!$article->getKeywords($currentLang)) {
            $nodes = $crawler->filter('meta[name="citation_keywords"]');
            if ($nodes->count()) {
                $article->setKeywords($nodes->each(fn($meta) => $meta->attr('content')), $currentLang);
            } else {
                $this->logger->error('Without keywords', [
                    'method' => 'ScieloClient::getArticleMetadata',
                    'directory' => $article->getBasedir()
                ]);
            }
        }
        $authors = $crawler->filter('.contribGroup span[class="dropdown"]')->each(function ($node) use ($article) {
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
                            'article' => $article->getBasedir()
                        ]);
                        break;
                    default:
                        $return['foundation'] = $text;
                }
            }
            return $return;
        });
        if ($authors) {
            $article->setAuthors($authors);
        }
        return $article;
    }

    private function downloadBinaryAssync($url, $destination)
    {
        if (file_exists($destination)) {
            return;
        }
        $fileHandler = fopen($destination, 'w');
        // $client = new HttplugClient();
        // $request = $client->createRequest('GET', $this->settings['base_url'] . $url);
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
                $this->settings['base_url'] . $url,
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

    private function getResume(Crawler $node)
    {
        $return = [];
        $node->filter('div[data-toggle="tooltip"]')->each(function ($div) use (&$return) {
            $lang = $this->langs[substr($div->attr('id'), -2)];
            foreach ($div as $nodeElement) {
                $resume = trim($nodeElement->childNodes->item(2)->data);
                $resume = preg_replace(
                    ['/^Resumo: /', '/^Resumen: /', '/^Abstract: /'],
                    [],
                    $resume
                );
            }
            $return[$lang] = $resume;
        });
        return $return;
    }

    private function getArticleId(Crawler $node)
    {
        $link = $node->filter('ul.links li a[href^="/article/"]');
        if ($link->count()) {
            $id = $link->first()->attr('href');
        } else {
            $link = $node->filter('ul.links li a[href^="/pdf/"]');
            if ($link->count()) {
                $id = $link->first()->attr('href');
            }
        }
        if (isset($id)) {
            return explode('/', $id)[4];
        }
        $this->logger->error('Article ID not found', ['article' => $node, 'method' => 'getArticleId']);
    }
}
