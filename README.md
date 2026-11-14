# TubeWriter

Pulls a YouTube video's transcript, has Gemini write the title, description, chapters, tags and hashtags in your channel's voice, and pushes them back to the video. Runs on your own local WordPress site with your own Google credentials. Nothing goes through anyone else's server.

## Install

1. In LocalWP, create a site (any name, any domain).
2. In WP Admin go to **Plugins > Add New > Upload Plugin**, upload `tubewriter.zip`, activate.
3. Do the one-time setup below, then open **TubeWriter > Settings**.

## One-time setup (about 15 minutes)

### A. Google Cloud project (free)

1. Go to https://console.cloud.google.com and create a new project, for example `TubeWriter`.
2. **APIs & Services > Library**: search **YouTube Data API v3** and click **Enable**.
3. Open **Google Auth Platform** (search "Google Auth Platform" in the top bar) and click **Get started**.
   - App name: `TubeWriter`. Support email: your Gmail.
   - Audience: **External**.
   - Contact email: your Gmail. Agree and **Create**.
4. **Audience** page: under Test users, **Add users** and add the Gmail that owns your YouTube channel.
5. **Data Access** page: **Add or remove scopes**, add `https://www.googleapis.com/auth/youtube.force-ssl`, save.
6. **Clients** page: **Create client**, type **Desktop app**, name it `TubeWriter`, **Create**.
   Copy the **Client ID** and **Client Secret**. The secret is only shown once.
7. On the **Audience** page click **Publish app** (switch Testing to Production). No review is needed for this.
   In Testing mode Google logs you out of TubeWriter every 7 days. After publishing you will see a
   "Google hasn't verified this app" warning when you connect. It is your own app, so click
   **Advanced > Go to TubeWriter (unsafe)** and continue. You only do this once.

### B. Gemini API key (free)

1. Go to https://aistudio.google.com and sign in.
2. Click **Get API key > Create API key**. Copy it.

Free tier note: Google may use free-tier prompts and responses to improve its products. Your transcripts are for videos you're publishing anyway.

### C. Connect TubeWriter

1. **TubeWriter > Settings**: paste Client ID, Client Secret and Gemini key. **Save settings**.
2. Click **Connect YouTube**. A Google sign-in tab opens. Pick your channel's account and allow access.
3. Google then sends you to a **"This site can't be reached"** page at `127.0.0.1`. That's expected.
   Copy the whole address from the address bar, paste it into the box in Settings, click **Finish connecting**.
4. Set your timezone under **Settings > General** so schedules land at the right time.

## Using it

### Videos page

Your video list is stored on your WordPress site, so searching and filtering never touch your YouTube quota.

- First time: click **Full sync**. It reads 50 videos per call (2 units per 50, so 1,000 videos is about 40 units) and shows progress. If it stops, everything synced so far is kept.
- After uploading new videos: **Quick sync** grabs the newest 100 for about 4 units.
- Tabs filter by visibility: All, Public, Unlisted, Private, Scheduled, Draft.
- The filter bar has Title contains, visibility, a views range, TubeWriter status (not started, draft ready, published) and rows per page.
- Studio drafts: YouTube's API has no Draft status, so TubeWriter treats private (unscheduled) videos as drafts. Use **Compare drafts** to move already-indexed private uploads into Draft and add only the ones still missing. It does not re-sync the whole channel.
- The search box looks in titles and descriptions.
- Click the Video, Date, Views or Length headings to sort. Page controls are at the top and bottom.
- View counts update when you sync.

### Editor

Open **TubeWriter > Videos** and click **Edit** on a video. The editor walks you through 7 steps, each with its own instructions at the top. **Nothing on YouTube changes until step 7.** **Save draft** is in the bar at the bottom, and drafts also save every time you change steps.

1. **Source.** Pull captions or paste a transcript, check the starting title and description, add notes. Click **Generate with AI**, or **Fill in by hand** to edit what is on YouTube now.
2. **Details.** Title, description, chapters, hashtags, tags, category and language. Use **Tweak it with AI** for changes.
3. **Thumbnail (optional).** Paste up to two screenshots with Ctrl+V, let Gemini write a thumbnail brief, edit it, then **Create thumbnail** with OpenAI or Gemini (set the provider and API key in Settings). **Upload to YouTube** pushes it live (about 50 API units, needs a verified channel), or download and upload in Studio. Skip with Next if you do not need one.
4. **Playlist.** Don't add, add to an existing playlist, or create a new one (**Polish for SEO** writes the title and description).
5. **Go live.** Choose **Publish now** to make it public when you confirm in step 7, **Schedule it** and click a day on the calendar, or **Leave as is** to keep it private and only update the details. Orange is already scheduled, green is already published. Private videos only.
6. **Review.** What is on YouTube now next to what it will be after publishing. Red errors block publishing.
7. **Publish.** Read the list of changes, tick the box, click **Publish changes**, confirm the pop-up. Then set the game title and end screen in Studio (YouTube's API can't set those). Thumbnails can be uploaded from step 3.

## Safety

- A backup of the current title, description, tags, category, language and schedule is saved before every publish.
- **Version history** in step 7 lists every backup. **Restore this version** puts it back, and backs up the current version first so you can undo the undo.
- If the video changed in Studio after your review, publishing stops and asks you to review again.
- Editing anything after the review locks step 7 until you review again.
- TubeWriter never deletes videos, playlists or playlist entries.
- **TubeWriter > History** lists every change across all videos.

## Rules TubeWriter enforces before pushing

- Chapters: first at 0:00, at least 3, each 10+ seconds apart, none past the end. It fixes these automatically and tells you what it changed.
- Title: 100 characters max. Description: 5000 bytes max (emoji count as 4).
- Tags: 500 characters total, capped at 8 (aim for 4 to 6). Generic tags and near-duplicates are dropped. Warnings only, not blockers.
- Hashtags: capped at 3 (only 3 show above the title). Extras are trimmed with a warning.
- Description body: warnings if it opens with the warning block or a link, looks like a keyword list, runs over 1,500 characters, or has a truncated GG Algorithm Warning.
- No `<` or `>` (YouTube rejects them).

## Quota

YouTube gives each Google Cloud project 10,000 units a day, reset at midnight Pacific.

| Action | Units |
|---|---|
| Open a video in the editor | 1 |
| Pull captions | 250 |
| Review | 1 |
| Publish | 51, plus 51 to add to a playlist, plus 50 to create one |
| Full sync | 2 per 50 videos |
| Quick sync | about 8 (newest 100, plus scheduled videos) |
| Full sync scheduled check | up to about 16 more, on top of the per-page cost |
| Videos page, search, filters, calendar | 0 |

That's roughly 30 videos a day if you pull captions for each. Pasting transcripts instead costs almost nothing.

## Troubleshooting

- **"Your YouTube connection expired"**: your Cloud project is in Testing mode. Reconnect, or publish the app (step A7).
- **"Google did not return a refresh token"**: remove TubeWriter at https://myaccount.google.com/permissions and connect again.
- **"No captions yet"**: wait for YouTube to finish auto-captions, or paste a transcript.
- **Gemini 429**: free-tier rate limit. Wait a minute.
- **Gemini model not found**: Google renamed or retired the model. Put a current Flash model name in Settings.
- **Schedule refused**: only private videos that have never been public can be scheduled through the API.

## Make it yours

Everything about the voice lives in **Settings > Channel voice prompt**. The JSON output rules get added behind the scenes, so you only describe tone, the warning text, chapter style, tags and hashtags. **Reset prompt to default** brings back the GG prompt.
