# UploadWriter — ChatGPT design / imagery brief

Copy everything below the line into ChatGPT.

---

You are an art director and product brand designer helping UploadWriter.com. Your job is to deeply understand the product process, then invent login/register and brand photography that visually tell that story. Do not invent features. Do not turn this into a generic AI SaaS brand. Match the existing warm editorial look.

# PART 1 — Product deep dive (read this carefully before designing)

## The problem UploadWriter solves

Independent YouTube creators already make the video. The painful part is everything after the upload:

- Writing a sharp title
- Writing a description that sounds like them
- Building chapters that match the talk track
- Choosing tags and hashtags that are useful, not spammy
- Making or refining a thumbnail
- Putting the video in the right playlist
- Deciding publish-now vs schedule
- Hopping between YouTube Studio tabs, AI chat tabs, calendars, and notes
- Worrying that an AI tool will publish something without their say

UploadWriter is the workspace for that post-upload work. Tagline energy: “Made for the work after the upload.” Journey headline: “From ‘uploaded’ to ‘that’s the one.’”

It is not a video editor. It is not an upload tool. It is not auto-posting. It is not a multi-channel agency dashboard. It is a focused creative control room for one creator and one connected channel.

## Who it is for

Solo or small-channel YouTube creators who care about voice and craft. They want AI as a first-draft partner, not a replacement. They bring their own Gemini and/or OpenAI keys. They connect their own YouTube channel through Google OAuth. Keys stay encrypted on their WordPress site. Positioning: “Your channel. Your keys. Your final say.”

## Public brand vs internal name

- Public product name: UploadWriter / UploadWriter.com
- Internal code name: TubeWriter / routes like /tubewriter/login/
- Never put “TubeWriter” on customer-facing art
- Brand mark: charcoal pen nib with a red/terracotta underline
- Wordmark: Upload + italic Writer (+ muted .com when full)
- Avoid YouTube play-triangle marks in owned graphics
- Red/terracotta is the shared accent, not a YouTube clone look

## The full user process (this is what imagery must feel like)

### Stage 0 — Arrival / marketing site
They land on a cinematic homepage. Warm creator photography. Hero ideas like “Great videos deserve better words.” Assurance: bring your AI key, review before you publish. Capabilities strip: Titles & descriptions · Chapters & tags · Thumbnails · Scheduling. Feeling: editorial magazine for creators, not a neon AI dashboard.

### Stage 1 — Create account / request access
Route: /tubewriter/register/
They create an account (or request private beta access). Lead copy today: “Connect AI and YouTube, draft metadata, and publish only after review. Your keys stay encrypted on this site.” Feeling: invitation into a private studio, trust and calm, not growth-hack signup.

### Stage 2 — Log in (THIS IS THE PAGE WE ARE GUSSYING UP)
Route: /tubewriter/login/
Current state (problem): plain centered card, text “UploadWriter”, thin red bar, H1 “Log in”, lead “Back to your channel workspace.” Flat paper background. No photography. No pen mark. Same marketing nav as the homepage, so it feels underdressed compared to the cinematic site.
Emotion we want: returning to your creative control room. Familiar. Quiet. Ready to work. Not “sign into software.” More “pick up the pen again.”

### Stage 3 — Set up Keys & voice
Inside the account workspace tabs: Videos · Schedule · Keys & voice.
They fill Channel identity & voice (who they are, how they sound on camera / in writing). They paste Gemini and/or OpenAI keys. Keys are encrypted at rest. They connect YouTube. Optional: generate a Muse API key so an external AI assistant can help this account with permissions they choose (push, restore, sync). Feeling: unlocking the studio. Tools on the desk. Personal voice, not corporate onboarding.

### Stage 4 — Sync the channel into a local library
First time: Full sync pulls their uploads into a local index on the site (batched). Later: Quick sync for newest uploads. After that, browse / search / filter / sort / calendar cost zero YouTube API units because the library is local. Tabs: All, Public, Unlisted, Private, Scheduled, Draft. Filters for title, visibility, views, TubeWriter status (not started / draft ready / published). Feeling: “a clearer view of your channel.” Control room overview. Not a raw API console.

### Stage 5 — Open one video and walk the 7-step editor
Critical product rule: Nothing on YouTube changes until step 7 (except an optional explicit thumbnail upload in step 3 with its own confirm). Drafts autosave when changing steps. Save draft is always available.

