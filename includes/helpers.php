<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;


/**
 * Detect whether a dedicated SEO plugin is active.
 * Used to avoid duplicating meta boxes and front-end tags.
 */
function pageforge_seo_plugins_active(): bool {
    // Yoast SEO
    if (defined('WPSEO_VERSION')) return true;
    // Rank Math
    if (defined('RANK_MATH_VERSION') || class_exists('\\RankMath')) return true;
    // All in One SEO
    if (defined('AIOSEO_VERSION') || class_exists('\\AIOSEO\\Plugin')) return true;
    return false;
}

function settings_get(): array {
    $opts = \get_option(PAGEFORGE_OPTION, []);
    return is_array($opts) ? $opts : [];
}

function settings_update(array $data): void {
    \update_option(PAGEFORGE_OPTION, $data);
}

function array_get(array $arr, string $key, $default = '') {
    return isset($arr[$key]) ? $arr[$key] : $default;
}

function schema_business_type_options(): array {
    return [
        'LocalBusiness'       => 'LocalBusiness',
        'Organization'        => 'Organization',
        'ProfessionalService' => 'ProfessionalService',
        'Store'               => 'Store',
        'HomeAndConstructionBusiness' => 'HomeAndConstructionBusiness',
        'AutomotiveBusiness'  => 'AutomotiveBusiness',
        'MedicalBusiness'     => 'MedicalBusiness',
        'LegalService'        => 'LegalService',
        'FinancialService'    => 'FinancialService',
        'RealEstateAgent'     => 'RealEstateAgent',
        'Restaurant'          => 'Restaurant',
        'Hotel'               => 'Hotel',
        'HealthAndBeautyBusiness' => 'HealthAndBeautyBusiness',
        'Dentist'             => 'Dentist',
        'Plumber'             => 'Plumber',
        'Electrician'         => 'Electrician',
        'HVACBusiness'        => 'HVACBusiness',
        'RoofingContractor'   => 'RoofingContractor',
    ];
}

function schema_normalize_business_type(string $type): string {
    $type = trim($type);
    $options = schema_business_type_options();
    return isset($options[$type]) ? $type : 'LocalBusiness';
}

function ai_provider_catalog(): array {
    return [
        'openai' => [
            'label'         => 'ChatGPT',
            'api_label'     => 'OpenAI',
            'transport'     => 'openai',
            'endpoint'      => 'https://api.openai.com/v1/chat/completions',
            'key_option'    => 'openai_key',
            'model_option'  => 'openai_model',
            'default_model' => 'gpt-4o-mini',
            'models'        => [
                'gpt-5.4-mini' => 'GPT-5.4 mini',
                'gpt-5.4'      => 'GPT-5.4',
                'gpt-4.1-mini' => 'GPT-4.1 mini',
                'gpt-4.1'      => 'GPT-4.1',
                'gpt-4.1-nano' => 'GPT-4.1 nano',
                'gpt-4o-mini'  => 'GPT-4o mini',
                'gpt-4o'       => 'GPT-4o',
                'o4-mini'      => 'o4-mini',
                'o3'           => 'o3',
            ],
        ],
        'anthropic' => [
            'label'         => 'Claude',
            'api_label'     => 'Anthropic',
            'transport'     => 'anthropic',
            'endpoint'      => 'https://api.anthropic.com/v1/messages',
            'key_option'    => 'anthropic_key',
            'model_option'  => 'anthropic_model',
            'default_model' => 'claude-sonnet-5',
            'models'        => [
                'claude-sonnet-5'             => 'Claude Sonnet 5',
                'claude-opus-4-8'             => 'Claude Opus 4.8',
                'claude-opus-4-7'             => 'Claude Opus 4.7',
                'claude-opus-4-6'             => 'Claude Opus 4.6',
                'claude-sonnet-4-6'           => 'Claude Sonnet 4.6',
                'claude-haiku-4-5-20251001'   => 'Claude Haiku 4.5',
            ],
        ],
        'gemini' => [
            'label'         => 'Gemini',
            'api_label'     => 'Google Gemini',
            'transport'     => 'gemini',
            'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models',
            'key_option'    => 'gemini_key',
            'model_option'  => 'gemini_model',
            'default_model' => 'gemini-2.5-flash',
            'models'        => [
                'gemini-3.1-pro'       => 'Gemini 3.1 Pro',
                'gemini-3-flash'       => 'Gemini 3 Flash',
                'gemini-2.5-pro'       => 'Gemini 2.5 Pro',
                'gemini-2.5-flash'     => 'Gemini 2.5 Flash',
                'gemini-2.5-flash-lite'=> 'Gemini 2.5 Flash-Lite',
            ],
        ],
        'deepseek' => [
            'label'         => 'DeepSeek',
            'api_label'     => 'DeepSeek',
            'transport'     => 'openai',
            'endpoint'      => 'https://api.deepseek.com/chat/completions',
            'key_option'    => 'deepseek_key',
            'model_option'  => 'deepseek_model',
            'default_model' => 'deepseek-v4-flash',
            'models'        => [
                'deepseek-v4-flash' => 'deepseek-v4-flash',
                'deepseek-v4-pro'   => 'deepseek-v4-pro',
            ],
        ],
    ];
}

