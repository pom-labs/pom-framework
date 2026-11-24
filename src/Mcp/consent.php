<?php
/**
 * POM MCP OAuth consent screen.
 *
 * @var array $data Authorization request data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client       = $data['client'];
$oauth_fields = array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Authorize POM MCP', 'pom-framework' ); ?></title>
	<?php wp_admin_css( 'login', true ); ?>
	<style>
		.pom-mcp-consent { max-width: 520px; margin: 8vh auto; background: #fff; border: 1px solid #dcdcde; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 24px; }
		.pom-mcp-consent h1 { margin-top: 0; font-size: 22px; }
		.pom-mcp-consent code { word-break: break-all; }
		.pom-mcp-consent__actions { display: flex; gap: 10px; align-items: center; margin: 18px 0; }
		.pom-mcp-consent__actions button { border: none;background: #000;color: #fff;padding: 15px 30px;cursor:pointer; }
	</style>
</head>
<body class="login">
	<div class="pom-mcp-consent">
		<h1><?php esc_html_e( 'Authorize POM MCP access', 'pom-framework' ); ?></h1>
		<p style="margin-bottom: 0.5rem;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: OAuth client name. */
					__( '%s is requesting access to this WordPress site through POM MCP.', 'pom-framework' ),
					$client['client_name']
				)
			);
			?>
		</p>
		<p style="margin-bottom: 0.5rem;"><strong><?php esc_html_e( 'User', 'pom-framework' ); ?>:</strong> <?php echo esc_html( wp_get_current_user()->user_login ); ?></p>
		<p style="margin-bottom: 0.5rem;"><strong><?php esc_html_e( 'Resource', 'pom-framework' ); ?>:</strong><br><code><?php echo esc_html( $data['resource'] ); ?></code></p>
		<p style="margin-bottom: 0.5rem;"><strong><?php esc_html_e( 'Scopes', 'pom-framework' ); ?>:</strong><br> <?php echo implode( '<br>', array_map( 'esc_html', $data['scopes'] ) ); ?></p>
		<?php if ( in_array( \POMFramework\Mcp\Settings::WRITE_SCOPE, $data['scopes'], true ) ) : ?>
			<p><?php esc_html_e( 'This connection may request changes within your WordPress permissions. Each change requires a separate review.', 'pom-framework' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'This connection may use the read-only POM MCP capabilities available to your WordPress account.', 'pom-framework' ); ?></p>
		<?php endif; ?>
		<?php if ( in_array( \POMFramework\Mcp\Network::READ, $data['scopes'], true ) ) : ?>
			<p><?php esc_html_e( 'This connection can also read the destinations enabled in POM MCP Network Settings. Any granted network writes are limited to enabled groups and require a separate decision on this main site.', 'pom-framework' ); ?></p>
		<?php endif; ?>
		<?php $gateway_consent = \POMFramework\Mcp\AccountGateway::consent(); ?>
		<?php if ( $gateway_consent !== '' ) : ?>
			<p><?php echo esc_html( $gateway_consent ); ?></p>
		<?php endif; ?>
		<?php if ( in_array( \POMFramework\Mcp\AccountDestination::SCOPE, $data['scopes'], true ) ) : ?>
			<p><?php esc_html_e( 'This account connection uses the site’s authoring catalogue. Approve each requested change from a signed-in WordPress session on this site.', 'pom-framework' ); ?></p>
		<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'pom_mcp_oauth_authorize', 'pom_mcp_oauth_nonce' ); ?>
				<?php foreach ( $oauth_fields as $key ) : ?>
					<?php
					// OAuth authorization request parameters are validated server-side before this template renders.
					if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
						continue;
					}
					$value = sanitize_text_field( (string) wp_unslash( $_GET[ $key ] ) );
					?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endforeach; ?>
			<div class="pom-mcp-consent__actions">
				<button type="submit" name="approve" value="1" class="button button-primary button-large"><?php esc_html_e( 'Authorize', 'pom-framework' ); ?></button>
				<button type="submit" name="deny" value="1" class="button button-secondary button-large"><?php esc_html_e( 'Deny', 'pom-framework' ); ?></button>
			</div>
		</form>
	</div>
</body>
</html>
