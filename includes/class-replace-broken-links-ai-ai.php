<?php
/**
 * Talks to the AI: OpenAI's Responses API or Anthropic's Messages API.
 *
 * Plain HTTPS requests to fixed hosts (api.openai.com, api.anthropic.com)
 * through the WordPress HTTP API, so it runs on PHP 7.2 with no SDK. The key
 * goes in a header and is never logged or shown; error messages from the
 * providers are trimmed and scrubbed of anything that looks like a key
 * before they're stored.
 *
 * Each call is a single question and answer: a short instruction, one
 * prompt, and a text reply. Sampling settings (temperature and the like) are
 * never sent; the newest models don't accept them. Optional extras (effort,
 * Anthropic's server-side refusal fallback, OpenAI's reasoning effort) are
 * only sent to models known to take them, and a request is retried once
 * without them if a model turns them down.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI client.
 */
final class Replace_Broken_Links_AI_AI {

	/**
	 * Anthropic endpoints and API version.
	 */
	const ANTHROPIC_MESSAGES = 'https://api.anthropic.com/v1/messages';
	const ANTHROPIC_MODELS   = 'https://api.anthropic.com/v1/models?limit=1000';
	const ANTHROPIC_VERSION  = '2026-10-21';

	/**
	 * Beta header for Anthropic's server-side fallback: a request a model
	 * declines is re-run on the model Anthropic recommends for that case.
	 */
	const ANTHROPIC_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

	/**
	 * OpenAI endpoints.
	 */
	const OPENAI_RESPONSES = 'https://api.openai.com/v1/responses';
	const OPENAI_MODELS    = 'https://api.openai.com/v1/models';

	/**
	 * Seconds to wait for an answer.
	 */
	const TIMEOUT = 90;

	/**
	 * Output token ceiling. Replies are a line or two, but thinking models
	 * spend tokens before they answer; only tokens used are billed.
	 */
	const MAX_TOKENS = 16000;

	/**
	 * Provider names.
	 *
	 * @return array<string,string>
	 */
	public static function providers() {
		return array(
			'openai'    => __( 'OpenAI (ChatGPT)', 'replace-broken-links-ai' ),
			'anthropic' => __( 'Anthropic (Claude)', 'replace-broken-links-ai' ),
		);
	}

	/**
	 * A provider's display name.
	 *
	 * @param string $provider openai or anthropic.
	 * @return string
	 */
	public static function provider_name( $provider ) {
		return 'anthropic' === $provider ? 'Anthropic' : 'OpenAI';
	}

