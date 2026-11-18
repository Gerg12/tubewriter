# UploadWriter theme

The local WordPress homepage is **Pages → UploadWriter**. It contains four ACF blocks: Hero & workspace preview, Feature grid, How it works, and Workspace call to action. Use each block's fields to change its copy, links, and cards. Blank fields use the built-in defaults. The “UploadWriter homepage” block pattern inserts the complete layout on another page.

`front-page.php` renders the saved marketing blocks, with a complete default homepage if none are present. The four field groups are registered in PHP in `inc/uploadwriter.php`; no ACF JSON synchronization is required. ACF Pro must remain active to edit and render saved ACF blocks.

Shared styles live in `assets/uploadwriter.css`; the small responsive navigation script is `assets/uploadwriter.js`. They load directly with file modification timestamps, so these changes need no Gulp build. Existing Sass and compiled portfolio assets are retained for older page templates.

The video workspace continues to use the TubeWriter plugin and existing `/tubewriter/` routes, OAuth callbacks, stored settings, and AJAX handlers. Public branding is UploadWriter. Its updated cards, filters, editor, calendar, setup, and auth styles are scoped to `body.tw-app` in the shared stylesheet. The workspace overview uses actual indexed video counts and connection status.

Local changes do not deploy to UploadWriter.com. Deploy the theme and plugin changes together, and migrate the homepage and WordPress front-page/site-title options through the usual WordPress deployment process.

Public and app pages share `template-parts/uploadwriter-header.php`. Workspace navigation is a separate row of Videos, Schedule, and Keys & connections link tabs. The active Keys tab follows the setup deep link.

Homepage photography is documented in IMAGE-PROMPTS.md. Replace images using the Hero and How it works Section photograph fields or each feature card's Card photograph field. Blank fields keep the bundled generated photographs.

Pricing is at /pricing/ and is stored as an UploadWriter Pricing ACF block on Pages → Pricing. The introduction is editable in that block. Plan prices and allowances share inc/pricing-data.json with the economics report generator. This is labelled as launch pricing; there is no payment integration or enforcement change. The private cost model and competitor research live in I:/apps/tube-writer/planning outside the public web root.

Standard pages (including child pages) and single posts use the shared `content-document.php` layout and `assets/document.css`. The title and ancestor breadcrumbs are automatic. Content is a full-width grid with standard and wide tracks that both follow the shared site container (1440px, or 1800px on screens at least 2000px wide), with the same responsive gutters: select Wide width or Full width in the block toolbar. Custom top-level sections can use `uw-width-wide` or `uw-width-full`. Use a Full width Group with a constrained inner layout for edge-to-edge background bands with readable inner text. Full-width blocks need no viewport-width offsets or negative margins. Homepage, pricing, and workspace retain their own layouts. Width choices are also available in the editor via theme.json and align-wide support.

