<?php
// Standing instructions for the image model (editable in TubeWriter > Settings).
// This is the "custom instructions" layer. The approved per-video brief is appended after it.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return <<<'PROMPT'
You polish one Gregatron Games YouTube thumbnail from the supplied gameplay screenshot. The screenshot stays the foundation. Do not replace it with a new illustration.

CANVAS AND EDGE SAFETY
- Exact 16:9 widescreen. The saved file is 1920x1080.
- Never square. Never portrait. Never add black bars.
- Start with the frame: gameplay may fill edge to edge. Every graphic (headline, logo, corner character, inset card) must sit inside the central 84 percent of the canvas. That is about 8 percent empty gameplay padding from every edge.
- Backgrounds may bleed to the rim. Text, logos, borders, helmets, and inset corners may not. If a graphic does not fit, scale it down. Never crop it. Never push it flush.
- Place graphics in named boxes, not against the rim:
  - Headline box: upper-left content area, starting about 8 percent in from the top and 8 percent in from the left.
  - Game logo box: upper-right content area, inset the same 8 percent, or lower-right when an inset card is used.
  - Channel / corner character box: lower-left content area, inset the same 8 percent from the left and bottom.
- Failed output looks like: letters kissing the top, a logo kissing the top-right, faces cut by the bottom, an inset frame flush to the rim.

LAYOUT
Match this arrangement:
- Image 1 always fills the whole frame as the main background. Grade it, deepen contrast, and clear the hero moment that is already in that shot. Do not repaint the scene as a new artwork.
- When Image 2 is attached, it is only the picture-in-picture card. Never swap Image 1 and Image 2. Never paint Image 2 as the background. Never paint Image 1 as the inset.
- Do not invent a new foreground hero. No Pixar face, no fan-art Link, no new soldier, no new mascot standing in front of the gameplay. The people and creatures in Image 1 are the only characters allowed in the main frame.
- Upper left: two huge lines from the brief. Line 1 is larger. Line 1 is white distressed heavy block lettering with a thick black shadow and a fiery orange stroke. Line 2 is amber-orange with the same heavy black shadow. Heavy chipped block lettering, not a thin font. Spell the brief's lines exactly. Keep both lines inside the headline box.
- Upper right: exactly one official game logo when there is no inset, inside the game logo box, smaller than the headline.
- Lower left: only a character that is already drawn in the screenshot, plus the GG Gregatron Games logo, grouped, inside the channel box.
- Lower right: empty unless the game logo moved here because of a picture-in-picture. YouTube covers that corner with a duration badge.
- Add a picture-in-picture card when Image 2 is attached or the brief describes one. If the brief says no inset and only Image 1 is attached, do not add a frame. A thick gold frame that shoves the headline off the left edge is a failed thumbnail. An inset is smaller than the hero, shows Image 2, has a thin dark border, sits fully inside the central 84 percent, and never flushes to the top or right edge. When an inset is used, move the single game logo to the bottom right, raised above the duration badge and still inside the central 84 percent.

MAIN SUBJECT FROM THE SCREENSHOT
- The hero of the thumbnail is whatever Image 1 already shows.
- Copy that look. Same face, outfit, body, art style, and camera.
- Do not replace the in-game character with a different redesign, a 3D cartoon, or a figure from memory of the franchise.
- If Image 1 is a wide environment shot with no close-up face, keep it as an environment shot. Do not invent a close-up character to fill the frame.

LOWER-LEFT CHARACTER
Look at the lower left of the gameplay screenshot. That corner changes from video to video.
- If a character, avatar, or reaction face is already drawn there in the screenshot, keep that exact drawing. Face, outfit, colors, mascot, and pose stay as drawn.
- If the lower left of the screenshot is empty gameplay, leave that corner for the GG logo only. Do not invent a face, anime figure, cat, or mascot.
- Do not invent a different person. Do not import a character from another video.

GG LOGO
Stacked mark in the lower left: large orange GG with a heavy black outline, and GREGATRON GAMES in smaller white block letters underneath. If a channel logo file is attached, use that exact file. Keep it fully inside the channel box and smaller than the headline.

GAME LOGO (EXACTLY ONE)
- Place exactly one official game logo. Never two. Never a large logo plus a small copy.
- If an official game logo file is attached, place that exact artwork once in the corner the brief or logo instruction names. Do not invent a fake wordmark. Do not redraw the letters. Do not paint a second logo from memory beside it.
- Prefer the attached PNG over memory. If no logo file is attached, paint the authentic official mark once only.
- Keep that single logo fully inside its logo box and smaller than the headline.

POLISH
Deeper shadows, clearer hero subject already in the shot, brighter effects that already belong in the shot. The image must still read as the actual game footage. Do not add a new army, a new building, or a new lead character.
PROMPT;
