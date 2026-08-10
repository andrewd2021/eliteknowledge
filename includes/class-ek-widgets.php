<?php
/**
 * Classic (Widgets screen / widget block) sidebar widgets.
 *
 * RECONSTRUCTED FILE — the EK_Widgets shell and the start of
 * EK_Widget_Recent_Discussions::widget() are restored verbatim from a
 * saved partial read; the rest of that widget and the other two widget
 * classes are rebuilt to match the same conventions used elsewhere in
 * this plugin (the .ek-widget-list markup content-*.php templates already
 * use, EK_Verification/EK_Forum's public API), not restored from an
 * original read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Widgets {

	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_widget( 'EK_Widget_Recent_Discussions' );
		register_widget( 'EK_Widget_Popular_Topics' );
		register_widget( 'EK_Widget_Faq' );
	}
}

class EK_Widget_Recent_Discussions extends WP_Widget {

	public function __construct() {
		parent::__construct( 'ek_recent_discussions', __( 'EK: Recent Discussions', 'elite-knowledge' ), array(
			'description' => __( 'Shows the most recently active forum discussions.', 'elite-knowledge' ),
		) );
	}

	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Recent Discussions', 'elite-knowledge' );
		$count = ! empty( $instance['count'] ) ? absint( $instance['count'] ) : 5;

		$query = new WP_Query( array(
			'post_type'      => 'ek_discussion',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		if ( ! $query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<ul class="ek-widget-list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		echo '</ul>';
		wp_reset_postdata();

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'Recent Discussions', 'elite-knowledge' );
		$count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'elite-knowledge' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number to show:', 'elite-knowledge' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" step="1" value="<?php echo esc_attr( $count ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['count'] = absint( $new_instance['count'] );
		return $instance;
	}
}

class EK_Widget_Popular_Topics extends WP_Widget {

	public function __construct() {
		parent::__construct( 'ek_popular_topics', __( 'EK: Popular Topics', 'elite-knowledge' ), array(
			'description' => __( 'Shows the most-viewed knowledge topics.', 'elite-knowledge' ),
		) );
	}

	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Popular Topics', 'elite-knowledge' );
		$count = ! empty( $instance['count'] ) ? absint( $instance['count'] ) : 5;

		$query = new WP_Query( array(
			'post_type'      => 'ek_topic',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'orderby'        => 'comment_count',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		if ( ! $query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<ul class="ek-widget-list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		echo '</ul>';
		wp_reset_postdata();

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'Popular Topics', 'elite-knowledge' );
		$count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'elite-knowledge' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number to show:', 'elite-knowledge' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" step="1" value="<?php echo esc_attr( $count ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['count'] = absint( $new_instance['count'] );
		return $instance;
	}
}

class EK_Widget_Faq extends WP_Widget {

	public function __construct() {
		parent::__construct( 'ek_faq_widget', __( 'EK: FAQ', 'elite-knowledge' ), array(
			'description' => __( 'Shows a short list of FAQ questions.', 'elite-knowledge' ),
		) );
	}

	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Frequently Asked Questions', 'elite-knowledge' );
		$count = ! empty( $instance['count'] ) ? absint( $instance['count'] ) : 5;

		$query = new WP_Query( array(
			'post_type'      => 'ek_faq',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		if ( ! $query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<ul class="ek-widget-list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		echo '</ul>';
		wp_reset_postdata();

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'Frequently Asked Questions', 'elite-knowledge' );
		$count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'elite-knowledge' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number to show:', 'elite-knowledge' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" step="1" value="<?php echo esc_attr( $count ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['count'] = absint( $new_instance['count'] );
		return $instance;
	}
}
