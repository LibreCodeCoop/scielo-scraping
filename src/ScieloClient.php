<?php
namespace ScieloScrapping;

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
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
    private $header;
    private $footer;
    /**
     * Languages
     *
     * @var array
     */
    private $langs = [
        'pt' => 'pt_BR',
        'es' => 'es_ES',
        'en' => 'en_US'
    ];
    private $settings = [
        'journal_slug' => null,
        'base_directory' => 'output',
        'base_url' => 'https://www.scielosp.org',
        'assets_folder' => 'assets'
    ];
    public function __construct(array $settings = [])
    {
        $this->settings = array_merge($this->settings, $settings);
        $this->browser = new HttpBrowser(HttpClient::create());
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
        $crawler->filter('#issueList table tbody tr')->each(function($tr) use (&$grid) {
            $td = $tr->filter('td');

            $links = [];
            $td->last()->filter('.btn')->each(function($linkNode) use (&$links) {
                $link = $linkNode->link();
                $url = $link->getUri();
                $tokens = explode('/', $url);
                $issueCode = $tokens[sizeof($tokens)-2];
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

    public function saveAllMetadata($selectedYears = [], $selectedVolumes = [], $selectedIssues = [])
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

    public function downloadAllBinaries($year = '*', $volume = '*', $issue = '*', $articleId = '*')
    {
        if (!$this->settings['base_directory'] || !is_dir($this->settings['base_directory'])) {
            return;
        }
        try {
            $finder = Finder::create()
                ->files()
                ->name('metadata.json')
                ->in(implode(DIRECTORY_SEPARATOR, [$this->settings['base_directory'], $year, $volume, $issue, $articleId]));
        } catch (\Throwable $th) {
            return;
        }
        foreach($finder as $file) {
            $article = file_get_contents($file->getRealPath());
            $article = json_decode($article);
            if (!$article) {
                continue;
            }
            foreach ($article->formats as $format => $data) {
                foreach ($data as $lang => $url) {
                    $path = dirname($file->getRealPath());
                    if (!is_dir($path)) {
                        mkdir($path, 0666, true);
                    }
                    switch ($format) {
                        case 'html':
                            $article = $this->getAllArcileData($url, $path, $article, $lang);
                            file_put_contents($file->getRealPath(), json_encode($article));
                            break;
                        case 'pdf':
                            $this->downloadBinaryAssync(
                                $url,
                                $path. DIRECTORY_SEPARATOR . $lang . '.pdf'
                            );
                            break;
                    }
                }
            }
        }
    }

    public function getIssue($year, $volume, $issueName, $articleId = null)
    {
        $grid = $this->getGrid();

        try {
            $finder = Finder::create()
                ->files()
                ->name('metadata.json')
                ->in(implode(DIRECTORY_SEPARATOR, [$this->settings['base_directory'], $year, $volume, $issueName, $articleId]));
            return;
        } catch (DirectoryNotFoundException $th) {
        }
        $crawler = $this->browser->request('GET', $grid[$year][$volume][$issueName]['url']);
        $articles = [];
        $crawler->filter('.articles>li')->each(function($article) use ($year, $volume, $issueName, $articleId, &$articles) {
            $id = $this->getArticleId($article);
            if ($articleId && $articleId != $id) {
                return;
            }
            foreach($article->filter('h2')->first() as $nodeElement) {
                $title = trim($nodeElement->childNodes->item(0)->data);
            }

            $textPdf = $this->getTextPdfUrl($article, $id);

            $return = [
                'id' => $id,
                'year' => $year,
                'volume' => $volume,
                'issueName' => $issueName,
                'title' => $title,
                'category' => strtolower($article->filter('h2 span')->text('article')) ?: 'article',
                'resume' => $this->getResume($article),
                'formats' => [
                    'html' => $textPdf['text'] ?: null,
                    'pdf' => $textPdf['pdf'] ?: null
                ],
                'authors' => $article->filter('a[href*="//search"]')->each(function($a) {
                    return ['name' => $a->text()];
                })
            ];

            $date = $article->attr('data-date');
            switch (strlen($date)) {
                case 4:
                    $return['date'] = $article->attr('data-date');
                    break;
                default:
                    $return['date'] = \DateTime::createFromFormat('Ymd', $article->attr('data-date'))->format('Y-m-d');
            }
            $articles[] = $return;
        });

        foreach ($articles as $article) {
            $outputDir = implode(
                DIRECTORY_SEPARATOR, 
                [$this->settings['base_directory'], $year, $volume, $issueName, $article['id']]
            );
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0666, true);
            }
            file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($article));
        }
        return $articles;
    }

    private function getTextPdfUrl($article)
    {
        $return = [];
        $article->filter('ul.links li')->each(function($li) use (&$return) {
            $prefix = substr($li->text(), 0, 3);
            $prefixList = [
                'Tex' => 'text',
                'PDF' => 'pdf'
            ];
            if (!isset($prefixList[$prefix])) {
                return;
            }
            $type = $prefixList[$prefix];
            $li->filter('a')->each(function($a) use (&$return, $type) {
                $return[$type][$this->langs[$a->text()]] = $a->attr('href');
            });
        });
        return $return;
    }

    private function getAllArcileData($url, $path, $article, $lang)
    {
        if (file_exists($path . DIRECTORY_SEPARATOR . $lang . '.html')) {
            $crawler = new Crawler(file_get_contents($path . DIRECTORY_SEPARATOR . $lang . '.raw.html'));
        } else {
            $crawler = $this->browser->request('GET', $this->settings['base_url'] . $url);
            file_put_contents($path . DIRECTORY_SEPARATOR . $lang . '.raw.html', $crawler->outerHtml());
        }
        if (!file_exists($path . DIRECTORY_SEPARATOR . $lang. '.html')) {
            $selectors = [
                '#standalonearticle'
            ];
            $html = '';
            foreach($selectors as $selector) {
                try {
                    $html.= $crawler->filter($selector)->outerHtml();
                } catch (\Throwable $th) {}
            }
            $html =
                $this->getHeader() .
                $this->formatHtml($html) .
                $this->getFooter();
            file_put_contents($path . DIRECTORY_SEPARATOR . $lang. '.html', $html);
        }
        $this->getAllAssets($crawler, $path);
        return $this->getArticleMetadata($crawler, $article);
    }

    private function formatHtml($html)
    {
        return preg_replace('/\/media\/assets\/csp\S+\/([\da-z-.]+)/i', '$1', $html);
        return $html;
    }

    private function getHeader()
    {
        if ($this->header) {
            return $this->header;
        }
        $this->header = file_get_contents($this->settings['assets_folder'] . DIRECTORY_SEPARATOR .'/header.html');
        return $this->header;
    }

    private function getFooter()
    {
        if ($this->footer) {
            return $this->footer;
        }
        $this->footer = file_get_contents($this->settings['assets_folder'] . DIRECTORY_SEPARATOR .'/footer.html');
        return $this->footer;
    }

    private function getAllAssets(Crawler $crawler, $path)
    {
        if (!is_dir($path)) {
            mkdir($path);
        }
        $crawler->filter('.modal-body img')->each(function($img) use($path) {
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
     * @param Crawler $article
     * @return array
     */
    private function getArticleMetadata(Crawler $crawler, $article)
    {
        $article->doi = $crawler->filter('meta[name="citation_doi"]')->attr('content');
        $article->title = $crawler->filter('meta[name="citation_title"]')->attr('content');
        $article->publication_date = $crawler->filter('meta[name="citation_publication_date"]')->attr('content');
        $article->keywords = $crawler->filter('meta[name="citation_keywords"]')->each(function($meta) {
            return $meta->attr('content');
        });
        $authors = $crawler->filter('.contribGroup span[class="dropdown"]')->each(function($node) {
            $return = [];
            $name = $node->filter('[id*="contribGroupTutor"] span');
            if ($name->count()) {
                $return['name'] = $name->text();
            }
            $orcid = $node->filter('[class*="orcid"]');
            if ($orcid->count()) {
                $return['orcid'] = $orcid->attr('href');
            }
            foreach($node->filter('ul') as $nodeElement) {
                if ($nodeElement->childNodes->count() <= 1) {
                    continue;
                }
                $text = trim(preg_replace('!\s+!', ' ', $nodeElement->childNodes->item(1)->nodeValue));
                switch($text) {
                    case '†':
                        $return['decreased'] = 'decreased';
                        break;
                    default:
                        $return['foundation'] = $text;
                }
            }
            return $return;
        });
        if ($authors) {
            $article->authors = (object)$authors;
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
                function($chunk, AsyncContext $context) use ($fileHandler) {
                    if ($chunk->isLast()) {
                        yield $chunk;
                    };
                    fwrite($fileHandler, $chunk->getContent());
                }
            );
        } catch (\Throwable $th) {
        }
    }

    private function getResume($article)
    {
        $return = [];
        $article->filter('div[data-toggle="tooltip"]')->each(function($div) use (&$return) {
            $lang = $this->langs[substr($div->attr('id'), -2)];
            foreach($div as $nodeElement){
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

    private function getArticleId($article)
    {
        $id = $article->filter('ul.links li a[href^="/article/"]')->first()->attr('href');
        return explode('/', $id)[4];
    }
}