Step 1 — Source
Pull captions or paste a transcript. Check starting title/description from YouTube. Add notes. Then Generate with AI, or Fill in by hand. Feeling: raw material on the desk. Transcript as clay.

Step 2 — Details
Title options, description body, chapters, hashtags, tags, category, language. Tweak it with AI for revisions. Feeling: writing craft. Editing words, not uploading files.

Step 3 — Thumbnail (optional)
Paste up to two screenshots. AI writes a thumbnail brief. User edits the brief. Create thumbnail with OpenAI or Gemini. Optionally upload to YouTube (confirmed) or download for Studio. Skip if not needed. Feeling: visual craft beside the writing craft.

Step 4 — Playlist
Don’t add / add to existing / create new (AI can polish playlist title+description for SEO). Playlist changes only happen at publish. Feeling: organizing the shelf.

Step 5 — Go live
Publish now (becomes public when they confirm in step 7), Schedule it (pick a day/time on calendar), or Leave as is (keep private / only update details). Calendar shows orange for already scheduled, green for already published. Feeling: choosing when the work goes out into the world.

Step 6 — Review
Field-by-field comparison: what is live on YouTube now vs what will change. Errors block publishing. Feeling: proof desk. Serious, careful, trustworthy.

Step 7 — Publish
Read the action list. Tick the checkbox. Confirm the popup. Backup of current live details is saved first. Then write. Version history + restore available. If the video changed in Studio after review, publish stops and asks them to review again. Feeling: the final signature. Relief and control. Not impulsive posting.

### Stage 6 — Schedule calendar view
Separate Schedule tab: browse scheduled and published uploads by day. Click a day, review that day’s posts, open one to edit. Feeling: publishing calendar as a creative planner, not a corporate Gantt chart.

### Stage 7 — Aftercare / safety net
Version history per video. Global change log. Restore backs up current first so undo can be undone. UploadWriter never deletes videos, playlists, or playlist entries. Feeling: safety, craftsmanship, respect for their channel.

## Trust principles (must show up in brand mood)

1. AI drafts. Human decides.
2. Bring your own keys. No middleman server for the creator’s credentials.
3. Review before publish. Twice-confirmed at the end.
4. Backup before every write.
5. Never silent auto-publish.
6. Never delete their YouTube content.
7. Less tab hopping. More creating.

## Emotional arc for imagery

Map photos to feelings in the process:

1. Invitation / arrival — warm empty desk, open notebook, morning light (login, register)
2. Voice & tools — pen, keys-as-metaphor objects, notebook with blank pages (setup)
3. Channel overview — calm creator surveying work, planner, library mood (workspace)
4. Drafting — writing, editing words, over-shoulder focus (editor / details)
5. Visual craft — screenshots, storyboard, still camera gear (thumbnails)
6. Timing — calendar, clock-adjacent soft motifs without literal UI (schedule)
7. Final say — stepping back, underline on paper, closed notebook with pen resting (publish / trust)

## Existing visual system (do not reinvent)

Colors:
- Paper / ivory backgrounds: #faf9f6, #f7f7f2
- Ink / charcoal: #242820, #242321
- Muted text: #6d7067
- Accent terracotta/coral: #ec593e, #d93620, #ca452e
- Soft lime highlight band (rare): #e4ecae

Typography vibe:
- Clean geometric sans for UI (DM Sans / Manrope energy)
- Georgia italic for editorial Writer moments

Existing homepage photo set already covers:
- Creator recording talking-head in warm home studio
- Equipment still-life (camera, headphones, mic, notebook)
- Over-shoulder editing at desk
- Planning with notebook + laptop
- Storyboard sketchbook + tablet + coffee

Login/register currently have NONE of that atmosphere. That gap is the design problem.

# PART 2 — Design assignment

## Primary job
Propose and generate brand imagery + layout direction to gussy up:
1. Login page
2. Register page
3. Any supporting brand stills that reinforce the process above (OG/social, auth textures, empty-state mood)

## Login experience goals
- Feel like returning to “YOUR CREATIVE CONTROL ROOM”
- Same brand as the cinematic homepage
- Form stays dead simple and readable
- Use the real pen mark, not a random arrow tile
- Short lead line that echoes process trust (examples below)
- Do not turn login into a second homepage with feature grids, stats, or pricing

