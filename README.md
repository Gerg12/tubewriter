[![Build Status](https://travis-ci.org/Automattic/_s.svg?branch=master)](https://travis-ci.org/Automattic/_s)

_s
===

Hi. I'm a starter theme called `_s`, or `underscores`, if you like. I'm a theme meant for hacking so don't use me as a Parent Theme. Instead try turning me into the next, most awesome, WordPress theme out there. That's what I'm here for.

My ultra-minimal CSS might make me look like theme tartare but that means less stuff to get in your way when you're designing your awesome theme. Here are some of the other more interesting things you'll find here:

* A modern workflow with a pre-made command-line interface to turn your project into a more pleasant experience.
* A just right amount of lean, well-commented, modern, HTML5 templates.
* A custom header implementation in `inc/custom-header.php`. Just add the code snippet found in the comments of `inc/custom-header.php` to your `header.php` template.
* Custom template tags in `inc/template-tags.php` that keep your templates clean and neat and prevent code duplication.
* Some small tweaks in `inc/template-functions.php` that can improve your theming experience.
* A script at `js/navigation.js` that makes your menu a toggled dropdown on small screens (like your phone), ready for CSS artistry. It's enqueued in `functions.php`.
* 2 sample layouts in `sass/layouts/` made using CSS Grid for a sidebar on either side of your content. Just uncomment the layout of your choice in `sass/style.scss`.
Note: `.no-sidebar` styles are automatically loaded.
* Smartly organized starter CSS in `style.css` that will help you to quickly get your design off the ground.
* Full support for `WooCommerce plugin` integration with hooks in `inc/woocommerce.php`, styling override woocommerce.css with product gallery features (zoom, swipe, lightbox) enabled.
* Licensed under GPLv2 or later. :) Use it to make something cool.

Installation
---------------

### Requirements

`_s` requires the following dependencies:

- [Node.js](https://nodejs.org/)
- [Composer](https://getcomposer.org/)

### Quick Start

Clone or download this repository, change its name to something else (like, say, `megatherium-is-awesome`), and then you'll need to do a six-step find and replace on the name in all the templates.

1. Search for `'_s'` (inside single quotations) to capture the text domain and replace with: `'megatherium-is-awesome'`.
2. Search for `_s_` to capture all the functions names and replace with: `megatherium_is_awesome_`.
3. Search for `Text Domain: _s` in `style.css` and replace with: `Text Domain: megatherium-is-awesome`.
4. Search for <code>&nbsp;_s</code> (with a space before it) to capture DocBlocks and replace with: <code>&nbsp;Megatherium_is_Awesome</code>.
5. Search for `_s-` to capture prefixed handles and replace with: `megatherium-is-awesome-`.
6. Search for `_S_` (in uppercase) to capture constants and replace with: `MEGATHERIUM_IS_AWESOME_`.

Then, update the stylesheet header in `style.css`, the links in `footer.php` with your own information and rename `_s.pot` from `languages` folder to use the theme's slug. Next, update or delete this readme.

### Setup

To start using all the tools that come with `_s`  you need to install the necessary Node.js and Composer dependencies :

```sh
$ composer install
$ npm install
```

### Available CLI commands

`_s` comes packed with CLI commands tailored for WordPress theme development :

- `composer lint:wpcs` : checks all PHP files against [PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- `composer lint:php` : checks all PHP files for syntax errors.
- `composer make-pot` : generates a .pot file in the `languages/` directory.
- `npm run compile:css` : compiles SASS files to css.
- `npm run compile:rtl` : generates an RTL stylesheet.
- `npm run watch` : watches all SASS files and recompiles them to css when they change.
- `npm run lint:scss` : checks all SASS files against [CSS Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/css/).
- `npm run lint:js` : checks all JavaScript files against [JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/).
- `npm run bundle` : generates a .zip archive for distribution, excluding development and system files.

Now you're ready to go! The next step is easy to say, but harder to do: make an awesome WordPress theme. :)

Good luck!

Homepage hero slider: edit the Hero block's optional Hero slides repeater to customize slide labels, headlines, descriptions, photos, and captions (up to five slides). Leaving it empty uses the three bundled creator slides; the original hero fields customize the first default slide. The primary CTA remains shared across slides. The carousel uses native JavaScript and existing responsive WebP assets, without an additional slider library.

Homepage feature, workflow, and CTA blocks use the cinematic homepage design. Their original ACF fields remain supported. Decorative SVGs are rendered by `uw_float_art()` and use the shared global palette, with responsive positioning, reduced-motion support, and a page-level decorative-motion pause control.

Browser and sharing identity is configured in `inc/brand.php`. The charcoal pen favicon set includes SVG, a multi-resolution ICO, PNGs, an Apple touch icon, and a web manifest. `assets/brand/uploadwriter-social.png` is the 1200×630 public Open Graph/X card. All metadata URLs follow WordPress's configured site URL; app links use generic branding rather than private video content. WordPress Site Icon is also set in this local database for admin/login screens. Regenerate raster artwork with `planning/generate-brand-assets.ps1` from the project root. Public social preview testing requires deployment to a publicly reachable domain; existing chat previews may remain cached.

Identity differentiation: the shared header/footer, favicons, WordPress Site Icon, and social card use a charcoal pen mark with a red underline. The wordmark treats Writer in editorial italic type. Workspace tabs use slate filled selection instead of a red underline, and owned promotional graphics avoid play-triangle brand marks. Red remains the shared accent. This is a visual differentiation pass, not trademark clearance or a complete YouTube API branding-compliance audit. Reference: https://developers.google.com/youtube/terms/branding-guidelines

Shared responsive containers: `--uw-container` is the single width token for the header, hero copy/navigation, homepage sections, footer, pricing, video library, schedule, and editor. It defaults to 1440px and uses the original hero maximum of `1800px` from 2000px upward. Hero-only and workspace-only width overrides were removed. Local text-length limits remain for readable copy. Browser measurements confirmed matching edges at 1920px, 2560px, 3440px, 1440px, and 320px with no page-level horizontal overflow.

Studio brand update: login and registration now use editable ACF Studio sign-in blocks, with new bundled and Media Library photographs. The Publishing process and Your final say blocks support the detailed How it works page. See STUDIO-DESIGN.md for fields, patterns, local page assignments, artwork prompts, validation, and migration instructions.
