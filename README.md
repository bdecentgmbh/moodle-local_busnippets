# Boost Union snippet sync (local_busnippets)

Keeps the SCSS snippets of your own snippets repository in sync with a Moodle site which uses
[theme_boost_union](https://github.com/moodle-an-hochschulen/moodle-theme_boost_union).

A scheduled task downloads a ZIP archive of your snippets repository, validates the contained
`.scss` files and adds them to Boost Union's uploaded snippets. Run it on every site you
operate and they all carry the same snippet library, without anyone having to download and
upload a ZIP archive by hand.

- **Plugin type:** `local`
- **Component:** `local_busnippets`
- **Requires:** Moodle **5.0** or later, and `theme_boost_union`
- **Maturity:** ALPHA (`v0.1.0-alpha`)
- **Author:** bdecent GmbH — <https://bdecent.de>
- **License:** [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html)

---

## How it works

Boost Union has exactly two snippet sources and both are hardcoded: built-in snippets, which are
globbed from the theme directory, and uploaded snippets, which live in the `uploadedsnippets`
file area. There is no API to register a third source.

This plugin therefore does not invent one. It writes the snippets from your repository into that
very file area — exactly as if an admin had uploaded them — and then calls Boost Union's own
public API to register them:

```
\theme_boost_union\snippets::parse_uploaded_snippets();
\theme_boost_union\snippets::cleanup_snippets();
theme_reset_all_caches();
```

These are the same three calls which Boost Union runs when an admin saves its *Upload snippets*
setting. **No Boost Union code is patched.**

Two consequences worth knowing:

- **Snippets are identified by their file name.** A snippet keeps its enabled state across sync
  runs because Boost Union stores it as `(name, source)`. Renaming a file in your repository
  therefore looks like "old snippet gone, new snippet arrived" and the new one starts disabled.
- **Your repository and the community hub can coexist.** Both write into the same file area, so
  make sure your file names do not collide with those of the
  [community snippets repository](https://github.com/moodle-an-hochschulen/moodle-theme_boost_union_snippets).

## Your snippets repository

Any ZIP archive works, as long as it contains `.scss` files carrying valid Boost Union snippet
metadata. Folder structures inside the archive are ignored — as with a manual upload, only the
file name identifies a snippet. The wrapping folder which GitHub and GitLab add to their archives
is stripped automatically, so the *Subdirectory* setting can be written the way the path looks in
your repository.

[`moodle-theme_bdecent_union_snippets`](https://github.com/bdecentgmbh/moodle-theme_bdecent_union_snippets)
is such a repository and can be used as a template for your own one.

A snippet needs the metadata header from the
[boilerplate](https://github.com/moodle-an-hochschulen/moodle-theme_boost_union_snippets/blob/main/boilerplate/boilerplate.scss);
`Snippet Title` is the only field which is strictly required. Files without it are skipped, and so
is `boilerplate/boilerplate.scss` itself. A preview image next to a snippet, with the same base
name and one of the extensions `webp`, `png`, `jpg`, `jpeg` or `gif`, is synced along with it.

## Settings

*Site administration ▸ Plugins ▸ Local plugins ▸ Boost Union snippet sync*

| Setting | Meaning |
|---|---|
| Enable snippet sync | Master switch. While off, the task does nothing and already synced snippets stay untouched. |
| Archive URL | URL of the ZIP archive, e.g. `https://github.com/bdecentgmbh/moodle-theme_bdecent_union_snippets/archive/refs/heads/main.zip`. |
| Access token | Optional. Sent as `Authorization: Bearer <token>`, for private repositories. |
| Subdirectory | Optional. Sync only a part of the repository, e.g. `snippets`. |
| Enable new snippets automatically | Enables snippets which arrive on this site for the first time. Snippets which are already known keep their state, so switching one off on a site is never overruled. |
| Remove snippets which were deleted from the repository | Removes snippets which this plugin added earlier and which are gone from the archive. Snippets uploaded by hand and snippets from other sources are never touched. |

The plugin also adds a notice to the *Uploaded snippets* section of Boost Union's SCSS snippets
settings page, so that admins can see where the extra snippets came from.

## Running it

The scheduled task `\local_busnippets\task\sync_snippets` runs daily at 4:00 by default and can
be rescheduled or triggered under *Site administration ▸ Server ▸ Scheduled tasks*.

To sync right away, for example from a deployment script:

```bash
php local/busnippets/cli/sync.php
```

The sync compares file contents and only resets the theme caches when a snippet was really added,
updated or removed, so running it often is cheap.

## Note

Boost Union only applies uploaded snippets if its *Enable uploaded snippets* setting is switched
on. The sync warns about this in its output but still syncs, so the snippets become effective as
soon as the setting is enabled.