## Register experience goals
- Slightly more aspirational than login
- Promise the process: connect AI + YouTube, draft metadata, publish only after review
- Private-studio / request-access energy if needed
- Still calm and premium

## Layout concepts to explore (describe all, then pick a recommendation)
A) Split panel: process photo left / auth card right
B) Soft full-bleed atmospheric photo with solid paper auth card floating center
C) Charcoal vertical brand band (pen mark + short process line) + paper form column
D) Minimal paper page with one strong still-life vignette above or beside the card
E) Your better idea if it fits the process and palette better

For each concept, explain how it expresses “work after the upload” and “final say.”

## Voice lines to generate
Write 6 login lead alternatives and 6 register lead alternatives that sound like UploadWriter (plain, direct, no em dashes, no semicolons, no hype). Seed tone from existing lines:
- Back to your channel workspace.
- Your channel. Your keys. Your final say.
- AI helps with the first draft. You keep the last word.
- Review before you publish.
- Less clicking. More creating.
- From uploaded to that’s the one.

# PART 3 — Image generation prompts (generate multiple variants)

Art direction for ALL images:
Photorealistic editorial lifestyle photography. Warm daylight. Tactile wood, paper, metal. Palette warm ivory + charcoal + burnt orange/terracotta. No logos. No readable text. No recognizable software UI. No watermarks. No purple gradients. No YouTube play button. Leave negative space where UI may overlay.

Generate several variants of each:

1) LOGIN ATMOSPHERE 16:9
Quiet morning desk for a returning creator. Soft-focus ivory desk edge in foreground, charcoal notebook and fountain pen slightly out of focus, cream wall, side window light, one terracotta accent object. Empty and inviting. Plenty of soft midtone space for a login card overlay. No dominant faces. Mood: pick up the pen again.

2) LOGIN SPLIT PANEL 4:5
Taller crop for a left/right auth layout. Open blank cream notebook, black pen resting diagonally, soft bokeh camera body behind. Keep one third quieter for overlay text or logo. Mood: back to the control room.

3) REGISTER INVITATION
Young adult creator in a warm home studio standing back from a tidy desk after finishing notes, calm small smile, camera on tripod soft in background, cream walls, daylight. Portrait. Human and grounded, not influencer glossy. Mood: make yourself at home.

4) SOURCE / TRANSCRIPT METAPHOR
Desk scene implying raw material becoming writing: open notebook, headphones, small mic, coffee, no readable words. Mood: clay before the sculpture.

5) DETAILS / WRITING CRAFT
Close editorial of hands with a pen above paper near a blurred monitor showing only soft color shapes (no UI text). Mood: words are the craft.

6) THUMBNAIL / VISUAL CRAFT
Still-life of printed storyboard frames or polaroid-like frames (blank/abstract imagery only), pencil, tablet edge, terracotta cup. Mood: visual craft beside writing.

7) SCHEDULE / CALENDAR
Top-down planner with abstract ruled lines only (no readable dates or words), pen, blank terracotta sticky note, morning light. Mood: clearer calendar.

8) REVIEW / FINAL SAY
Macro of a charcoal fountain pen nib drawing one confident red-orange underline on cream paper. Soft shadow. Magazine product photography. Mood: the final signature / review before publish.

9) AUTH TEXTURE
Almost abstract paper grain + soft terracotta wash + faint pencil grid for CSS backgrounds.

10) SOCIAL / OG 1200x630 FEEL
Wide cinematic desk still-life: notebook, pen, headphones, closed laptop, burnt-orange paper curve behind. Large clean negative space on one side for a future wordmark. Do not render any text or logo.

# PART 4 — What to deliver back

1. A short creative strategy paragraph proving you understood the process (not a feature list dump).
2. Recommended login layout + why it fits stages 2 and 7 emotionally.
3. Recommended register layout.
4. The voice-line alternatives.
5. The generated images, labeled by which process stage they support.
6. A do/don’t list for future UploadWriter brand photos.
7. Optional: 3 tiny sticker/ticket copy ideas in the homepage style (like “Made by you. Fine-tuned with UploadWriter.”) usable near auth without cluttering the form.

Remember: this product is about craft, control, and the work after upload. Every image should feel like part of that process.
