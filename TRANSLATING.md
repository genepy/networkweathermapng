# Translating NetworkWeathermapNG

The interface is written in English. Each other language is a folder of
`src/app/Lang/`, picked up automatically: once the folder exists, the
language shows in the header menu. No code change is needed.

## Add a language

1. Copy the French catalogue, naming the folder after the language code
   (`de`, `es`, `pt_BR`...; two or three lowercase letters, optionally `_`
   and a two-letter uppercase region):

   ```bash
   cp -r src/app/Lang/fr src/app/Lang/de
   ```

2. Edit `src/app/Lang/de/_meta.php` and set the name of the language, written
   in that language:

   ```php
   return [
       'name' => 'Deutsch',
   ];
   ```

3. Translate the values (right-hand side) of every other file. Keep the keys
   (left-hand side) in English, exactly as they are:

   ```php
   'Maps' => 'Karten',
   'Updated at :time' => 'Aktualisiert um :time',
   ```

   - Keep the `:name` placeholders unchanged; they are replaced at run time.
   - Files named `*-js.php` hold the texts of the browser scripts; translate
     them the same way.
   - A missing entry simply shows the English text, so a partial translation
     is fine to start with.

4. Check the catalogue:

   ```bash
   php src/bin/i18n-check.php de
   ```

   It lists the keys missing from your files (texts of the code without a
   translation) and the unused ones (keys no longer in the code). Add
   `--strict` to get a failing exit status when something is missing.

5. Try it: run the application (`composer start`) and choose the language in
   the header menu.

6. Open a pull request.

## Improve an existing language

Edit the values in `src/app/Lang/<code>/*.php`, run
`php src/bin/i18n-check.php <code>` and open a pull request. The check also
runs in CI, without blocking the build.

## For developers

Wrap interface texts in English with `__('…')` (or `e__('…')` to print them
HTML-escaped) in PHP, and `t('…')` in the browser scripts. Then add the new
keys to the catalogues of `src/app/Lang/` (`php src/bin/i18n-check.php`
shows what is missing).
