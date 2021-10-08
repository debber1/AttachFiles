# AttachFiles
This extension allows users to upload and attach files directly to pages, without going through the default MediaWiki upload process. Made for ULYSSIS VZW by Joachim Vandersmissen.

## Features
* Very user-friendly UI.
* Automatically shows a table of attached files, no parser commands or special configuration required.
* Sort by name, file type, upload date, and uploader.
* Upload and delete files directly on an article page.
* Support for MediaWiki `upload` and `delete` permissions.
* Multiple files with the same name are supported (attached to different pages)
* Works with other extension such as [CompressUploads](https://github.com/ULYSSIS-KUL/CompressUploads).

## Installation
* Download [the latest release](https://github.com/ULYSSIS-KUL/AttachFiles/releases/latest/download/AttachFiles.zip), and put the `AttachFiles` folder in the `extensions` directory.
* Add the following to `LocalSettings.php`:
```
wfLoadExtension( 'AttachFiles' );
```
* Run the maintenance update script to update the database. Go to your wiki directory (containing the `LocalSettings.php` file) and execute the following command:
```
php maintenance/update.php
```

## Configuration
As usual, configuration options can be added to `LocalSettings.php` using global variables.
| Option | Value | Default Value | Description |
| --- | --- | --- | --- |
| `$wgAFIgnoredPages` | `String[]` | `[]` | An array of pages which should be ignored by the extension (i.e. no table of attached files and no upload form is added). For example, ignore your main page by setting `$wgAFIgnoredPages[] = "Main Page";` in `LocalSettings.php` |

### Translations
Translations can be found in the `i18n` folder. Dutch (`nl.json`) and English (`en.json`) translations are already provided. Even the provided translations can be customized, and we strongly advise you to do so, in order to match them with your wiki situation.

## Tips
* Check out [CompressUploads](https://github.com/ULYSSIS-KUL/CompressUploads) to reduce the amount of disk space used by your MediaWiki uploads.
