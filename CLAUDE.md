# TubeWriter

WordPress plugin that writes and publishes YouTube video metadata for the Gregatron Games channel (and friends who run their own copy). It pulls a video's transcript, has Gemini write the title, description, chapters, tags and hashtags in the channel's voice, then pushes them to YouTube through the official YouTube Data API v3 after a multi-step review.

Runs on a LocalWP site (or a private hosted WP). Each install connects ONE YouTube channel with the user's own Google Cloud OAuth client and Gemini API key ("bring your own credentials"). There is no server of ours in the loop.

## Prime directive: never lose the user's data

The owner is nervous about overwriting or deleting YouTube data. Every change must keep these guarantees:

1. **Metadata does not touch YouTube until step 7** of the editor, after a ticked checkbox plus a `confirm()` pop-up. The only other YouTube write is optional step-3 thumbnail upload (`TW_YouTube::set_thumbnail()`), which has its own confirm.
2. **Backup before write.** `TW_Plan::publish()` saves a `TW_History` entry with a snapshot of the live video BEFORE any API write. If the backup fails, it stops.
3. **Stale review guard.** Review returns a fingerprint of the live video. Publish re-fetches and refuses if the fingerprint changed (someone edited in Studio in between).
4. **Never delete.** No calls to `videos.delete`, `playlists.delete` or `playlistItems.delete`. Only update and insert. Do not add delete features without the owner asking.
5. **videos.update wipes omitted fields.** Any writable field left out of a part you send gets cleared. `TW_YouTube::write_video()` always sends every writable field (ours or the live value). Only send the `status` part when changing the schedule, and copy all writable status fields (including `selfDeclaredMadeForKids`).
6. **Undo works both ways.** `TW_Plan::restore()` backs up the live version first, then writes the old one. Playlists are never touched by restore.

## Layout

```
tubewriter.php                  bootstrap, constants (redirect URI, scope, default Gemini model)
includes/
  class-tw-settings.php         options, defaults, language and category lists
  class-tw-oauth.php            Desktop OAuth + PKCE, paste-the-URL connect flow, token refresh
  class-tw-youtube.php          YouTube Data API client: videos, captions, playlists, calendar, quota counter
  class-tw-transcript.php       SRT/VTT to "m:ss text" lines, ISO duration parsing
  class-tw-validator.php        YouTube limits: chapters (0:00 first, 3+, 10s apart), title 100, description 5000 bytes, tags 500 chars / max 8 (aim 4 to 6, drop generics and near-dupes), hashtags max 3, description body warnings, no < >, em dash swap
  class-tw-ai.php               Gemini calls: generate, revise, playlist SEO, thumbnail describe + image gen; fixed JSON contracts
  class-tw-plan.php             review (diff + action list + fingerprint), publish, restore
  class-tw-history.php          per-video backups (25) and global change log (300)
  class-tw-index.php            local index of all uploads (custom table wp_tw_videos), batched sync, list queries, calendar data
  class-tw-videos-table.php     WP_List_Table for the Videos page: visibility tabs, search, filters, sorting, pagination
  class-tw-admin.php            admin pages (Videos, editor wizard, History, Settings) and all AJAX handlers
  default-prompt.php            default channel voice prompt (editable in Settings)
  default-thumb-prompt.php      default thumbnail brief prompt (editable in Settings)
  default-thumb-render-prompt.php  standing image-model instructions (editable in Settings)
assets/admin.js                 editor wizard, vanilla JS, no build step
assets/admin.css
README.md                       end user setup guide
```

## Videos page and the local index

The owner has a very large channel, so the Videos page NEVER calls YouTube to list, search, filter, sort or paginate. It reads `wp_tw_videos` via `TW_Index::query()`.

- Full sync: browser loops `tw_sync` AJAX in batches of 4 pages (200 videos), 2 units per 50 videos. At the end it deletes LOCAL rows not seen in this run. It never deletes anything on YouTube.
- Quick sync: newest 100 uploads, about 4 units, then the scheduled check below.
- Scheduled videos and Studio drafts: API has no draft status (Studio drafts look like private). Private without a future `publishAt` is stored as `privacy=draft`. Future `publishAt` stays Scheduled. After sync, `TW_YouTube::playlist_excluded_videos()` uses `search.list` (`forMine`). Mode `drafts` (Compare drafts) promotes local private-unscheduled rows to draft (0 units), then searches and only upserts draft candidates the index does not already have. If that check errors or hits the page cap, full-sync local delete leaves draft and future scheduled rows in place. `search.list` is 1 unit per call in Google's separate search bucket (100 calls a day), plus `videos.list` at 1 unit per 50.
- After publish or restore, `TW_Index::refresh_one()` updates that row (1 unit).
- The editor's schedule calendar reads the index (0 units). Its refresh button runs a quick sync.
- `tw_status` column (started, draft, pushed) is updated from `save_state()`.

