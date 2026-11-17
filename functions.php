<?php
/**
 * HPM Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package HPM_Theme
 */

if ( ! defined( '_S_VERSION' ) ) {
	// Replace the version number of the theme on each release.
	define( '_S_VERSION', '1.0.0' );
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function hpm_theme_setup() {
	/*
		* Make theme available for translation.
		* Translations can be filed in the /languages/ directory.
		* If you're building a theme based on HPM Theme, use a find and replace
		* to change 'hpm-theme' to the name of your theme in all the template files.
		*/
	load_theme_textdomain( 'hpm-theme', get_template_directory() . '/languages' );

	// Add default posts and comments RSS feed links to head.
	add_theme_support( 'automatic-feed-links' );

	/*
		* Let WordPress manage the document title.
		* By adding theme support, we declare that this theme does not use a
		* hard-coded <title> tag in the document head, and expect WordPress to
		* provide it for us.
		*/
	add_theme_support( 'title-tag' );

	/*
		* Enable support for Post Thumbnails on posts and pages.
		*
		* @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		*/
	add_theme_support( 'post-thumbnails' );

	// This theme uses wp_nav_menu() in one location.
	register_nav_menus(
		array(
			'menu-1' => esc_html__( 'Primary', 'hpm-theme' ),
			'footer-menu' => esc_html__( 'Footer', 'hpm-theme' ),
			'mobile-menu' => esc_html__( 'Mobile', 'hpm-theme' ),
		)
	);

	/*
		* Switch default core markup for search form, comment form, and comments
		* to output valid HTML5.
		*/
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
		)
	);

	// Set up the WordPress core custom background feature.
	add_theme_support(
		'custom-background',
		apply_filters(
			'hpm_theme_custom_background_args',
			array(
				'default-color' => 'ffffff',
				'default-image' => '',
			)
		)
	);

	// Add theme support for selective refresh for widgets.
	add_theme_support( 'customize-selective-refresh-widgets' );

	/**
	 * Add support for core custom logo.
	 *
	 * @link https://codex.wordpress.org/Theme_Logo
	 */
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 250,
			'width'       => 250,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);
}
add_action( 'after_setup_theme', 'hpm_theme_setup' );

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function hpm_theme_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'hpm_theme_content_width', 640 );
}
add_action( 'after_setup_theme', 'hpm_theme_content_width', 0 );

/**
 * Register widget area.
 *
 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
 */
