# Публікація статей на Telegraph

Скрипт відкриває [https://telegra.ph/](https://telegra.ph/), заповнює редактор і зберігає URL створених статей у `output/results.csv`.

Браузер керується бібліотекою [chrome-php/chrome](https://github.com/chrome-php/chrome). Вона підключається до встановленого Google Chrome через Chrome DevTools Protocol. Окремий Selenium і ChromeDriver не потрібні.

## Вимоги

- PHP 8.1 або новіший, з розширенням `mbstring`
- [Composer](https://getcomposer.org/)
- Google Chrome

## Встановлення PHP-залежностей

У корені проєкту:

```bash
composer install
```

Якщо команди `composer` ще немає:

```bash
brew install composer
composer install
```

У корені проєкту вже лежить локальний `composer.phar`. Ним можна поставити залежності без глобального Composer:

```bash
php composer.phar install
```

## Браузер

Скрипт сам знаходить Google Chrome:

- macOS: `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
- Linux: `google-chrome` або `chromium`
- Windows: шлях з реєстру

Окремо встановлювати драйвер не потрібно. Якщо Chrome лежить в іншому місці, передайте шлях у `CHROME_PATH`:

```bash
CHROME_PATH="/path/to/google-chrome" php publish.php
```

## Формат input.csv

Файл `input/articles.csv` містить один рядок заголовків і далі по одному рядку на статтю:

```csv
Title,Author,Content
"PHP automation note","Dmytro","This article was created automatically from input.csv.

The blank line above becomes a second paragraph."
"Second note, with a comma","Dmytro","A short follow-up article for the same CSV run."
```

- Колонки саме такі: `Title`, `Author`, `Content`.
- `Title` має містити щонайменше 2 символи. Коротший заголовок Telegraph не публікує.
- Порожній рядок у `Content` стає новим абзацом.
- Якщо в тексті є кома або перенос рядка, візьміть поле в подвійні лапки.

## Запуск

Усі статті з `input/articles.csv`:

```bash
php publish.php
```

Інший вхідний файл:

```bash
php publish.php path/to/articles.csv
```

Chrome працює у фоні. Кожен крок пишеться в консоль. Стаття з тим самим Title і Author, яка вже є в `output/results.csv`, не публікується повторно: скрипт пише, що її пропущено, і показує наявний URL. Якщо одна нова стаття не публікується, скрипт переходить до наступної. Код виходу `0` означає, що помилок не було. Код `1` означає, що хоча б одна стаття або читання CSV завершились помилкою.

Дата і час у логах і в CSV — локальний час комп’ютера.

## Формат results.csv

Файл `output/results.csv` створюється під час першого успішного запису. Якщо файлу немає, скрипт додає заголовок. Якщо файл уже є, нові рядки дописуються в кінець.

```csv
Title,Author,URL,Date
"PHP automation note",Dmytro,https://telegra.ph/PHP-automation-note-10-01,"2026-10-01 16:07:55"
"Second note, with a comma",Dmytro,https://telegra.ph/Second-note-with-a-comma-10-01,"2026-10-01 16:07:56"
```

## Приклад роботи

```text
[2026-10-01 16:07:52] [INFO] Знайдено статей: 2
[2026-10-01 16:07:52] [INFO] Стаття 1/2: PHP automation note
[2026-10-01 16:07:52] [INFO] Запускаю Google Chrome
[2026-10-01 16:07:53] [INFO] Відкриваю https://telegra.ph/
[2026-10-01 16:07:54] [INFO] Сторінку відкрито
[2026-10-01 16:07:54] [INFO] Шукаю поле Title
[2026-10-01 16:07:54] [INFO] Поле Title заповнено
[2026-10-01 16:07:54] [INFO] Шукаю поле Your name
[2026-10-01 16:07:54] [INFO] Поле Your name заповнено
[2026-10-01 16:07:54] [INFO] Шукаю поле Your story
[2026-10-01 16:07:55] [INFO] Поле Your story заповнено
[2026-10-01 16:07:55] [INFO] Шукаю кнопку Publish
[2026-10-01 16:07:55] [INFO] Натискаю Publish
[2026-10-01 16:07:55] [INFO] Опубліковано: https://telegra.ph/PHP-automation-note-10-01
[2026-10-01 16:07:55] [INFO] Результат записано в output/results.csv
[2026-10-01 16:07:55] [INFO] Стаття 2/2: Second note, with a comma
[2026-10-01 16:07:55] [INFO] Відкриваю https://telegra.ph/
[2026-10-01 16:07:55] [INFO] Сторінку відкрито
[2026-10-01 16:07:55] [INFO] Шукаю поле Title
[2026-10-01 16:07:55] [INFO] Поле Title заповнено
[2026-10-01 16:07:55] [INFO] Шукаю поле Your name
[2026-10-01 16:07:56] [INFO] Поле Your name заповнено
[2026-10-01 16:07:56] [INFO] Шукаю поле Your story
[2026-10-01 16:07:56] [INFO] Поле Your story заповнено
[2026-10-01 16:07:56] [INFO] Шукаю кнопку Publish
[2026-10-01 16:07:56] [INFO] Натискаю Publish
[2026-10-01 16:07:56] [INFO] Опубліковано: https://telegra.ph/Second-note-with-a-comma-10-01
[2026-10-01 16:07:56] [INFO] Результат записано в output/results.csv
[2026-10-01 16:07:56] [INFO] Готово. Опубліковано: 2, помилок: 0
```

Створені сторінки:

- https://telegra.ph/PHP-automation-note-10-01
- https://telegra.ph/Second-note-with-a-comma-10-01

## Помилки

Скрипт пише зрозуміле повідомлення і не затирає вже збережені результати:

| Ситуація | Повідомлення |
|---|---|
| Сайт не відкрився | Не вдалося відкрити https://telegra.ph/ |
| Поле не знайдено | Не знайдено поле Title / Your name / Your story |
| Кнопку Publish не знайдено | Не знайдено кнопку Publish |
| Публікація не відбулася | Публікація не відбулася |
| URL статті не отримано | URL не отримано |
| CSV не записується | Не вдалося записати CSV: … |