function ai_normalize_provider(string $provider): string {
    $provider = sanitize_key($provider);
    $catalog = ai_provider_catalog();
    return isset($catalog[$provider]) ? $provider : 'openai';
}

function ai_provider_config(?string $provider = null): array {
    $catalog = ai_provider_catalog();
    $slug = ai_normalize_provider((string) $provider);
    return ['slug' => $slug] + $catalog[$slug];
}

function ai_provider_model_options(?string $provider = null): array {
    $config = ai_provider_config($provider);
    return (array) ($config['models'] ?? []);
}

function ai_provider_default_model(?string $provider = null): string {
    $config = ai_provider_config($provider);
    return (string) ($config['default_model'] ?? '');
}

function ai_provider_setting_key(string $provider, string $type = 'key'): string {
    $config = ai_provider_config($provider);
    return $type === 'model'
        ? (string) ($config['model_option'] ?? 'openai_model')
        : (string) ($config['key_option'] ?? 'openai_key');
}

function ai_get_provider(?array $settings = null): string {
    if ($settings === null) {
        $settings = settings_get();
    }
    return ai_normalize_provider((string) array_get($settings, 'ai_provider', 'openai'));
}

function ai_provider_label(?string $provider = null): string {
    $config = ai_provider_config($provider);
    return (string) ($config['label'] ?? 'AI');
}

function ai_provider_api_label(?string $provider = null): string {
    $config = ai_provider_config($provider);
    return (string) ($config['api_label'] ?? ai_provider_label($provider));
}

function ai_provider_infer_from_model(string $model): ?string {
    $model = trim($model);
    if ($model === '') {
        return null;
    }

    foreach (ai_provider_catalog() as $provider => $config) {
        $models = isset($config['models']) && is_array($config['models']) ? $config['models'] : [];
        if (isset($models[$model])) {
            return (string) $provider;
        }
    }

    return null;
}

function ai_provider_credentials(?array $settings = null, ?string $provider = null): array {
    if ($settings === null) {
        $settings = settings_get();
    }

    $active_provider = $provider !== null && $provider !== ''
        ? ai_normalize_provider($provider)
        : ai_get_provider($settings);
    $config = ai_provider_config($active_provider);
    $key_option = (string) ($config['key_option'] ?? 'openai_key');
    $model_option = (string) ($config['model_option'] ?? 'openai_model');
    $default_model = (string) ($config['default_model'] ?? '');
    $model = (string) array_get($settings, $model_option, $default_model);
    $models = isset($config['models']) && is_array($config['models']) ? $config['models'] : [];
    if (!isset($models[$model])) {
        $model = $default_model;
    }

    return [
        'provider'  => (string) ($config['slug'] ?? $active_provider),
        'label'     => (string) ($config['label'] ?? 'AI'),
        'api_label' => (string) ($config['api_label'] ?? 'AI'),
        'endpoint'  => (string) ($config['endpoint'] ?? ''),
        'transport' => (string) ($config['transport'] ?? 'openai'),
        'key'       => trim((string) array_get($settings, $key_option, '')),
        'model'     => $model,
    ];
}

