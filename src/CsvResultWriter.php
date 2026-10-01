<?php

declare(strict_types=1);

namespace App;

use Throwable;

final class CsvResultWriter
{
    /**
     * @return array<string, string> title and author => first published URL
     */
    public function publishedUrls(string $path): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AutomationException("Не вдалося прочитати CSV: {$path}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false) {
                return [];
            }

            $columns = [];
            foreach ($header as $index => $name) {
                $columns[trim((string) $name)] = $index;
            }

            foreach (['Title', 'Author', 'URL'] as $column) {
                if (!array_key_exists($column, $columns)) {
                    throw new AutomationException("Не вдалося прочитати CSV: {$path}");
                }
            }

            $urls = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $title = trim((string) ($row[$columns['Title']] ?? ''));
                $author = trim((string) ($row[$columns['Author']] ?? ''));
                $url = trim((string) ($row[$columns['URL']] ?? ''));
                if ($title === '' || $author === '' || $url === '') {
                    continue;
                }

                $key = $this->key($title, $author);
                if (!array_key_exists($key, $urls)) {
                    $urls[$key] = $url;
                }
            }

            return $urls;
        } catch (AutomationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AutomationException("Не вдалося прочитати CSV: {$path}", 0, $exception);
        } finally {
            fclose($handle);
        }
    }

    public function key(string $title, string $author): string
    {
        return $title . "\n" . $author;
    }

    public function append(string $path, Article $article, string $url, string $date): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AutomationException("Не вдалося записати CSV: {$path}");
        }

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new AutomationException("Не вдалося записати CSV: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new AutomationException("Не вдалося записати CSV: {$path}");
            }

            $needsHeader = filesize($path) === 0;
            if ($needsHeader && fputcsv($handle, ['Title', 'Author', 'URL', 'Date'], ',', '"', '') === false) {
                throw new AutomationException("Не вдалося записати CSV: {$path}");
            }

            $written = fputcsv(
                $handle,
                [$article->title, $article->author, $url, $date],
                ',',
                '"',
                '',
            );

            if ($written === false) {
                throw new AutomationException("Не вдалося записати CSV: {$path}");
            }

            fflush($handle);
        } catch (AutomationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AutomationException("Не вдалося записати CSV: {$path}", 0, $exception);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
