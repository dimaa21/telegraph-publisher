<?php

declare(strict_types=1);

namespace App;

use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\ElementNotFoundException;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use Throwable;

final class TelegraphPublisher
{
    private const START_URL = 'https://telegra.ph/';
    private const TITLE_SELECTOR = 'h1[data-placeholder="Title"]';
    private const AUTHOR_SELECTOR = 'address[data-placeholder="Your name"]';
    private const STORY_SELECTOR = 'p[data-placeholder="Your story..."]';
    private const PUBLISH_SELECTOR = '#_publish_button';

    private ?ProcessAwareBrowser $browser = null;
    private ?Page $page = null;

    public function __construct(private readonly ConsoleLogger $logger)
    {
    }

    public function publish(Article $article): string
    {
        $this->openEditor();
        $this->fillField(self::TITLE_SELECTOR, 'Title', $article->title, 'line');
        $this->fillField(self::AUTHOR_SELECTOR, 'Your name', $article->author, 'line');
        $this->fillField(self::STORY_SELECTOR, 'Your story', $article->content, 'story');
        $this->clickPublish();

        return $this->waitForPublishedUrl();
    }

    public function close(): void
    {
        try {
            $this->browser?->close();
        } catch (Throwable) {
            // Chrome is also stopped when the PHP process exits.
        }

        $this->browser = null;
        $this->page = null;
    }

    private function openEditor(): void
    {
        if ($this->page === null) {
            $this->page = $this->browser()->createPage();
            $this->page->keyboard()->setKeyInterval(5);
        }

        $this->logger->info('Відкриваю https://telegra.ph/');

        try {
            $this->page()->navigate(self::START_URL)->waitForNavigation(Page::LOAD, 20000);
        } catch (Throwable $exception) {
            throw new AutomationException('Не вдалося відкрити https://telegra.ph/', 0, $exception);
        }

        $this->waitForEditor();
        $this->logger->info('Сторінку відкрито');
    }

    private function waitForEditor(): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            try {
                $ready = $this->page()->evaluate(<<<'JS'
                    (() => {
                        const title = document.querySelector('h1[data-placeholder="Title"]');
                        const author = document.querySelector('address[data-placeholder="Your name"]');
                        const story = document.querySelector('p[data-placeholder="Your story..."]');
                        const publish = document.querySelector('#_publish_button');
                        return !!(window.quill && window.quill.isEnabled() && title && author && story && publish);
                    })()
                JS)->getReturnValue();

                if ($ready === true) {
                    return;
                }
            } catch (Throwable) {
                // The editor script is still loading.
            }