function ai_has_provider_key(?array $settings = null, ?string $provider = null): bool {
    $credentials = ai_provider_credentials($settings, $provider);
    return $credentials['key'] !== '';
}

function parse_csv(string $file_path): array {
    $rows = [];
    $headers = [];
    if (!file_exists($file_path)) return [$headers, $rows];

    // Detect delimiter and handle BOM
    $delimiters = [",", ";", "\t", "|"];
    $delimiter = ",";
    $sample = '';
    // Prefer WP_Filesystem for reading files (plugin directory best practice).
    global $wp_filesystem;
    if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        \WP_Filesystem();
    }

    $contents = '';
    if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
        // get_contents() returns false on failure.
        $contents = (string) $wp_filesystem->get_contents( $file_path );
    }
    if ( $contents === '' ) {
        return [ $headers, $rows ];
    }

    $contents = str_replace( "\r\n", "\n", $contents );
    $contents = str_replace( "\r", "\n", $contents );

    // Strip UTF-8 BOM if present.
    if ( substr( $contents, 0, 3 ) === "\xEF\xBB\xBF" ) {
        $contents = substr( $contents, 3 );
    }

    $lines = preg_split( "/\n/", $contents );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        return [ $headers, $rows ];
    }

    $sample = (string) ( $lines[0] ?? '' );
    $bestCount = -1;
    foreach ( $delimiters as $d ) {
        $c = substr_count( $sample, $d );
        if ( $c > $bestCount ) { $bestCount = $c; $delimiter = $d; }
    }

    $rowIndex = 0;
    foreach ( $lines as $line ) {
        $line = (string) $line;
        if ( trim( $line ) === '' ) {
            continue;
        }

        $data = str_getcsv( $line, $delimiter );
        if ( ! is_array( $data ) ) {
            continue;
        }

        $isEmpty = true;
        foreach ( $data as $v ) {
            if ( trim( (string) $v ) !== '' ) { $isEmpty = false; break; }
        }
        if ( $isEmpty ) {
            continue;
        }

        if ( $rowIndex === 0 ) {
            if ( ! empty( $data ) ) {
                $data[0] = ltrim( (string) $data[0], "\xEF\xBB\xBF" );
            }
            $headers = array_map( 'trim', $data );
        } else {
            $row = [];
            foreach ( $headers as $i => $h ) {
                $row[ $h ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
            }
            $rows[] = $row;
        }
        $rowIndex++;
    }
    return [$headers, $rows];
}

function normalize_placeholders(array $row): array {
    // Produce case variations to support {City}, {city}
    $expanded = [];
    foreach ($row as $k => $v) {
        $expanded[$k] = $v;
        $expanded[strtolower($k)] = $v;
        $expanded[ucfirst(strtolower($k))] = $v;
    }
    return $expanded;
}

function replace_placeholders(string $text, array $row): string {
    if ($text === '') return '';
    $vars = normalize_placeholders($row);
    // Map {Key} => value
    $search = [];
    $replace = [];
    foreach ($vars as $k => $v) {
        $search[] = '{' . $k . '}';
        $replace[] = $v;
    }
    return str_replace($search, $replace, $text);
}

function replace_placeholders_deep($value, array $row) {
    if (is_string($value)) {
        return replace_placeholders($value, $row);
    }

    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = replace_placeholders_deep($v, $row);
        }
        return $value;
    }

    if (is_object($value)) {
        foreach (get_object_vars($value) as $k => $v) {
            $value->$k = replace_placeholders_deep($v, $row);
        }
        return $value;
    }

    return $value;
}

function build_slug(string $pattern, array $row): string {
    $slug_raw = replace_placeholders($pattern, $row);
    return \sanitize_title($slug_raw);
}

