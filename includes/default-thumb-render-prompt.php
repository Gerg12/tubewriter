<?php
// Standing instructions for the image model (editable in TubeWriter > Settings).
// This is the "custom instructions" layer. The approved per-video brief is appended after it.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return <<<'PROMPT'
You polish one Gregatron Games YouTube thumbnail from the supplied gameplay screenshot. The screenshot stays the foundation. Do not replace it with a new illustration.

CANVAS
- Exact 16:9 widescreen. The saved file is 1920x1080.
- Never square. Never portrait. Never add black bars.
- Every letter of the headline is fully painted inside the frame. The first letter is not cut off. Leave at least 100px of image to the left of the text, above it, and to the right of the end of each line.
- The failure to avoid: a headline sliced on the left edge, such as a missing 7 or a word that starts halfway through a letter.

LAYOUT
Match this arrangement:
- The gameplay screenshot fills the whole frame. Grade it. Do not repaint the battlefield, the vehicles, or the soldiers.
- Upper left: two huge lines from the brief. Line 1 is larger. Line 1 is white distressed heavy block lettering with a thick black shadow and a fiery orange stroke. Line 2 is amber-orange with the same heavy black shadow. Military stencil, chipped, not a thin font. Spell the brief's lines exactly.
- Upper right: the official game logo when there is no inset, fully inside the frame, smaller than the headline.
- Lower left: the character already in the screenshot, plus the GG Gregatron Games logo, grouped, about 100px from the left and bottom edges.
- Lower right: empty unless the game logo moved here because of a picture-in-picture. YouTube covers that corner with a duration badge.
- Add a picture-in-picture card only when the brief describes one. If it says no inset, do not add a frame. A thick gold frame that shoves the headline off the left edge is a failed thumbnail. An inset, when used, is smaller than the hero, with a thin dark military border. When an inset is used, move the game logo to the bottom right, raised above the duration badge.

LOWER-LEFT CHARACTER
Look at the lower left of the gameplay screenshot. That character changes from video to video.
- If a character, avatar, or reaction face is already there, keep that exact drawing. Face, outfit, colors, mascot, and pose stay as drawn.
- Do not invent a different person. Do not swap in a generic bald commander when the screenshot already has someone else.
- Do not redraw him into a new costume. This video's look is whatever is already in the shot.
- If the lower left is empty, leave that corner for the GG logo only. Do not invent a face.

GG LOGO
Stacked mark beside the lower-left character: large orange GG with a heavy black outline, and GREGATRON GAMES in smaller white block letters underneath. If a channel logo file is attached, use that exact file. Keep it fully inside the frame and smaller than the headline.

GAME LOGO
If an official game logo file is attached, place that exact artwork. Do not invent a fake wordmark. Do not redraw the letters. Prefer the attached PNG over memory.

POLISH
Deeper shadows, clearer hero subject, brighter muzzle flashes, smoke, and fire that already belong in the shot. The image must still read as the actual game. Do not add a new army, a new tank, or a new building.
PROMPT;