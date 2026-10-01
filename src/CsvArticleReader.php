<?php

declare(strict_types=1);

namespace App;

use Throwable;

final class CsvArticleReader
{
    private const REQUIRED_COLUMNS = ['Title', 'Author', 'Content'];

    /**
     * @return list<Article>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new AutomationException("Не вдалося прочитати CSV: файл не існує ({$path})");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AutomationException("Не вдалося прочитати CSV: {$path}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false) {
                throw new AutomationException('У вхідному CSV немає заголовка Title,Author,Content');
            }

            $columns = $this->columnMap($header);
            $articles = [];
            $rowNumber = 1;
            $blankLineBeforeNextRow = false;

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($this->isEmptyRow($row)) {
                    $blankLineBeforeNextRow = true;
                    continue;
                }

                $title = $this->cell($row, $columns['Title']);
                $author = $this->cell($row, $columns['Author']);
                $content = $this->cell($row, $columns['Content']);

                if ($title !== '' && $author !== '' && $content !== '') {
                    if (mb_strlen($title) < 2) {
                        throw new AutomationException("Рядок {$rowNumber}: Title має містити щонайменше 2 символи");
                    }

                    $articles[] = new Article($title, $author, $content);
                    $blankLineBeforeNextRow = false;
                    continue;
                }

                $extraParagraph = $this->extraParagraph($row);
                $lastIndex = array_key_last($articles);
                if ($extraParagraph !== null && $lastIndex !== null) {
                    $separator = $blankLineBeforeNextRow ? "\n\n" : "\n";
                    $previous = $articles[$lastIndex];
                    $articles[$lastIndex] = new Article(
                        $previous->title,
                        $previous->author,
                        $previous->content . $separator . $extraParagraph,
                    );
                    $blankLineBeforeNextRow = false;
                    continue;
                }

                throw new AutomationException("Рядок {$rowNumber}: Title, Author і Content не можуть бути порожніми");
            }

            return $articles;
        } catch (AutomationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AutomationException("Не вдалося прочитати CSV: {$path}", 0, $exception);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int>
     */
    private function columnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $normalized = trim((string) $name);
            if ($index === 0) {
                $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
            }
            $map[$normalized] = $index;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!array_key_exists($column, $map)) {
                throw new AutomationException('У вхідному CSV мають бути колонки Title, Author, Content');
            }
        }

        return $map;
    }

    /**
     * A line written after the closing quote is the next paragraph of the previous article.
     *
     * @param list<string|null> $row
     */
    private function extraParagraph(array $row): ?string
    {
        $filled = [];
        foreach ($row as $cell) {
            $value = trim((string) $cell);
            if ($value !== '') {
                $filled[] = $value;
            }
        }

        if (count($filled) !== 1) {
            return null;
        }

        $text = $filled[0];
        if (str_ends_with($text, '"')) {
            $text = substr($text, 0, -1);
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param list<string|null> $row
     */
    private function cell(array $row, int $index): string
    {
        return trim((string) ($row[$index] ?? ''));
    }

    /**
     * @param list<string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