function pexels_sideload(string $keyword, string $pexels_key): int {
    if (empty($pexels_key)) return 0;
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($keyword) . "&per_page=1&orientation=landscape";
    $response = \wp_remote_get($url, [
        'headers' => ['Authorization' => $pexels_key],
        'timeout' => 45,
    ]);
    if (\is_wp_error($response)) return 0;
    $data = json_decode(\wp_remote_retrieve_body($response), true);
    if (empty($data['photos'][0]['src']['large2x'])) return 0;
    $photo_url = $data['photos'][0]['src']['large2x'];

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $img_id = \media_sideload_image($photo_url, 0, $keyword, 'id');
    return \is_wp_error($img_id) ? 0 : (int) $img_id;
}

function ai_chat_completion(array $messages, string $model = '', string $api_key = '', int $max_tokens = 2500, ?string $provider = null) {
    $settings = settings_get();
    $inferred_provider = $provider;
    if (($inferred_provider === null || $inferred_provider === '') && $model !== '') {
        $inferred_provider = ai_provider_infer_from_model($model);
    }

    $credentials = ai_provider_credentials($settings, $inferred_provider);
    if ($model === '') {
        $model = (string) $credentials['model'];
    }
    if ($api_key === '') {
        $api_key = (string) $credentials['key'];
    }
    if ($api_key === '') {
        return new \WP_Error(
            'no_api_key',
            sprintf(
                /* translators: %s: AI provider label */
                __('%s API key missing', 'pageforge'),
                $credentials['api_label']
            )
        );
    }

    $transport = (string) ($credentials['transport'] ?? 'openai');
    if ($transport === 'anthropic') {
        return ai_anthropic_chat($messages, $model, $api_key, $max_tokens, (string) $credentials['endpoint']);
    }
    if ($transport === 'gemini') {
        return ai_gemini_chat($messages, $model, $api_key, $max_tokens, (string) $credentials['endpoint']);
    }

    $body = [
        'model'       => $model,
        'messages'    => $messages,
    ];
    if (preg_match('/^(gpt-5|o[0-9])/i', $model)) {
        $body['max_completion_tokens'] = $max_tokens;
    } else {
        $body['max_tokens'] = $max_tokens;
        $body['temperature'] = 0.7;
    }
    $response = \wp_remote_post((string) $credentials['endpoint'], [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => \wp_json_encode($body),
        'timeout' => 90,
    ]);
    if (\is_wp_error($response)) {
        return $response;
    }

    $payload = json_decode(\wp_remote_retrieve_body($response), true);
    if (isset($payload['error'])) {
        return new \WP_Error(
            'ai_provider_error',
            $payload['error']['message'] ?? sprintf(
                /* translators: %s: AI provider label */
                __('%s error', 'pageforge'),
                $credentials['api_label']
            )
        );
    }

    $content = $payload['choices'][0]['message']['content'] ?? '';
    $content = trim(str_replace(['```json', '```html', '```'], '', (string) $content));
    return $content;
}

function ai_anthropic_chat(array $messages, string $model, string $api_key, int $max_tokens, string $endpoint) {
    $system_parts = [];
    $anthropic_messages = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = (string) ($m['role'] ?? '');
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '') continue;
        if ($role === 'system') {
            $system_parts[] = $content;
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') continue;
        $anthropic_messages[] = ['role' => $role, 'content' => $content];
    }
    if (empty($anthropic_messages)) {
        return new \WP_Error('no_messages', __('No messages provided', 'pageforge'));
    }
    if (($anthropic_messages[0]['role'] ?? '') === 'assistant') {
        array_unshift($anthropic_messages, ['role' => 'user', 'content' => 'Continue.']);
    }

    $body = [
        'model' => $model,
        'max_tokens' => $max_tokens,
        'temperature' => 0.7,
        'messages' => $anthropic_messages,
    ];
    if (!empty($system_parts)) {
        $body['system'] = implode("\n\n", $system_parts);
    }

    $response = \wp_remote_post($endpoint, [
        'headers' => [
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ],
        'body' => \wp_json_encode($body),
        'timeout' => 90,
    ]);
    if (\is_wp_error($response)) return $response;
    $payload = json_decode(\wp_remote_retrieve_body($response), true);
    if (isset($payload['error'])) {
        $message = is_array($payload['error']) ? (string) ($payload['error']['message'] ?? __('Anthropic error', 'pageforge')) : (string) $payload['error'];
        return new \WP_Error('anthropic_error', $message);
    }
    $parts = [];
    foreach ((array) ($payload['content'] ?? []) as $part) {
        if (is_array($part) && isset($part['text'])) {
            $parts[] = (string) $part['text'];
        }
    }
    return trim(str_replace(['```json', '```html', '```'], '', implode("\n", $parts)));
}

