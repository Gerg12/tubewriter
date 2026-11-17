<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Videos page. Reads only from the local index (TW_Index), so filtering,
 * searching, sorting and paging cost zero YouTube API units.
 */
class TW_Videos_Table extends WP_List_Table {

	private $filters = array();

	public function __construct() {
		parent::__construct( array( 'singular' => 'video', 'plural' => 'videos', 'ajax' => false ) );
	}

	private function arg( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore
	}

	public function get_columns() {
		return array(
			'thumb'      => '',
			'title'      => 'Video',
			'visibility' => 'Visibility',
			'date'       => 'Date',
			'views'      => 'Views',
			'length'     => 'Length',
			'tw'         => 'TubeWriter',
			'action'     => '',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'  => array( 'title', false ),
			'date'   => array( 'date', true ),
			'views'  => array( 'views', true ),
			'length' => array( 'length', true ),
		);
	}

	public function prepare_items() {
		$this->filters = array(
			'search'     => $this->arg( 's' ),
			'title'      => $this->arg( 'title' ),
			'visibility' => $this->arg( 'visibility' ),
			'views_min'  => '' !== $this->arg( 'vmin' ) ? (string) absint( $this->arg( 'vmin' ) ) : '',
			'views_max'  => '' !== $this->arg( 'vmax' ) ? (string) absint( $this->arg( 'vmax' ) ) : '',
			'tw'         => $this->arg( 'tw' ),
			'orderby'    => $this->arg( 'orderby' ) ?: 'date',
			'order'      => $this->arg( 'order' ) ?: 'desc',
			'per_page'   => $this->arg( 'per_page' ) ?: 50,
			'paged'      => $this->get_pagenum(),
		);
		$result = TW_Index::query( $this->filters );

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $result['per_page'],
				'total_pages' => (int) ceil( $result['total'] / $result['per_page'] ),
			)
		);
	}

	/** Visibility tabs with counts, like YouTube's filter. */
	protected function get_views() {
		$counts  = TW_Index::counts();
		$current = $this->arg( 'visibility' );
		$labels  = array( '' => 'All', 'public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private', 'scheduled' => 'Scheduled', 'draft' => 'Draft' );
		$views   = array();
		foreach ( $labels as $key => $label ) {
			$url           = $key ? add_query_arg( 'visibility', $key, remove_query_arg( array( 'paged', 'visibility' ) ) ) : remove_query_arg( array( 'paged', 'visibility' ) );
			$n             = $counts[ $key ?: 'all' ];
			$views[ $key ?: 'all' ] = sprintf( '<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url( $url ), $current === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ), number_format_i18n( $n ) );
		}
		return $views;
	}

	/** Filter bar above the table: title, views range, TubeWriter status, rows per page. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f = $this->filters;
		?>
		<div class="alignleft actions tw-filters">
			<input type="text" name="title" value="<?php echo esc_attr( $f['title'] ); ?>" placeholder="Title contains" class="tw-f-title">
			<select name="visibility">
				<?php foreach ( array( '' => 'Any visibility', 'public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private', 'scheduled' => 'Scheduled', 'draft' => 'Draft' ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['visibility'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="tw-f-views">Views
				<input type="number" min="0" name="vmin" value="<?php echo esc_attr( $f['views_min'] ); ?>" placeholder="min">
				to
				<input type="number" min="0" name="vmax" value="<?php echo esc_attr( $f['views_max'] ); ?>" placeholder="max">
			</span>
			<select name="tw">
				<?php foreach ( array( '' => 'Any TubeWriter status', 'none' => 'Not started', 'draft' => 'Draft ready', 'pushed' => 'Published with TubeWriter' ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['tw'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="per_page">
				<?php foreach ( array( 25, 50, 100, 200 ) as $n ) : ?>
					<option value="<?php echo (int) $n; ?>" <?php selected( (int) $f['per_page'], $n ); ?>><?php echo (int) $n; ?> per page</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
			<?php if ( array_filter( array( $f['search'], $f['title'], $f['visibility'], $f['views_min'], $f['views_max'], $f['tw'] ), 'strlen' ) ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tubewriter' ) ); ?>">Clear filters</a>
			<?php endif; ?>
		</div>
		<?php
	}

	public function no_items() {
		if ( ! TW_Index::count_all() ) {
			echo 'No videos indexed yet. Click Full sync above.';
			return;
		}
		if ( 'scheduled' === ( $this->filters['visibility'] ?? '' ) ) {
			echo 'No scheduled videos in the index. These are private videos with a future go-live date on YouTube. They do not appear in the normal uploads list, so run Quick or Full sync to pull them in. Use the <strong>Scheduled</strong> tab in the row above (orange count).';
			return;
		}
		if ( 'draft' === ( $this->filters['visibility'] ?? '' ) ) {
			echo 'No Studio drafts in the index. YouTube often leaves drafts out of the uploads playlist, same as scheduled videos. Run Quick or Full sync, then open the <strong>Draft</strong> tab above.';
			return;
		}
		echo 'No videos match these filters.';
	}

	private function edit_url( $id ) {
		return admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $id ) );
	}

	private static function has_local_file( $file ) {
		return is_string( $file ) && file_exists( $file ) && filesize( $file ) > 200;
	}

	protected function column_default( $item, $col ) {
		switch ( $col ) {
			case 'thumb':
				$src = TW_Index::thumb_url_with_version( $item['id'] ?? '', (string) ( $item['thumb'] ?? '' ) );
				if ( ! $src ) {
					return '';
				}
				// Bust stale browser caches after we replace expired YouTube URLs with local files.
				$paths = TW_Index::local_thumb_paths( $item['id'] );
				if ( $paths && self::has_local_file( $paths['file'] ) ) {
					$src = add_query_arg( 'v', (string) filemtime( $paths['file'] ), $paths['url'] );
				}
				return '<img src="' . esc_url( $src ) . '" width="96" height="54" loading="lazy" alt="">';
			case 'visibility':
				$sched = 'private' === $item['privacy'] && $item['publish_at'] && strtotime( $item['publish_at'] . ' UTC' ) > time();
				$key   = $sched ? 'scheduled' : $item['privacy'];
				return '<span class="tw-badge tw-badge-' . esc_attr( $key ) . '">' . esc_html( $key ) . '</span>';
			case 'date':
				if ( 'private' === $item['privacy'] && $item['publish_at'] ) {
					return 'Goes live<br>' . esc_html( wp_date( 'M j, Y g:ia', strtotime( $item['publish_at'] . ' UTC' ) ) );
				}
				if ( ! $item['published_at'] ) {
					return '';
				}
				$kind = in_array( $item['privacy'], array( 'private', 'draft' ), true ) ? 'Uploaded' : 'Published';
				return $kind . '<br>' . esc_html( wp_date( 'M j, Y', strtotime( $item['published_at'] . ' UTC' ) ) );
			case 'views':
				return esc_html( number_format_i18n( (int) $item['views'] ) );
			case 'length':
				return $item['duration'] ? esc_html( TW_Transcript::fmt( (int) $item['duration'] ) ) : '';
			case 'tw':
				$map = array( 'started' => 'Started', 'draft' => 'Draft ready', 'pushed' => 'Published' );
				$out = isset( $map[ $item['tw_status'] ] ) ? '<span class="tw-tw tw-tw-' . esc_attr( $item['tw_status'] ) . '">' . esc_html( $map[ $item['tw_status'] ] ) . '</span>' : '';
				$cap = (string) ( $item['captions_status'] ?? '' );
				if ( 'ok' === $cap ) {
					$out .= ( $out ? '<br>' : '' ) . '<span class="tw-cap tw-cap-ok">Captions pulled</span>';
				} elseif ( 'none' === $cap ) {
					$out .= ( $out ? '<br>' : '' ) . '<span class="tw-cap tw-cap-none">No captions</span>';
				}
				return $out;
			case 'action':
				return '<a class="button button-primary" href="' . esc_url( $this->edit_url( $item['id'] ) ) . '">Edit</a>';
		}
		return '';
	}

	protected function column_title( $item ) {
		$id  = $item['id'];
		$out = '<a class="row-title" href="' . esc_url( $this->edit_url( $id ) ) . '">' . esc_html( $item['title'] ) . '</a>';
		$out .= $this->row_actions(
			array(
				'edit'    => '<a href="' . esc_url( $this->edit_url( $id ) ) . '">Edit</a>',
				'refresh' => '<button type="button" class="button-link tw-row-refresh" data-refresh-video="' . esc_attr( $id ) . '" title="About 1 API unit">Refresh</button>',
				'watch'   => '<a href="' . esc_url( 'https://www.youtube.com/watch?v=' . rawurlencode( $id ) ) . '" target="_blank" rel="noopener">Watch</a>',
				'studio'  => '<a href="' . esc_url( 'https://studio.youtube.com/video/' . rawurlencode( $id ) . '/edit' ) . '" target="_blank" rel="noopener">YouTube Studio</a>',
			)
		);
		if ( '' !== trim( (string) $item['excerpt'] ) ) {
			$out .= '<p class="tw-excerpt">' . esc_html( wp_trim_words( $item['excerpt'], 24 ) ) . '</p>';
		}
		return $out;
	}
}