	/**
	 * Models offered in the settings for each provider: id => label. Any
	 * other model the account has can be picked too.
	 *
	 * @param string $provider openai or anthropic.
	 * @return array<string,string>
	 */
	public static function suggested_models( $provider ) {
		if ( 'anthropic' === $provider ) {
			return array(
				'claude-opus-5-5'   => __( 'Claude Opus 5.5 (best for choosing)', 'replace-broken-links-ai' ),
				'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 (best for writing searches)', 'replace-broken-links-ai' ),
				'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 (lowest cost)', 'replace-broken-links-ai' ),
				'claude-fable-5-1'  => __( 'Claude Fable 5.1 (most capable, highest cost)', 'replace-broken-links-ai' ),
			);
		}

		return array(
			'gpt-6.1-sol'   => __( 'GPT-6.1 Sol (best for choosing)', 'replace-broken-links-ai' ),
			'gpt-6-luna'    => __( 'GPT-6 Luna (lowest cost, best for writing searches)', 'replace-broken-links-ai' ),
			'gpt-6.1-terra' => __( 'GPT-6.1 Terra (lower cost)', 'replace-broken-links-ai' ),
			'gpt-6-astra'   => __( 'GPT-6 Astra (most capable, highest cost)', 'replace-broken-links-ai' ),
		);
	}

	/**
	 * List prices in US dollars per million tokens (input, output), for the
	 * spending estimate and the daily spending limit. From the providers'
	 * pricing pages, October 2026.
	 *
	 * @return array<string,float[]>
	 */
	public static function prices() {
		$prices = array(
			'claude-fable-5-1'  => array( 10.0, 50.0 ),
			'claude-fable-5'    => array( 10.0, 50.0 ),
			'claude-opus-5-5'   => array( 4.0, 20.0 ),
			'claude-opus-5'     => array( 5.0, 25.0 ),
			'claude-opus-4-8'   => array( 5.0, 25.0 ),
			'claude-opus-4-7'   => array( 5.0, 25.0 ),
			'claude-opus-4-6'   => array( 5.0, 25.0 ),
			'claude-sonnet-5-5' => array( 2.0, 10.0 ),
			'claude-sonnet-5'   => array( 2.0, 10.0 ),
			'claude-sonnet-4-6' => array( 3.0, 15.0 ),
			'claude-haiku-5-5'  => array( 0.1, 0.5 ),
			'claude-haiku-4-5'  => array( 1.0, 5.0 ),
			'gpt-6-astra'       => array( 10.0, 50.0 ),
			'gpt-6.1-sol'       => array( 2.0, 10.0 ),
			'gpt-6-sol'         => array( 2.0, 10.0 ),
			'gpt-6.1-terra'     => array( 1.0, 5.0 ),
			'gpt-6-luna'        => array( 0.1, 0.5 ),
			'gpt-5.6-sol'       => array( 4.0, 20.0 ),
			'gpt-5.6-terra'     => array( 2.0, 12.0 ),
			'gpt-5.6-luna'      => array( 0.2, 1.2 ),
			'gpt-5.5'           => array( 5.0, 30.0 ),
			'gpt-5.4'           => array( 2.5, 15.0 ),
			'gpt-5.4-mini'      => array( 0.75, 4.5 ),
			'gpt-5.4-nano'      => array( 0.2, 1.25 ),
			'gpt-5.2'           => array( 1.75, 14.0 ),
			'gpt-5.1'           => array( 1.25, 10.0 ),
			'gpt-5'             => array( 1.25, 10.0 ),
			'gpt-5-mini'        => array( 0.25, 2.0 ),
			'gpt-5-nano'        => array( 0.05, 0.4 ),
			'gpt-4.1'           => array( 2.0, 8.0 ),
			'gpt-4.1-mini'      => array( 0.4, 1.6 ),
			'gpt-4o'            => array( 2.5, 10.0 ),
			'gpt-4o-mini'       => array( 0.15, 0.6 ),
		);

		/**
		 * Filters the prices (USD per million input and output tokens) used to
		 * estimate spending, e.g. to add a model or a negotiated rate.
		 *
		 * @param array<string,float[]> $prices Model ID => array( input, output ).
		 */
		return (array) apply_filters( 'replace_broken_links_ai_prices', $prices );
	}

	/**
	 * Estimated cost of one request, in millionths of a dollar. A model
	 * that isn't in the price list is counted at the top tier's price, so
	 * the daily spending limit errs on the safe side.
	 *
	 * @param string $model         Model ID.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens (thinking included).
	 * @return int
	 */
	public static function cost_micros( $model, $input_tokens, $output_tokens ) {
		$prices = self::prices();
		$price  = isset( $prices[ $model ] ) && is_array( $prices[ $model ] ) && 2 === count( $prices[ $model ] ) ? array_values( $prices[ $model ] ) : array( 10.0, 50.0 );

		return (int) ceil( (int) $input_tokens * (float) $price[0] + (int) $output_tokens * (float) $price[1] );
	}

	/**
	 * Whether a model ID looks valid (it goes into a JSON body, never a URL).
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	public static function valid_model( $model ) {
		return is_string( $model ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $model );
	}

	/**
	 * Ask the AI one question.
	 *
	 * @param string $provider openai or anthropic.
	 * @param string $key      API key.
	 * @param string $model    Model ID.
	 * @param string $system   Short standing instruction.
	 * @param string $prompt   The question.
	 * @param string $effort   low or medium: how hard a thinking model should think.
	 * @return array{text: string, input_tokens: int, output_tokens: int}|WP_Error
	 *         The error's data has "status" (HTTP status, 0 for network
	 *         errors) and "fatal" (true when retrying can't help until
	 *         someone fixes the key, the account or the model).
	 */
	public static function complete( $provider, $key, $model, $system, $prompt, $effort = 'low' ) {
		if ( '' === (string) $key ) {
			return self::error( 'rblai_ai_key', __( 'No API key is set for the AI.', 'replace-broken-links-ai' ), 0, true );
		}

		if ( ! self::valid_model( $model ) ) {
			return self::error( 'rblai_ai_model', __( 'The AI model name isn\'t valid. Pick another in Connections.', 'replace-broken-links-ai' ), 0, true );
		}

		$result = 'anthropic' === $provider
			? self::anthropic( $key, $model, $system, $prompt, $effort, true )
			: self::openai( $key, $model, $system, $prompt, $effort, true );

		// A model that turned down one of the optional extras: once more without.
		if ( is_wp_error( $result ) && 'rblai_ai_retry_plain' === $result->get_error_code() ) {
			$result = 'anthropic' === $provider
				? self::anthropic( $key, $model, $system, $prompt, $effort, false )
				: self::openai( $key, $model, $system, $prompt, $effort, false );
		}

		return $result;
	}

	/**
	 * Anthropic Messages API request.
	 *
	 * @param string $key    API key.
	 * @param string $model  Model ID.
	 * @param string $system Standing instruction.
	 * @param string $prompt The question.
	 * @param string $effort Effort level.
	 * @param bool   $extras Send the optional extras.
	 * @return array|WP_Error
	 */
	private static function anthropic( $key, $model, $system, $prompt, $effort, $extras ) {
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => self::ANTHROPIC_VERSION,
			'content-type'      => 'application/json',
		);
		$body    = array(
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		if ( $extras && self::anthropic_effort( $model ) ) {
			$body['output_config'] = array( 'effort' => $effort );
		}

		if ( $extras && self::anthropic_fallback( $model ) ) {
			$body['fallbacks']         = 'default';
			$headers['anthropic-beta'] = self::ANTHROPIC_FALLBACK_BETA;
		}

		$response = wp_remote_post(
			self::ANTHROPIC_MESSAGES,
			array(
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		$json = self::decode( $response );

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::api_error( 'anthropic', $code, $json, $model, $extras );
		}

		// A decline comes back as a normal 200; check before reading the text.
		if ( isset( $json['stop_reason'] ) && 'refusal' === $json['stop_reason'] ) {
			return self::error( 'rblai_ai_refusal', __( 'Claude declined to answer for this link.', 'replace-broken-links-ai' ), 200, false );
		}

		$text = '';
		foreach ( isset( $json['content'] ) && is_array( $json['content'] ) ? $json['content'] : array() as $block ) {
			if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			$why = isset( $json['stop_reason'] ) && 'max_tokens' === $json['stop_reason']
				? __( 'Claude ran out of room before it answered.', 'replace-broken-links-ai' )
				: __( 'Claude sent back an empty answer.', 'replace-broken-links-ai' );

			return self::error( 'rblai_ai_empty', $why, 200, false );
		}

		$usage = isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array();

		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0 )
				+ (int) ( isset( $usage['cache_read_input_tokens'] ) ? $usage['cache_read_input_tokens'] : 0 )
				+ (int) ( isset( $usage['cache_creation_input_tokens'] ) ? $usage['cache_creation_input_tokens'] : 0 ),
			'output_tokens' => (int) ( isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0 ),
		);
	}

	/**
	 * OpenAI Responses API request.
	 *
	 * @param string $key    API key.
	 * @param string $model  Model ID.
	 * @param string $system Standing instruction.
	 * @param string $prompt The question.
	 * @param string $effort Effort level.
	 * @param bool   $extras Send the optional extras.
	 * @return array|WP_Error
	 */
	private static function openai( $key, $model, $system, $prompt, $effort, $extras ) {
		$body = array(
			'model'             => $model,
			'instructions'      => $system,
			'input'             => $prompt,
			'max_output_tokens' => self::MAX_TOKENS,
			'store'             => false,
		);

		if ( $extras && self::openai_reasoning( $model ) ) {
			$body['reasoning'] = array( 'effort' => $effort );
		}

		$response = wp_remote_post(
			self::OPENAI_RESPONSES,
			array(
				'timeout'     => self::TIMEOUT,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		$json = self::decode( $response );

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::api_error( 'openai', $code, $json, $model, $extras );
		}

		$text    = '';
		$refused = false;

		// The answer is the output_text in the message item; reasoning and other
		// items can come before it.
		foreach ( isset( $json['output'] ) && is_array( $json['output'] ) ? $json['output'] : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['content'] ) || 'message' !== $item['type'] || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $part ) {
				if ( ! is_array( $part ) || ! isset( $part['type'] ) ) {
					continue;
				}

				if ( 'output_text' === $part['type'] && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				} elseif ( 'refusal' === $part['type'] ) {
					$refused = true;
				}
			}
		}

		if ( '' === trim( $text ) ) {
			if ( $refused ) {
				return self::error( 'rblai_ai_refusal', __( 'ChatGPT declined to answer for this link.', 'replace-broken-links-ai' ), 200, false );
			}

			$reason = isset( $json['incomplete_details']['reason'] ) ? (string) $json['incomplete_details']['reason'] : '';
			$why    = 'max_output_tokens' === $reason
				? __( 'ChatGPT ran out of room before it answered.', 'replace-broken-links-ai' )
				: __( 'ChatGPT sent back an empty answer.', 'replace-broken-links-ai' );

			return self::error( 'rblai_ai_empty', $why, 200, false );
		}

		$usage = isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array();

		return array(
			'text'          => $text,
			'input_tokens'  => (int) ( isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0 ),
			'output_tokens' => (int) ( isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0 ),
		);
	}

	/**
	 * The models an account can use, for the model pickers. Chat models only
	 * for OpenAI (no audio, image, embedding or moderation models).
	 *
	 * @param string $provider openai or anthropic.
	 * @param string $key      API key.
	 * @return string[]|WP_Error
	 */
	public static function models( $provider, $key ) {
		if ( '' === (string) $key ) {
			return self::error( 'rblai_ai_key', __( 'Enter an API key first.', 'replace-broken-links-ai' ), 0, true );
		}

		$headers = 'anthropic' === $provider
			? array(
				'x-api-key'         => $key,
				'anthropic-version' => self::ANTHROPIC_VERSION,
			)
			: array( 'Authorization' => 'Bearer ' . $key );

		$response = wp_remote_get(
			'anthropic' === $provider ? self::ANTHROPIC_MODELS : self::OPENAI_MODELS,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		$json = self::decode( $response );

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return self::api_error( $provider, $code, $json, '', false );
		}

		$ids = array();
		foreach ( isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array() as $model ) {
			$id = is_array( $model ) && isset( $model['id'] ) ? (string) $model['id'] : '';

			if ( ! self::valid_model( $id ) ) {
				continue;
			}

			if ( 'openai' === $provider && ( ! preg_match( '/^(gpt-|o[1-9]|chatgpt-)/', $id ) || preg_match( '/(audio|realtime|tts|transcribe|image|embedding|search|moderation|instruct|dall-e|whisper|codex|computer-use|cyber|rosalind)/', $id ) ) ) {
				continue;
			}

			$ids[] = $id;
		}

		$ids = array_values( array_unique( $ids ) );
		if ( 'openai' === $provider ) {
			rsort( $ids, SORT_NATURAL );
		}

		return array_slice( $ids, 0, 200 );
	}

	/**
	 * Whether a Claude model takes an effort level (Claude 4.6 and later, not Haiku 4.5).
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	private static function anthropic_effort( $model ) {
		if ( ! preg_match( '/^claude-(fable|mythos|opus|sonnet|haiku)-(\d+)(?:-(\d+))?(?:$|-)/', $model, $m ) ) {
			return false;
		}

		$major = (int) $m[2];
		$minor = isset( $m[3] ) ? (int) $m[3] : 0;

		return $major >= 5 || ( 4 === $major && $minor >= 6 && 'haiku' !== $m[1] );
	}

	/**
	 * Whether a Claude model takes the server-side refusal fallback.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	private static function anthropic_fallback( $model ) {
		return in_array( $model, array( 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5' ), true );
	}

	/**
	 * Whether an OpenAI model is a reasoning model that takes an effort level.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	private static function openai_reasoning( $model ) {
		return (bool) preg_match( '/^(gpt-[5-9]|o[1-9])/', $model ) && false === strpos( $model, '-pro' ) && false === strpos( $model, 'chat' );
	}

	/**
	 * A response's JSON body.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return self::error(
				'rblai_ai_network',
				/* translators: %s: error message from the HTTP request. */
				sprintf( __( 'Couldn\'t reach the AI service: %s', 'replace-broken-links-ai' ), self::clean_message( $response->get_error_message() ) ),
				0,
				false
			);
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Turn an error response into a WP_Error with a message people can act on.
	 *
	 * @param string $provider openai or anthropic.
	 * @param int    $code     HTTP status.
	 * @param array  $json     Response body.
	 * @param string $model    Model asked for.
	 * @param bool   $extras   Whether the optional extras were sent.
	 * @return WP_Error
	 */
	private static function api_error( $provider, $code, array $json, $model, $extras ) {
		$name    = self::provider_name( $provider );
		$detail  = isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ? self::clean_message( $json['error']['message'] ) : '';
		$type    = isset( $json['error']['code'] ) && is_string( $json['error']['code'] ) ? $json['error']['code'] : ( isset( $json['error']['type'] ) && is_string( $json['error']['type'] ) ? $json['error']['type'] : '' );
		$billing = 'insufficient_quota' === $type || 'billing_error' === $type || preg_match( '/credit balance|billing|quota/i', $detail );

		if ( 400 === $code && $extras && preg_match( '/fallback|anthropic-beta|output_config|effort|reasoning|unsupported|not supported/i', $detail ) ) {
			return self::error( 'rblai_ai_retry_plain', $detail, $code, false );
		}

		if ( 401 === $code ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( 'The %s API key was rejected. Check it under Connections.', 'replace-broken-links-ai' ), $name );
			$fatal   = true;
		} elseif ( 402 === $code || $billing ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( 'Your %s account is out of credit or has a billing problem.', 'replace-broken-links-ai' ), $name );
			$fatal   = true;
		} elseif ( 403 === $code ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( 'This %s API key isn\'t allowed to do that (often the model isn\'t enabled for the account).', 'replace-broken-links-ai' ), $name );
			$fatal   = true;
		} elseif ( 404 === $code ) {
			/* translators: 1: provider name, 2: model ID. */
			$message = sprintf( __( '%1$s doesn\'t offer the model "%2$s" to this account. Pick another under Connections.', 'replace-broken-links-ai' ), $name, $model );
			$fatal   = true;
		} elseif ( 429 === $code ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( '%s is rate limiting requests. The plugin will try again later.', 'replace-broken-links-ai' ), $name );
			$fatal   = false;
		} elseif ( $code >= 500 ) {
			/* translators: %s: provider name. */
			$message = sprintf( __( '%s is busy or having problems. The plugin will try again later.', 'replace-broken-links-ai' ), $name );
			$fatal   = false;
		} else {
			/* translators: 1: provider name, 2: HTTP status. */
			$message = sprintf( __( '%1$s turned the request down (HTTP %2$d).', 'replace-broken-links-ai' ), $name, $code );
			$fatal   = false;
		}

		if ( '' !== $detail ) {
			$message .= ' ' . sprintf( '(%s)', $detail );
		}

		return self::error( 'rblai_ai_' . $code, $message, $code, $fatal );
	}

	/**
	 * A provider's message, made safe to store and show: tags stripped,
	 * anything that looks like a key masked, and kept short.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function clean_message( $message ) {
		$message = sanitize_text_field( (string) $message );
		$message = (string) preg_replace( '/\b(sk-[A-Za-z0-9_*-]{4,}|[A-Za-z0-9_-]{32,})/', '[key]', $message );

		return Replace_Broken_Links_AI_Content::cut( $message, 300 );
	}

	/**
	 * Build an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param bool   $fatal   Whether retrying can't help.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status, $fatal ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => (int) $status,
				'fatal'  => (bool) $fatal,
			)
		);
	}
}