function ai_gemini_chat(array $messages, string $model, string $api_key, int $max_tokens, string $endpoint) {
    $system_parts = [];
    $contents = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = (string) ($m['role'] ?? '');
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '') continue;
        if ($role === 'system') {
            $system_parts[] = $content;
            continue;
        }
        $contents[] = [
            'role' => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $content]],
        ];
    }
    if (empty($contents)) {
        return new \WP_Error('no_messages', __('No messages provided', 'pageforge'));
    }

    $body = [
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => $max_tokens,
            'temperature' => 0.7,
        ],
    ];
    if (!empty($system_parts)) {
        $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system_parts)]]];
    }

    $url = rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
    $response = \wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => \wp_json_encode($body),
        'timeout' => 90,
    ]);
    if (\is_wp_error($response)) return $response;
    $payload = json_decode(\wp_remote_retrieve_body($response), true);
    if (isset($payload['error'])) {
        $message = is_array($payload['error']) ? (string) ($payload['error']['message'] ?? __('Gemini error', 'pageforge')) : (string) $payload['error'];
        return new \WP_Error('gemini_error', $message);
    }
    $parts = (array) ($payload['candidates'][0]['content']['parts'] ?? []);
    $text = [];
    foreach ($parts as $part) {
        if (is_array($part) && isset($part['text'])) {
            $text[] = (string) $part['text'];
        }
    }
    return trim(str_replace(['```json', '```html', '```'], '', implode("\n", $text)));
}

function openai_generate(string $prompt, string $model, string $api_key, int $max_tokens = 3000, ?string $provider = null) {
    return ai_chat_completion([
        ['role' => 'system', 'content' => 'You are a helpful SEO content generator. Return only raw text/HTML/JSON without code fences.'],
        ['role' => 'user', 'content' => $prompt],
    ], $model, $api_key, $max_tokens, $provider);
}

/**
 * Chat-style provider call supporting multi-message context.
 *
 * @param array<int, array{role:string, content:string}> $messages
 * @return string|\WP_Error
 */
function openai_chat(array $messages, string $model, string $api_key, int $max_tokens = 2500, ?string $provider = null) {
    // Normalize + safety trim
    $clean = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = isset($m['role']) ? (string) $m['role'] : '';
        $content = isset($m['content']) ? (string) $m['content'] : '';
        if ($role !== 'system' && $role !== 'user' && $role !== 'assistant') continue;
        $content = trim($content);
        if ($content === '') continue;
        $clean[] = [
            'role' => $role,
            'content' => mb_substr($content, 0, 12000),
        ];
        if (count($clean) >= 24) break;
    }

    if (empty($clean)) return new \WP_Error('no_messages', __('No messages provided', 'pageforge'));

    return ai_chat_completion($clean, $model, $api_key, $max_tokens, $provider);
}

function enqueue_admin_notice(string $message, string $class = 'warning') {
    \add_action('admin_notices', function() use ($message, $class){
        echo '<div class="notice notice-' . \esc_attr($class) . '"><p>' . \wp_kses_post($message) . '</p></div>';
    });
}

// ------------------ Analytics: GA4 Measurement Protocol ------------------
function ga4_send_event(string $event_name, array $params, string $measurement_id, string $api_secret): void {
    if ($measurement_id === '' || $api_secret === '') return;
    $client_id = md5(\site_url());
    $endpoint = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($measurement_id) . '&api_secret=' . rawurlencode($api_secret);
    $body = [
        'client_id' => $client_id,
        'non_personalized_ads' => true,
        'events' => [[ 'name' => $event_name, 'params' => $params ]],
    ];
    \wp_remote_post($endpoint, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => \wp_json_encode($body),
        'timeout' => 6,
    ]);
}