            usleep(200000);
        }

        throw new AutomationException('Не вдалося відкрити https://telegra.ph/');
    }

    private function fillField(string $selector, string $label, string $text, string $mode): void
    {
        $this->logger->info("Шукаю поле {$label}");
        $this->focus($selector, "Не знайдено поле {$label}");

        if ($mode === 'story') {
            $this->typeStory($text);
        } else {
            $this->page()->keyboard()->typeText($this->normalize($text, 'line'));
        }

        $actual = $this->readField($selector, $mode);
        $expected = $this->normalize($text, $mode);
        if ($actual !== $expected) {
            throw new AutomationException("Не вдалося заповнити поле {$label}");
        }

        $this->logger->info("Поле {$label} заповнено");
    }

    private function typeStory(string $text): void
    {
        $lines = explode("\n", $this->normalize($text, 'story'));
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $this->pressEnter();
            }

            if ($line !== '') {
                $this->page()->keyboard()->typeText($line);
            }
        }
    }

    private function pressEnter(): void
    {
        // Quill starts a new paragraph on key code 13. Keyboard::type('Enter') does not send that code.
        $session = $this->page()->getSession();
        $down = [
            'type' => 'keyDown',
            'key' => 'Enter',
            'code' => 'Enter',
            'windowsVirtualKeyCode' => 13,
            'nativeVirtualKeyCode' => 13,
        ];
        $up = $down;
        $up['type'] = 'keyUp';

        $session->sendMessageSync(new Message('Input.dispatchKeyEvent', $down));
        $session->sendMessageSync(new Message('Input.dispatchKeyEvent', $up));
    }

    private function clickPublish(): void
    {
        $this->logger->info('Шукаю кнопку Publish');
        $this->focus(self::PUBLISH_SELECTOR, 'Не знайдено кнопку Publish');
        $this->logger->info('Натискаю Publish');
    }

    private function focus(string $selector, string $notFoundMessage): void
    {
        $this->requireElement($selector, $notFoundMessage);

        try {
            $this->page()->mouse()->find($selector)->click();
        } catch (ElementNotFoundException $exception) {
            throw new AutomationException($notFoundMessage, 0, $exception);
        }
    }

    private function waitForPublishedUrl(): string
    {
        $deadline = microtime(true) + 20;
        $siteError = '';

        while (microtime(true) < $deadline) {
            $url = $this->currentUrl();
            if ($this->isPublishedUrl($url)) {
                return $url;
            }

            $error = $this->visibleError();
            if ($error !== '') {
                $siteError = $error;
            }

            usleep(250000);
        }

        if ($siteError !== '') {
            throw new AutomationException("Публікація не відбулася: {$siteError}");
        }

        $url = $this->currentUrl();
        if ($url === '' || $url === 'about:blank') {
            throw new AutomationException('URL не отримано');
        }

        throw new AutomationException('Публікація не відбулася');
    }

    private function requireElement(string $selector, string $message): void
    {
        try {
            $this->page()->waitUntilContainsElement($selector, 10000);
        } catch (OperationTimedOut $exception) {
            throw new AutomationException($message, 0, $exception);
        }
    }

    private function readField(string $selector, string $mode): string
    {
        try {
            if ($mode === 'story') {
                $value = $this->page()->evaluate(<<<'JS'
                    (() => Array.from(document.querySelectorAll('#_tl_editor p'))
                        .map((paragraph) => paragraph.innerText.replace(/\u00a0/g, ' ').replace(/\n+$/g, ''))
                        .join('\n')
                        .replace(/\n+$/g, ''))()
                JS)->getReturnValue();
            } else {
                $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR);
                $value = $this->page()->evaluate(<<<JS
                    (() => {
                        const node = document.querySelector({$selectorJson});
                        return node ? node.innerText.replace(/\u00a0/g, ' ') : '';
                    })()
                JS)->getReturnValue();
            }
        } catch (Throwable) {
            return '';
        }

        return $this->normalize((string) $value, $mode);
    }

    private function currentUrl(): string
    {
        try {
            return (string) $this->page()->evaluate('window.location.href')->getReturnValue();
        } catch (Throwable) {
            return '';
        }
    }

    private function visibleError(): string
    {
        try {
            $error = $this->page()->evaluate(<<<'JS'
                (() => {
                    const el = document.querySelector('#_error_msg');
                    if (!el || !el.classList.contains('shown')) {
                        return '';
                    }
                    return (el.textContent || '').trim();
                })()
            JS)->getReturnValue();
        } catch (Throwable) {
            return '';
        }

        return trim((string) $error);
    }

    private function isPublishedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'telegra.ph') {
            return false;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $path !== '' && $path !== '/';
    }

    private function normalize(string $text, string $mode): string
    {
        if ($mode === 'story') {
            $text = str_replace(["\r\n", "\r"], "\n", $text);

            return rtrim($text, "\n");
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function page(): Page
    {
        if ($this->page === null) {
            throw new AutomationException('Не вдалося відкрити https://telegra.ph/');
        }

        return $this->page;
    }

    private function browser(): ProcessAwareBrowser
    {
        if ($this->browser !== null) {
            return $this->browser;
        }

        $this->logger->info('Запускаю Google Chrome');

        try {
            $this->browser = (new BrowserFactory())->createBrowser([
                'headless' => true,
                'windowSize' => [1280, 900],
                'startupTimeout' => 30,
                'enableImages' => false,
            ]);
        } catch (Throwable $exception) {
            throw new AutomationException(
                'Не вдалося запустити Google Chrome. Встановіть Chrome або вкажіть шлях у CHROME_PATH.',
                0,
                $exception,
            );
        }

        return $this->browser;
    }
}
