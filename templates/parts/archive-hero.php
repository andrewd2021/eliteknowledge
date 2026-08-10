<?php
/**
 * Gradient hero banner shown at the top of every archive page: an icon,
 * title, subtitle, and a content-count pill.
 *
 * RECONSTRUCTED FILE — rebuilt to match its call signature (icon, title,
 * subtitle, count, variant) as used by every archive-ek_*.php template,
 * both restored-intact (archive-ek_forum.php) and reconstructed. Markup
 * and class names are rebuilt to match the plugin's established
 * conventions (.ek-archive-title reused from single views, .ek-hub-card-*
 * naming pattern for the icon/count chips) — not restored from an
 * original read, so may not be byte-identical to the original design.
 *
 * @var string $icon      Icon name passed to EK_Icons::get().
 * @var string $title     Section title, e.g. "Knowledge Topics".
 * @var string $subtitle  One-line description.
 * @var string $count     Pre-formatted count label, e.g. "8 articles".
 * @var string $variant   Section slug used for per-section accent styling
 *                        (topics, forums, faq, documents).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ek-hero ek-hero--<?php echo esc_attr( $variant ); ?>">
	<span class="ek-hero-icon"><?php echo EK_Icons::get( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<div class="ek-hero-body">
		<h1 class="ek-archive-title"><?php echo esc_html( $title ); ?></h1>
		<?php if ( ! empty( $subtitle ) ) : ?>
			<p class="ek-hero-subtitle"><?php echo esc_html( $subtitle ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $count ) ) : ?>
		<span class="ek-hero-count"><?php echo esc_html( $count ); ?></span>
	<?php endif; ?>
</div>
