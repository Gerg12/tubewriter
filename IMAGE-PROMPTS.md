# Homepage imagery

Generated with the built-in ImageGen tool for UploadWriter.com. All three scenes are illustrative AI-generated photography, not customer photographs or testimonials. The source PNGs remain in the Codex generated-images folder; the project uses local optimized WebP files.

## Saved assets

`assets/images/creator-recording-{640,960,1536}.webp`
`assets/images/creator-equipment-{640,960,1536}.webp`
`assets/images/creator-editing-{640,960,1536}.webp`

Each image has 640px, 960px, and 1536px variants. All nine files total about 685 KB. The hero loads eagerly with high priority; supporting images load lazily. The browser selects a responsive source through srcset.

## Final prompts

### Creator recording

Use case: photorealistic-natural. Asset type: UploadWriter.com homepage editorial hero photograph. Create one premium editorial lifestyle photograph, landscape 3:2 composition: a young adult female independent video creator in a warm, lived-in home studio, seated at a walnut desk, recording a thoughtful talking-head video to a compact mirrorless camera on a small tripod in the foreground. Camera body clearly in foreground right, creator midground left with natural relaxed expression, a small desktop microphone and open notebook, subtle burnt orange accent and cream walls, daylight from side window, gentle shadows, filmic realistic skin and tactile wood grain. Art directed and candid, polished but human, quiet confident creativity. Neutral warm white, charcoal, terracotta palette matching an editorial web design. No brand names, no logos, no readable text, no watermark, no UI or graphic overlays. Realistic anatomy and functional camera equipment. The image will be cropped to a wide thumbnail as well as a taller homepage feature, so keep principal subjects inside central 80 percent.

### Equipment

Use case: photorealistic-natural. Asset type: supporting editorial website image for UploadWriter.com, the YouTube creator workspace. One landscape 3:2 premium editorial still-life photograph: a compact unbranded black mirrorless camera with a short lens, studio headphones, a small charcoal microphone, and a closed cream notebook, arranged intentionally but naturally on a warm pale oak desktop. Burnt-orange paper backdrop curving up behind the objects, directional soft afternoon light from the left, beautifully tactile materials, natural grain, subtle shadows, contemporary independent creator aesthetic. Close-up equipment photograph, graphic composition with breathing room, elevated creative magazine art direction. No hands or people, no brand labels, no readable text, no watermarks, no collages, no UI. Realistic equipment shapes and optics.

### Editing

Use case: photorealistic-natural. Asset type: editorial supporting photo for UploadWriter.com YouTube creator website. One landscape 3:2 cinematic editorial photograph. Over-the-shoulder view of an adult male independent video creator in a cream cotton shirt, seated at a tidy walnut editing desk, calmly reviewing a video on a large desktop monitor. Monitor shows a simple blurred video editing layout with footage of an orange sunlit landscape, an indistinct timeline, absolutely no readable text and no recognizable software brand. A notebook and black headphones on desk, small warm desk lamp, subtle acoustic wall panels and cream plaster, a terracotta cup. Warm natural daylight mixed with warm task light, candid working moment, premium creative magazine photography, shallow depth of field but clear human and equipment silhouettes, subtle film texture, charcoal, warm ivory, burnt orange palette. Avoid brand logos, text overlays, watermarks, distorted hands, excessive screens or neon lighting.

## Editing in WordPress

The Hero and How it works ACF blocks each have a Section photograph field. Feature cards have individual Card photograph fields. Blank fields use the bundled photographs. Select a Media Library image to override one and set its alt text in the Media Library. Custom images use WordPress-generated responsive sizes.

## Workflow and closing-card replacements

Generated using the built-in image_gen tool. Workflow uses `creator-planning-{640,960,1536}.webp`, showing a creator planning a video with a notebook and laptop. The closing card uses `creator-storyboard-{640,960,1536}.webp`, showing a storyboard sketchbook, tablet, pencil, and coffee. Full production prompts are in `planning/homepage-replacement-image-prompts.md`. These two photographs are unique to their sections; existing hero and feature photographs remain unchanged. Both sections support an optional ACF Section photograph override. Workflow srcset sizing accounts for its tall desktop crop.
