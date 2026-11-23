# UploadWriter studio brand and page system

Implemented locally on 22 November 2026. This is the existing WordPress theme, with PHP-rendered ACF blocks. No replacement framework or static HTML page builder.

## The idea

UploadWriter is the place a creator comes back to after making the video. Its identity should make the careful part feel inviting: find the right words, review the changes, make the final call. A quiet real desk, the existing charcoal pen mark, warm paper, editorial italic headlines, and a restrained terracotta action button express that promise. Avoid making the login page carry the entire sales story.

## Editable screens

- Pages → Studio login (local ID 50): Studio sign-in ACF block, login mode.
- Pages → Studio registration (local ID 51): the same block, registration mode.
- Site Options → UploadWriter sign-in pages: explicitly assigns the published design page for each app route. Keep one Studio sign-in block per assigned page. A missing, password-protected, unpublished, or invalid source falls back to the bundled layout.
- Actual app URLs remain /tubewriter/login/ and /tubewriter/register/. The route chooses the correct form even if the block's mode is wrong.
- Both design pages use the UploadWriter studio entry page template. The form is represented by an explanation in editor preview. The live form is rendered by TW_Frontend::auth_form(), not stored in post content.
- Existing nonces, handlers, field names, honeypot, registration timer, beta request behavior, password reset, and signed-in redirects remain in place.
- Authentication templates send no-cache headers. Exclude the studio pages and app routes from any external full-page cache, as with all login forms.

Edit the photo, eyebrow, photo headline, supporting sentence, caption, form heading, and lead through the block fields. Blank fields use built-in defaults. Private beta adds its required approval notice regardless of the editable introduction.

## Layout choices

A. Split photo and paper form, implemented. The desk invites a returning creator back into their studio. A clean form and explicit final-say assurance carry the same calm as the last publishing step.
B. Full-bleed photo and floating paper card. Atmospheric, but a longer registration card obscures the scene and creates more image crop work on phones. Keep the final-say line on solid paper.
C. Charcoal brand band and paper column. A strong low-bandwidth alternative. The pen mark stands for authorship and the short trust line carries the review promise.
D. Minimal paper and one still-life vignette. A useful compact fallback. The notebook places the page after recording and the pen suggests a considered final decision.
E. A returning-studio layout. Keep the split on desktop, place the form first on phones, and let the photograph become a closing editorial panel. Implemented as the responsive version of A.

Registration uses the same structure with a creator planning at a desk and the invitation “Make yourself at home.” Login uses the new empty-desk photograph and “Pick up where you left off.”

## Added reusable blocks

- UploadWriter: Studio sign-in. Editable photo and copy around the canonical form.
- UploadWriter: Publishing process. Seven editable steps. The thumbnail confirmation exception remains visible below the list.
- UploadWriter: Your final say. Photo and editable safeguards around review, metadata backups, and creator ownership.

Patterns: UploadWriter studio login, UploadWriter studio registration, UploadWriter publishing story. PHP field groups live in inc/studio-blocks.php. No ACF JSON import or JavaScript build is required.

Pages → How it works (local ID 52) contains saved workflow, process, trust, and CTA blocks. Existing homepage blocks and saved homepage content were retained. Header and footer navigation now link to the detailed /how-it-works/ page. If that page is unavailable, the helper falls back to the homepage workflow section.

## Login lead alternatives

1. Your next good draft starts here.
2. Back to your channel workspace.
3. Pick up the draft. Keep your voice.
4. A little space to get the words right.
5. Your channel is ready when you are.
6. AI helps you start. You have the final say.

## Registration lead alternatives

1. Connect your channel. Bring your AI key. Make the first draft yours.
2. A calmer place for the work after the upload.
3. Start with your video. Finish with your voice.
4. Give your uploads a workspace of their own.
5. Draft the details. Review the changes. Make the final call.
6. Your channel. Your keys. A little more room to create.

## Photography and asset

New assets: assets/images/studio-return-{640,960,1536}.webp and assets/images/studio-invitation-{640,960,1536}.webp. Built-in ImageGen, photorealistic-natural. Generated master remains in the Codex generated_images folder. Both families have responsive project copies of about 24–73 KB. Login uses the quiet desk still and registration uses the standing creator invitation. Both also live in the local Media Library (54 and 55) and are selected through each saved ACF image field. Bundled assets remain the fallback. How it works now has its own three-image family for source material, final review, and visual planning. See HOW-IT-WORKS-IMAGERY.md.

Final generation prompt: A premium editorial landscape 3:2 photograph that also crops to 4:5. Quiet returning creator's desk beside a side window. Open blank ivory notebook and black fountain pen in the lower center, soft bokeh black camera body behind, small terracotta ceramic cup, tactile oak, cream plaster wall. Warm morning daylight, soft directional shadows, subtle film texture. Keep the upper half quieter and slightly darker for a later white HTML headline. Warm ivory, charcoal, muted terracotta. No people, logos, readable writing, letters, software UI, watermarks, play triangles, purple, neon, or added graphics.

Do: natural daylight, tactile materials, ordinary creator spaces, believable working poses, blank notebooks, room for crops, focused craft, restrained terracotta accents.
Don't: neon dashboards, robot assistants, invented app screenshots, fake analytics, play-triangle identity, glossy influencer poses, readable fake transcripts, baked-in words, or photos suggesting unattended publishing.

Small ticket copy: “Made by you. Fine-tuned with UploadWriter.” / “A first draft. Your final say.” / “Ready when you are.”

## Validation and handoff

Preview local app routes plus /how-it-works/. Check desktop and 320–390 px phones, one form per screen, all image candidates loading, no horizontal overflow, keyboard focus, and beta approval notice. No test account or YouTube write is required for this visual pass.

Theme files do not migrate WordPress content or Site Options. On another installation, insert the two studio patterns into published pages, use the studio entry template, assign those pages under Site Options, and insert the publishing-story pattern into How it works. Do not copy local numeric page or attachment IDs between databases. The idempotent CLI helper at planning/setup-studio-pages.php is specific to this local project and creates missing pages without overwriting existing assignments.

## Completed verification

Sixteen read-only checks passed for saved ACF copy, route-selected forms, nonce validity, registration fields and anti-spam controls, private-beta notice, assigned-page rendering, missing-page fallback, nested/duplicate block counting, and dynamic process/trust rendering. All changed PHP passed syntax checks and git diff --check passed. Browser checks found no horizontal overflow at desktop or narrow-phone widths after the wordmark fix. The story page rendered all seven steps and three assurances. No account was created and no YouTube write was made.

Registration generation prompt: A premium editorial 3:2 photograph with a tall 4:5 center crop. A young adult creator standing back from a tidy oak desk after finishing notes, relaxed small smile, simple charcoal shirt, tripod camera softly out of focus, blank ivory notebook, fountain pen and terracotta cup. Warm plaster wall, natural window light, realistic skin and tactile materials. Keep the upper left quieter for later HTML copy. No logos, readable writing, recognizable software, play triangles, watermarks, neon or baked-in graphics. Built-in ImageGen.

Staging MCP authentication was verified successfully against the configured local endpoint after correcting the existing token header mapping. The credential-free request helper is planning/staging-mcp.ps1 outside the public web root. It reads the existing private Codex config at runtime and never saves tokens in this project.
