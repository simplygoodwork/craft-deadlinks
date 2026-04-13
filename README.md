# Deadlinks plugin for Craft CMS

**Fight link rot by automatically redirecting broken links to their archived versions**.

When a user clicks on a broken external link, they will see a confirmation screen which lets them visit an archived version in the Internet Archive Wayback Machine if available.

The confirmation screen uses a neutral design, but you can can specify your own template if you prefer.

## Requirements

This plugin requires Craft CMS `5.3.0` or later.

Craft must be set to [run queue jobs automatically](https://craftcms.com/docs/5.x/reference/config/general.html#runqueueautomatically).

The plugin needs to make a call to the free [Wayback API](https://archive.org/developers/_static/test-wayback.html) at the address `https://archive.org/wayback/available` in case you need to whitelist this on your host.

## Installation

To install the plugin, follow these instructions:

```
composer require "simplygoodwork/craft-deadlinks:^1.0.0" -w && php craft plugin/install deadlinks
```

for DDEV users:

```
ddev composer require "simplygoodwork/craft-deadlinks:^1.0.0" -w && ddev craft plugin/install deadlinks
```

## Settings

You must [set Craft’s queue to run automatically](https://craftcms.com/docs/5.x/reference/config/general.html#runqueueautomatically)

Other than that, there’s nothing to configure.

## Optional: use a custom confirmation screen

If you want to use a custom confirmation screen, create a `/config/deadlinks.php` file and set `confirmationTemplate` to point to a .twig template (relative to your `/templates/` folder)

```php
<?php

use craft\helpers\App;

return [
	'confirmationTemplate' => 'deadlinks-custom-confirmation.twig’,
];

```

You can use the following variables:

|Twig variable|Type|Description|
|---|---|---|
| `deadlinks.url` | `string` | The dead link URL |
| `deadlinks.linkStatus` | `LinkStatus` | Full link status model (or null) |
| `deadlinks.archiveUrl` | `string\|null` | Wayback Machine archive URL if found |
| `deadlinks.archiveChecked` | `bool\|null` | Whether archive lookup is complete |
| `deadlinks.archiveCheckInProgress` | `bool` | Whether archive lookup is in progress |
| `deadlinks.waybackSearchUrl` | `string` | URL to search Wayback Machine manually |


## How Deadlinks works

- Deadlinks intercepts page render and finds all links
- Relevant links are sent to CheckLink job, which checks URL response code. Any dead links are sent to CheckArchive job, which tries to find version of URL in Internet Archive. This is done via the Craft’s queue so page rendering isn’t slowed down.
- Deadlinks updates the final rendered HTML to point any dead links to the confirmation screen
- CheckLink not finished → show original unmodified URL
- CheckLink finished, CheckArchive not finished → point URL to confirmation screen, but don't show link to archived version
- CheckLink finished, CheckArchive finished and archive found -> point URL to confirmation screen with link to archived version
- CheckLink finished, CheckArchive finished and archive not found or error → point URL to confirmation screen with 'no archive available' message
- Link statuses are stored in `deadlinks_links` DB table so they don’t get re-checked unless needed. If there's a definitive status (archive found/archive not available) then that is used. If there's no definitive status (e.g. error or timeout during last test) then link is checked again.
- CLI commands:
- - `craft deadlinks/default/stats' shows how many links checked and their status
- - `craft deadlinks/default/clear-all` clears all saved link statuses

---

Brought to you by [Good Work](https://simplygoodwork.com).