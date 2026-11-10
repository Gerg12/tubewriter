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
- Upper right: the official game logo, fully inside the frame, smaller than the headline.
- Lower left: the commander and the GG Gregatron Games logo, grouped, about 100px from the left and bottom edges.
- Lower right: empty. YouTube covers that corner with a duration badge. Do not put the logo, the face, or text there.
- Add a picture-in-picture card only when the brief describes one. If it says no inset, do not add a frame. A thick gold frame that shoves the headline off the left edge is a failed thumbnail. An inset, when used, is smaller than the hero, with a thin dark military border.

COMMANDER
The recurring avatar is a bald brown-skinned man:
- smooth bald head
- thick dark eyebrows
- a simple dark mustache
- a clean-shaven jaw
- no goatee, no chin beard, no soul patch
- a red forehead scar
- a stern anime expression
- a small calico cat on his shoulder when the reference shows the cat
- military clothes that fit this battle

If a supplied screenshot already shows this commander in the lower left, keep that exact drawing. Do not invent a second bald man. Do not redraw his face, mustache, scar, cat, or uniform.

GG LOGO
Stacked mark beside the avatar: large orange GG with a heavy black outline, and GREGATRON GAMES in smaller white block letters underneath. Use this same mark every time. Keep it fully inside the frame and smaller than the headline.

GAME LOGO
Use the real logo of the game named in the brief. Do not invent a fake wordmark. Upper right, fully visible, secondary to the hook.

POLISH
Deeper shadows, clearer hero subject, brighter muzzle flashes, smoke, and fire that already belong in the shot. The image must still read as the actual game. Do not add a new army, a new tank, or a new building.
PROMPT;