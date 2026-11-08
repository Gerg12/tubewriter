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
7. Recommended: back on the **Audience** page click **Publish app** (switch Testing to Production).
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

1. **TubeWriter > Videos** lists your latest uploads, private ones included. Click **Write**.
2. **Pull YouTube captions**, or paste a transcript. SRT, VTT and plain text with timestamps all work.
   Captions appear a while after the upload finishes processing.
3. Add **notes**: game, mode, who you played with, what actually happened. The AI trusts notes over the transcript.
4. **Generate**. Pick a title, edit anything, use **Tweak it** for changes like "make title 3 meaner".
5. Optional: tick **Schedule publish** (private, never-published videos only).
6. **Push to YouTube**. It replaces the title, description and tags on the video.

Drafts are saved per video, so you can come back later.

## Rules TubeWriter enforces before pushing

- Chapters: first at 0:00, at least 3, each 10+ seconds apart, none past the end. It fixes these automatically and tells you what it changed.
- Title: 100 characters max. Description: 5000 bytes max (emoji count as 4). Tags: 500 characters total.
- No `<` or `>` (YouTube rejects them).

## Quota

YouTube gives each Google Cloud project 10,000 units a day, reset at midnight Pacific.

| Action | Units |
|---|---|
| Open the video list or a video | 1 to 2 |
| Pull captions | 250 |
| Push | 51 |

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
