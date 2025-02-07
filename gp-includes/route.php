<?php
/**
 * Base controller class
 */
class GP_Route {

	public $api = false;

	public $errors  = array();
	public $notices = array();

	var $request_running = false;
	var $template_path   = null;

	var $fake_request = false;
	var $exited       = false;
	var $exit_message;
	var $redirected        = false;
	var $redirected_to     = null;
	var $rendered_template = false;
	var $loaded_template   = null;
	var $template_output   = null;
	var $headers           = array();
	var $class_name;
	var $http_status;
	var $last_method_called;

	public function __construct() {

		// Make sure that the current URL has a trailing slash.
		add_action( 'gp_before_request', array( $this, 'check_uri_trailing_slash' ) );
	}

	/**
	 * Shows a template and exit.
	 *
	 * @param string      $message  The message to display.
	 * @param int         $status   The HTTP status code.
	 * @param string|null $title    The title of the page.
	 * @param string      $template The template to use.
	 *
	 * @return void
	 */
	public function die_with_error( string $message, int $status = 500, ?string $title = null, string $template = 'error' ) {
		if ( null === $title ) {
			$title = esc_html__( 'Error', 'glotpress' );
		}
		$this->status_header( $status );
		if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
			$this->exit_( $message );
		} else {
			$this->tmpl(
				$template,
				array(
					'title'   => $title,
					'message' => $message,
				)
			);
			$this->exit_();
		}
	}

	public function before_request() {
		/**
		 * Fires before a route method is called.
		 *
		 * @since 1.0.0
		 *
		 * @param string $class_name         The class name of the route.
		 * @param string $last_method_called The route method that will be called.
		 */
		do_action( 'gp_before_request', $this->class_name, $this->last_method_called );
	}

	public function after_request() {
		// we can't unregister a shutdown function
		// this check prevents this method from being run twice
		if ( ! $this->request_running ) {
			return;
		}
		// set errors and notices
		if ( ! headers_sent() ) {
			$this->set_notices_and_errors();
		}

		/**
		 * Fires after a route method was called.
		 *
		 * @since 1.0.0
		 *
		 * @param string $class_name         The class name of the route.
		 * @param string $last_method_called The route method that will be called.
		 */
		do_action( 'gp_after_request', $this->class_name, $this->last_method_called );
	}

	/**
	 * Validates a thing and add its errors to the route's errors.
	 *
	 * @param object $thing a GP_Thing instance to validate
	 * @return bool whether the thing is valid
	 */
	public function validate( $thing ) {
		$verdict      = $thing->validate();
		$this->errors = array_merge( $this->errors, $thing->errors );
		return $verdict;
	}

	/**
	 * Same as validate(), but redirects to $url if the thing isn't valid.
	 *
	 * Note: this method calls $this->exit_() after the redirect and the code after it won't
	 * be executed.
	 *
	 * @param object $thing a GP_Thing instance to validate
	 * @param string $url where to redirect if the thing doesn't validate
	 * @return bool whether the thing is valid
	 */
	public function invalid_and_redirect( $thing, $url = null ) {
		$valid = $this->validate( $thing );
		if ( ! $valid ) {
			$this->redirect( $url );
			return true;
		}
		return false;
	}

	/**
	 * Checks whether a user is allowed to do an action.
	 *
	 * @since 2.3.0 Added the `$extra` parameter.
	 *
	 * @param string      $action      The action.
	 * @param string|null $object_type Optional. Type of an object. Default null.
	 * @param int|null    $object_id   Optional. ID of an object. Default null.
	 * @param array|null  $extra       Optional. Extra information for deciding the outcome.
	 * @return bool       The verdict.
	 */
	public function can( $action, $object_type = null, $object_id = null, $extra = null ) {
		return GP::$permission->current_user_can( $action, $object_type, $object_id, $extra );
	}

	/**
	 * Redirects and exits if the current user isn't allowed to do an action.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $action      The action.
	 * @param string|null $object_type Optional. Type of an object. Default null.
	 * @param int|null    $object_id   Optional. ID of an object. Default null.
	 * @param string|null $url         Optional. URL to redirect to. Default: referrer or index page, if referrer is missing.
	 * @return bool Whether a redirect happened.
	 */
	public function cannot_and_redirect( $action, $object_type = null, $object_id = null, $url = null ) {
		$can = $this->can( $action, $object_type, $object_id );
		if ( ! $can ) {
			$this->redirect_with_error( __( 'You are not allowed to do that!', 'glotpress' ), $url );
			return true;
		}
		return false;
	}

	/**
	 * Verifies a nonce for a route.
	 *
	 * @since 2.0.0
	 *
	 * @param string $action Context for the created nonce.
	 * @return bool False if the nonce is invalid, true if valid.
	 */
	public function verify_nonce( $action ) {
		if ( empty( $_REQUEST['_gp_route_nonce'] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_REQUEST['_gp_route_nonce'], $action ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Verifies a nonce for a route and redirects in case the nonce is invalid.
	 *
	 * @since 2.0.0
	 *
	 * @param string      $action Context for the created nonce.
	 * @param string|null $url    The URL to redirect. Default: 'null', the referrer.
	 * @return bool False if the nonce is valid, true if the redirect has happened.
	 */
	public function invalid_nonce_and_redirect( $action, $url = null ) {
		if ( $this->verify_nonce( $action ) ) {
			return false;
		}

		$this->redirect_with_error( __( 'An error has occurred. Please try again.', 'glotpress' ), $url );
		return true;
	}

	/**
	 * Determines whether a user can perfom an action and redirects in case of a failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $action      The action.
	 * @param string|null $object_type Optional. Type of an object. Default null.
	 * @param int|null    $object_id   Optional. ID of an object. Default null.
	 * @param string|null $message     Error message in case of a failure.
	 *                                 Default: 'You are not allowed to do that!'.
	 * @param array|null  $extra       Pass-through parameter to can().
	 * @return false
	 */
	public function can_or_forbidden( $action, $object_type = null, $object_id = null, $message = null, $extra = null ) {
		if ( ! isset( $message ) ) {
			$message = __( 'You are not allowed to do that!', 'glotpress' );
		}
		if ( ! $this->can( $action, $object_type, $object_id, $extra ) ) {
			$this->die_with_error( $message, 403 );
		}
		return false;
	}

	public function logged_in_or_forbidden() {
		if ( ! is_user_logged_in() ) {
			$this->die_with_error( 'Forbidden', 403 );
		}
	}

	public function redirect_with_error( $message, $url = null ) {
		$this->errors[] = $message;
		$this->redirect( $url );
	}

	public function redirect( $url = null ) {
		if ( $this->fake_request ) {
			$this->redirected    = true;
			$this->redirected_to = $url;
			return;
		}

		$this->set_notices_and_errors();

		if ( is_null( $url ) ) {
			$url = $this->get_http_referer();
		}

		/*
		 * TODO: do not redirect to projects, but to /.
		 * Currently it goes to /projects, because / redirects too and the notice is gone.
		 */
		if ( ! $url ) {
			$url = gp_url( '/projects' );
		}

		wp_safe_redirect( $url );
		$this->tmpl( 'redirect', compact( 'url' ) );
	}

	/**
	 * Retrieves referer from '_wp_http_referer' or HTTP referer.
	 *
	 * Unlike `wp_get_referer()`, it doesn't check if the referer is
	 * the same as the current request URL.
	 *
	 * @since 2.0.0
	 *
	 * @return false|string False on failure. Referer URL on success.
	 */
	private function get_http_referer() {
		if ( ! function_exists( 'wp_validate_redirect' ) ) {
			return false;
		}

		$ref = wp_get_raw_referer();
		if ( $ref ) {
			return wp_validate_redirect( $ref, false );
		}

		return false;
	}

	/**
	 * Sets HTTP headers for content download.
	 *
	 * @param string $filename      The name of the file.
	 * @param string $last_modified Optional. Date when the file was last modified. Default: ''.
	 */
	public function headers_for_download( $filename, $last_modified = '' ) {
		$this->header( 'Content-Description: File Transfer' );
		$this->header( 'Pragma: public' );
		$this->header( 'Expires: 0' );

		if ( $last_modified ) {
			$this->header( sprintf( 'Last-Modified: %s', $last_modified ) );
		}

		$this->header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		$this->header( "Content-Disposition: attachment; filename=\"$filename\"" );
		$this->header( 'Content-Type: application/octet-stream' );
		$this->header( 'Connection: close' );
	}

	public function set_notices_and_errors() {
		if ( $this->fake_request ) {
			return;
		}

		foreach ( $this->notices as $notice ) {
			gp_notice_set( $notice );
		}
		$this->notices = array();

		foreach ( $this->errors as $error ) {
			gp_notice_set( $error, 'error' );
		}
		$this->errors = array();
	}

	/**
	 * Loads a template.
	 *
	 * @param string      $template  Template name to load.
	 * @param array       $args      Associative array with arguements, which will be exported in the template PHP file.
	 * @param bool|string $honor_api If this is true or 'api' and the route is processing an API request
	 *                               the template name will be suffixed with .api. The actual file loaded will be template.api.php.
	 */
	public function tmpl( $template, $args = array(), $honor_api = true ) {
		if ( $this->fake_request ) {
			$this->rendered_template = true;
			$this->loaded_template   = $template;
		}
		$this->set_notices_and_errors();
		if ( $this->api && false !== $honor_api && 'no-api' !== $honor_api ) {
			$template = $template . '.api';
			$this->header( 'Content-Type: application/json' );
		} else {
			$this->header( 'Content-Type: text/html; charset=utf-8' );
		}
		if ( $this->fake_request ) {
			$this->template_output = gp_tmpl_get_output( $template, $args, $this->template_path );
			return true;
		}

		return gp_tmpl_load( $template, $args, $this->template_path );
	}

	public function die_with_404( $args = array() ) {
		$this->status_header( 404 );
		$this->tmpl(
			'404',
			$args + array(
				'title'       => __( 'Not Found', 'glotpress' ),
				'http_status' => 404,
			)
		);
		$this->exit_();
	}

	public function exit_( $message = 0 ) {
		if ( $this->fake_request ) {
			$this->exited       = true;
			$this->exit_message = $message;
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- May contain HTML.
		exit( $message );
	}

	public function header( $string ) {
		if ( $this->fake_request ) {
			list( $header, $value )   = explode( ':', $string, 2 );
			$this->headers[ $header ] = $value;
		} else {
			header( $string );
		}
	}

	public function status_header( $status ) {
		if ( $this->fake_request ) {
			$this->http_status = $status;
			return;
		}
		return status_header( $status );
	}

	/**
	 * Check if the current URL has trailing slash. If not, redirect to trailed slash URL.
	 *
	 * @since 4.0.0
	 */
	public function check_uri_trailing_slash() {

		// Current URL.
		$current_uri = wp_parse_url( gp_url_current() );

		// URL path.
		$current_path = $current_uri['path'];

		// If the current path has no trailing slash, redirect to path with trailing slash.
		if ( trailingslashit( $current_path ) !== $current_path ) {

			// Add trailing slash to redirect URL.
			$redirect_url = trailingslashit( $current_path );

			// Include any existing query.
			if ( isset( $current_uri['query'] ) ) {
				$redirect_url .= '?' . $current_uri['query'];
			}

			// Redirect to URL with trailing slash.
			$this->redirect( $redirect_url );
		}
	}
}
