<?php
/** Studio sign-in presentation. Form behavior lives in TW_Frontend. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$args = isset( $args ) && is_array( $args ) ? $args : array();
$route = get_query_var( 'tw_app' );
$read = function ( $name, $default ) use ( $args ) { return ! empty( $args['defaults'] ) ? $default : uw_field( $name, $default ); };
$view = in_array( $route, array( 'login', 'register' ), true ) ? $route : ( $args['view'] ?? $read( 'uw_auth_view', 'login' ) );
$register = 'register' === $view;
$beta = $register && TW_Settings::beta_access();
$heading = $read( 'uw_form_heading', $register ? 'Make yourself at home.' : 'Welcome back.' );
$lead = $read( 'uw_form_lead', $register ? 'Connect your channel. Bring your AI key. Make the first draft yours.' : 'Your next good draft starts here.' );
?>
<section class="uw-studio-auth<?php echo $register ? ' uw-studio-auth--register' : ''; ?>"<?php if ( ! empty( $block['anchor'] ) ) { echo ' id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
    <div class="uw-studio-auth__scene">
        <?php uw_image( $read( 'uw_image', 0 ), $register ? ( is_readable( get_template_directory() . '/assets/images/studio-invitation-960.webp' ) ? 'studio-invitation' : 'creator-planning' ) : ( is_readable( get_template_directory() . '/assets/images/studio-return-960.webp' ) ? 'studio-return' : 'creator-equipment' ), $register ? 'A creator standing beside an open notebook in a warm, sunlit home studio.' : 'A quiet creator desk with a notebook, pen, and camera equipment.', 'uw-studio-auth__photo', true, '(max-width: 760px) 100vw, 55vw' ); ?>
        <div class="uw-studio-auth__story">
            <p class="uw-eyebrow"><?php echo esc_html( $read( 'uw_eyebrow', $register ? 'A LITTLE SPACE FOR YOUR NEXT BIG IDEA' : 'MADE FOR THE WORK AFTER THE UPLOAD' ) ); ?></p>
            <h2><?php echo esc_html( $read( 'uw_heading', $register ? 'Your channel. Your voice. Your studio.' : 'Pick up where you left off.' ) ); ?></h2>
            <p><?php echo esc_html( $read( 'uw_description', 'AI helps with the first draft. You keep the last word.' ) ); ?></p>
        </div>
        <p class="uw-studio-auth__caption"><?php echo esc_html( $read( 'uw_caption', $register ? 'Made by you. Fine-tuned with UploadWriter.' : 'A clearer desk. A fresh perspective.' ) ); ?></p>
    </div>
    <div class="uw-studio-auth__paper">
        <a class="uw-studio-auth__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="UploadWriter.com home"><?php uw_brand(); ?></a>
        <p class="uw-eyebrow"><?php echo $register ? ( $beta ? 'YOUR PRIVATE STUDIO · PRIVATE BETA' : 'YOUR PRIVATE STUDIO' ) : 'YOUR CREATIVE CONTROL ROOM'; ?></p>
        <h1><?php echo esc_html( $heading ); ?></h1>
        <p class="uw-studio-auth__lead"><?php echo esc_html( $lead ); ?></p>
        <?php if ( $beta ) : ?><p class="uw-studio-auth__beta" role="note">UploadWriter is in private beta. Request access and we will email you when your account is approved.</p><?php endif; ?>
        <?php if ( ! empty( $is_preview ) ) : ?>
            <div class="uw-studio-auth__preview"><p>The secure <?php echo $register ? 'registration' : 'login'; ?> form appears here on the site.</p><p>Edit the photograph and copy in this block’s fields.</p></div>
        <?php elseif ( is_user_logged_in() ) : ?>
            <p>You are already logged in.</p><a class="uw-button" href="<?php echo esc_url( TW_Frontend::url( 'account' ) ); ?>">Open your workspace</a>
        <?php else : TW_Frontend::auth_form( $view ); endif; ?>
        <div class="uw-studio-auth__assurance"><span aria-hidden="true">✓</span><p>Your channel. Your keys. Your final say.</p></div>
    </div>
</section>
