# UploadWriter theme

The local WordPress homepage is **Pages → UploadWriter**. It contains four ACF blocks: Hero & workspace preview, Feature grid, How it works, and Workspace call to action. Use each block's fields to change its copy, links, and cards. Blank fields use the built-in defaults. The “UploadWriter homepage” block pattern inserts the complete layout on another page.

`front-page.php` renders the saved marketing blocks, with a complete default homepage if none are present. The four field groups are registered in PHP in `inc/uploadwriter.php`; no ACF JSON synchronization is required. ACF Pro must remain active to edit and render saved ACF blocks.

Shared styles live in `assets/uploadwriter.css`; the small responsive navigation script is `assets/uploadwriter.js`. They load directly with file modification timestamps, so these changes need no Gulp build. Existing Sass and compiled portfolio assets are retained for older page templates.

The video workspace continues to use the TubeWriter plugin and existing `/tubewriter/` routes, OAuth callbacks, stored settings, and AJAX handlers. Public branding is UploadWriter. Its updated cards, filters, editor, calendar, setup, and auth styles are scoped to `body.tw-app` in the shared stylesheet. The workspace overview uses actual indexed video counts and connection status.

Local changes do not deploy to UploadWriter.com. Deploy the theme and plugin changes together, and migrate the homepage and WordPress front-page/site-title options through the usual WordPress deployment process.

Public and app pages share `template-parts/uploadwriter-header.php`. Workspace navigation is a separate row of Videos, Schedule, and Keys & connections link tabs. The active Keys tab follows the setup deep link.

Homepage photography is documented in IMAGE-PROMPTS.md. Replace images using the Hero and How it works Section photograph fields or each feature card's Card photograph field. Blank fields keep the bundled generated photographs.

Pricing is at /pricing/ and is stored as an UploadWriter Pricing ACF block on Pages → Pricing. The introduction is editable in that block. Plan prices and allowances share inc/pricing-data.json with the economics report generator. This is labelled as launch pricing; there is no payment integration or enforcement change. The private cost model and competitor research live in I:/apps/tube-writer/planning outside the public web root.