Do not add per-row API calls to the list page.

## Editor wizard (assets/admin.js + TW_Admin::page_editor)

1. Source: transcript (pull captions or paste), starting title/description (prefilled from YouTube), notes. Generate with AI, or Fill in by hand.
2. Details: title options, description body, chapters, hashtags, tags, category, language (sets both defaultLanguage and defaultAudioLanguage).
3. Thumbnail (optional): paste up to 2 screenshots (Ctrl+V), Gemini writes a brief, user approves, OpenAI or Gemini creates a 16:9 PNG. Standing style rules live in `thumb_render_prompt`. Optional **Upload to YouTube** (`thumbnails.set`, ~50 units, confirm required). Skippable.
4. Playlist: none, existing, or new (AI "Polish for SEO"). Created only at publish.
5. Schedule: month calendar of scheduled (orange) and published (green) uploads, click a day, default time from Settings. Private videos only.
6. Review: server builds the plan against the live video, field-by-field diff, errors block publishing.
7. Publish: action list, checkbox, confirm pop-up, results, Undo, manual Studio checklist, version history.

Any edit after Review makes the review stale (`staleReview()`), which re-locks step 7. Source and Thumbnail edits do not stale the review.

## Things the API cannot do (keep them manual)

- Game title (no API field)
- End screens and cards (no API)

Custom thumbnails can be uploaded from step 3 via `thumbnails.set` (channel must be verified). Step 7 still links to Studio for game title and end screens.

## Data storage (wp_options, all autoload off)

- `tw_client_id`, `tw_client_secret`, `tw_gemini_key`, `tw_openai_key`, `tw_model`, `tw_image_provider`, `tw_image_model`, `tw_openai_image_model`, `tw_openai_image_quality`, `tw_prompt`, `tw_thumb_prompt`, `tw_thumb_render_prompt`, `tw_default_time`, `tw_default_language`, `tw_default_category`
- `tw_tokens` (access, refresh, expiry), `tw_channel` (id, title, uploads playlist)
- `tw_state_{videoId}`: draft (transcript, notes, seed title/desc, pkg, opts, thumb_description, thumb_url)
- `tw_history_{videoId}`: backups; `tw_log`: global change log
- `tw_quota`: rough daily API unit count
- Table `{prefix}tw_videos` (version in `tw_db_version`), `tw_index_synced`, `tw_index_full` timestamps

## Quota costs (10,000 units/day/project, resets midnight Pacific)

list/get 1, search.list 1 per call in a separate bucket of 100 calls a day, captions.list 50, captions.download 200, videos.update 50, thumbnails.set 50, playlists.insert 50, playlistItems.insert 50.

## AI contract

`TW_AI::OUTPUT_CONTRACT` is appended to the user's voice prompt and forces JSON with keys: titles, suggested_title, body, chapters_heading, chapters[{time,label}], tags (4 to 6, max 8, no #), hashtags (exactly 3: game, topic, brand). Hashtags and chapters are kept OUT of body; `TW_Validator::assemble_description()` joins body + heading + chapters + hashtags. Don't change the contract keys without updating `TW_AI::parse_package()` and admin.js `fillDetails()`.

## Conventions

- Plain PHP 7.4+, WordPress APIs (`wp_remote_*`, options, nonces). No Composer, no build step.
- Every AJAX handler calls `ajax_guard()` (capability + nonce). Every admin-post handler calls `guard()`.
- Escape all output. User text sent to the AI is never rendered as HTML.
- Owner's writing style for any UI copy: no em dashes, no semicolons, plain direct sentences.

## Testing without real Google

A test-only mu-plugin can intercept `pre_http_request` and fake OAuth, YouTube and Gemini (stateful, so publish, review fingerprint and restore can be exercised). Never ship that file in the plugin.