function hpm_theme_widgets_init() {
	register_sidebar(
		array(
			'name'          => esc_html__( 'Sidebar', 'hpm-theme' ),
			'id'            => 'sidebar-1',
			'description'   => esc_html__( 'Add widgets here.', 'hpm-theme' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'hpm_theme_widgets_init' );

/**
 * Enqueue scripts and styles.
 */
function hpm_theme_scripts() {

	wp_enqueue_style( 'hpm-theme-style', get_template_directory_uri() . '/dist/css/style.css', array(), _S_VERSION );
	wp_style_add_data( 'hpm-theme-style', 'rtl', 'replace' );
	wp_enqueue_script('jquery');

	wp_enqueue_script('select2', get_template_directory_uri() . '/dist/js/select2.min.js', array('jquery'), '4.1.0-rc.0', true);
	wp_enqueue_script( 'slick-scripts', get_template_directory_uri() . '/dist/js/slick.min.js', array(), _S_VERSION, true );
	wp_enqueue_script( 'aos-scripts', get_template_directory_uri() . '/dist/js/aos.js', array(), _S_VERSION, true );

	wp_enqueue_script( 'hpm-theme-scripts', get_template_directory_uri() . '/dist/js/scripts.js', array(), _S_VERSION, true );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'hpm_theme_scripts' );

/**
 * Implement the Custom Header feature.
 */
require get_template_directory() . '/inc/custom-header.php';

/**
 * Custom template tags for this theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Functions which enhance the theme by hooking into WordPress.
 */
require get_template_directory() . '/inc/template-functions.php';

/**
 * Customizer additions.
 */
require get_template_directory() . '/inc/customizer.php';

/**
 * Load Jetpack compatibility file.
 */
if ( defined( 'JETPACK__VERSION' ) ) {
	require get_template_directory() . '/inc/jetpack.php';
}

add_action('acf/init', 'my_acf_init_block_types');
function my_acf_init_block_types() {

	// Check function exists.
	if( function_exists('acf_register_block_type') ) {
		// register a Hero General block.
		acf_register_block_type(array(
			'name'              => 'hero-general',
			'title'             => __('Hero General'),
			'description'       => __('A Hero General block.'),
			'render_template'   => 'template-parts/blocks/hero-general.php',
			'category'          => 'layout',
			'icon'              => 'format-image',
			'mode'              => 'edit',
			'keywords'          => array( 'hero', 'general' ),
		));
		// register a Image Cards block.
		acf_register_block_type(array(
			'name'              => 'flex-cards',
			'title'             => __('Flex Cards block'),
			'description'       => __('A Flex Cards block.'),
			'render_template'   => 'template-parts/blocks/flex-cards.php',
			'category'          => 'layout',
			'icon'              => 'columns',
			'mode'              => 'edit',
			'keywords'          => array( 'flex', 'cards' ),
		));
		// register a Hero Split block.
		acf_register_block_type(array(
			'name'              => 'hero-split',
			'title'             => __('Hero Split block'),
			'description'       => __('A Hero Split block.'),
			'render_template'   => 'template-parts/blocks/hero-split.php',
			'category'          => 'layout',
			'icon'              => 'columns',
			'mode'              => 'edit',
			'keywords'          => array( 'hero', 'split' ),
		));
		// register a Post Cards block.
		acf_register_block_type(array(
			'name'              => 'post-cards',
			'title'             => __('Post Cards block'),
			'description'       => __('A Post Cards block.'),
			'render_template'   => 'template-parts/blocks/post-cards.php',
			'category'          => 'layout',
			'icon'              => 'columns',
			'mode'              => 'edit',
			'keywords'          => array( 'post', 'cards' ),
		));
		// register a Blog List block.
		acf_register_block_type(array(
			'name'              => 'blog-list',
			'title'             => __('Blog List block'),
			'description'       => __('A Blog List block.'),
			'render_template'   => 'template-parts/blocks/blog-list.php',
			'category'          => 'layout',
			'icon'              => 'columns',
			'mode'              => 'edit',
			'keywords'          => array( 'blog', 'list' ),
		));

		// register a Graphic Block block.
		acf_register_block_type(array(
			'name'              => 'graphic-block',
			'title'             => __('Graphic Block block'),
			'description'       => __('A Graphic Block block.'),
			'render_template'   => 'template-parts/blocks/graphic-block.php',
		));

		// register a Service Block block.
		acf_register_block_type(array(
			'name'              => 'service-block',
			'title'             => __('Service Block block'),
			'description'       => __('A Service Block block.'),
			'render_template'   => 'template-parts/blocks/service-block.php',
			'category'          => 'layout',
			'icon'              => 'admin-tools',
			'mode'              => 'edit',
			'keywords'          => array( 'service', 'block' ),
			'supports'          => array(
				'anchor' => true,
			),
		));

		// register a Portfolio Block block.
		acf_register_block_type(array(
			'name'              => 'portfolio-block',
			'title'             => __('Portfolio Block block'),
			'description'       => __('A Portfolio Block block.'),
			'render_template'   => 'template-parts/blocks/portfolio-block.php',
			'category'          => 'layout',
			'icon'              => 'portfolio',
			'mode'              => 'edit',
			'keywords'          => array( 'portfolio', 'block' ),
			'supports'          => array(
				'anchor' => true,
			),
		));

		// register a Alternating Sections block.
		acf_register_block_type(array(
			'name'              => 'alternating-sections',
			'title'             => __('Alternating Sections block'),
			'description'       => __('A Alternating Sections block.'),
			'render_template'   => 'template-parts/blocks/alternating-sections.php',
			'category'          => 'layout',
			'icon'              => 'columns',
			'mode'              => 'edit',
			'keywords'          => array( 'alternating', 'sections' ),
			'supports'          => array(
				'anchor' => true,
			),
		));
	}
}

// Add ACF Options Page
if (function_exists('acf_add_options_page')) {
	acf_add_options_page(array(
		'page_title'    => 'Site Options',
		'menu_title'    => 'Site Options',
		'menu_slug'     => 'site-options',
		'capability'    => 'edit_posts',
		'redirect'      => false,
		'position'      => '59.3',
		'icon_url'      => 'dashicons-admin-generic'
	));
}

function custom_admin_styles() {
	wp_enqueue_style('admin-styles', get_template_directory_uri() . '/dist/css/editor-style.css');
}
add_action('admin_enqueue_scripts', 'custom_admin_styles');

/**
 * Enqueue block editor assets
 */
function theme_block_editor_assets() {
    $block_dir = get_template_directory() . '/dist/js/blocks';
    $block_files = glob($block_dir . '/*.js');

    foreach ($block_files as $file) {
        $filename = basename($file, '.js');
        wp_enqueue_script(
            'theme-block-' . $filename,
            get_template_directory_uri() . '/dist/js/blocks/' . basename($file),
            array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor'),
            filemtime($file),
            true
        );
    }
}
add_action('enqueue_block_editor_assets', 'theme_block_editor_assets');


require get_template_directory() . '/inc/tubewriter/tubewriter.php';

require get_template_directory() . '/inc/uploadwriter.php';

require get_template_directory() . '/inc/pricing.php';

require get_template_directory() . '/inc/brand.php';

require get_template_directory() . '/inc/document.php';
