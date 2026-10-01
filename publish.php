<?php

declare(strict_types=1);

use App\AutomationException;
use App\ConsoleLogger;
use App\CsvArticleReader;
use App\CsvResultWriter;
use App\TelegraphPublisher;

require __DIR__ . '/vendor/autoload.php';

useLocalTimezone();

if ($argc > 2) {
    fwrite(STDERR, "Використання: php publish.php [input.csv]\n");
    exit(1);
}

$inputPath = $argv[1] ?? __DIR__ . '/input/articles.csv';
$outputPath = __DIR__ . '/output/results.csv';

$logger = new ConsoleLogger();
$publisher = new TelegraphPublisher($logger);

try {
    $articles = (new CsvArticleReader())->read($inputPath);
} catch (AutomationException $exception) {
    $logger->error($exception->getMessage());
    exit(1);
}

if ($articles === []) {
    $logger->error('У вхідному CSV немає статей');
    exit(1);
}

$logger->info('Знайдено статей: ' . count($articles));

$writer = new CsvResultWriter();

try {
    $publishedUrls = $writer->publishedUrls($outputPath);
} catch (AutomationException $exception) {
    $logger->error($exception->getMessage());
    exit(1);
}

$published = 0;
$skipped = 0;
$failed = 0;
$total = count($articles);

try {
    foreach ($articles as $index => $article) {
        $position = $index + 1;
        $key = $writer->key($article->title, $article->author);

        if (isset($publishedUrls[$key])) {
            $logger->info("Стаття {$position}/{$total}: {$article->title} — вже опублікована, пропускаю");
            $logger->info($publishedUrls[$key]);
            $skipped++;
            continue;
        }

        $logger->info("Стаття {$position}/{$total}: {$article->title}");

        try {
            $url = $publisher->publish($article);
            $writer->append($outputPath, $article, $url, date('Y-m-d H:i:s'));
            $publishedUrls[$key] = $url;
            $logger->info("Опубліковано: {$url}");
            $logger->info('Результат записано в output/results.csv');
            $published++;
        } catch (AutomationException $exception) {
            $logger->error($exception->getMessage());
            $failed++;
        }
    }
} finally {
    $publisher->close();
}

$logger->info("Готово. Опубліковано: {$published}, пропущено: {$skipped}, помилок: {$failed}");
exit($failed > 0 ? 1 : 0);

function useLocalTimezone(): void
{
    $link = @readlink('/etc/localtime');
    if (!is_string($link) || preg_match('#zoneinfo/(.+)$#', $link, $matches) !== 1) {
        return;
    }

    try {
        date_default_timezone_set($matches[1]);
    } catch (Throwable) {
        // Keep the timezone from php.ini when the system zone cannot be read.
    }
}
