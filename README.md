![master](https://github.com/lyseontech/scielo-scraping/workflows/CI/badge.svg?branch=master)

# Scielo Scraping

Run web scraping in a specific SciELO journal and download all publications.

## Install

To install with composer:

```sh
composer require lyseontech/scielo-scraping
```

## How to use?

Run the follow command to see commands list:

```bash
php bin/scielo
```

The main command:
```
php bin/scielo scielo:download-all --help
Usage:
  scielo:download-all [options] [--] <slug>

Arguments:
  slug                     Slug of journal

Options:
      --year[=YEAR]        Year of journal (multiple values allowed)
      --volume[=VOLUME]    Volume number (multiple values allowed)
      --issue[=ISSUE]      Issue name (multiple values allowed)
      --article[=ARTICLE]  Article name
      --output[=OUTPUT]    Output directory [default: "output"]
      --assets[=ASSETS]    Assets directory [default: "assets"]

```

All commands:

```
scielo:download-all
scielo:download-binary
scielo:download-metadata
```
