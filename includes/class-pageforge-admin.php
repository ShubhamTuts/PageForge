<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Admin {
        /**
     * Render one secret input.
     *
     * The field is always blank on page load; it never carries the stored
     * secret. A status pill shows Configured/Not configured plus a
     * non-sensitive fingerprint. Submitting the form with the field left
     * blank keeps the stored secret via the unchanged sentinel, so a normal
     * "Save Settings" click can never accidentally wipe a working key.
     */
    private static function secret_field(string $name, string $stored_value): void {
        $status = Secrets::status($stored_value);
        $placeholder_value = Secrets::field_placeholder_value($stored_value);
        ?>
        <input
            type="password"
            name="<?php echo esc_attr($name); ?>"
            value="<?php echo esc_attr($placeholder_value); ?>"
            class="regular-text pf-secret-input"
            data-pf-secret="1"
            autocomplete="off"
            placeholder="<?php echo $status['configured'] ? esc_attr__('Enter a new value to replace it', 'pageforge') : esc_attr__('Not configured', 'pageforge'); ?>"
        />
        <span class="pf-badge <?php echo $status['configured'] ? 'ok' : 'warn'; ?> pf-secret-status">
            <?php echo $status['configured']
                ? esc_html(sprintf(
                    /* translators: %s: last characters of the stored secret, e.g. ****ab12 */
                    __('Configured (%s)', 'pageforge'),
                    $status['fingerprint']
                ))
                : esc_html__('Not configured', 'pageforge'); ?>
        </span>
        <?php if ($status['configured']): ?>
            <button type="button" class="button-link pf-secret-remove" data-pf-secret-clear="<?php echo esc_attr($name); ?>"><?php echo esc_html__('Remove', 'pageforge'); ?></button>
        <?php endif; ?>
        <?php
    }

    private static function topbar(string $active, string $subtitle = ''): void {
        // Primary navigation inside PageForge screens.
        // Plan & Account tabs were removed from the main shell to avoid
        // duplicating Freemius UI and to keep the experience focused on
        // generation and AI tools.
		$items = [
			'dashboard'   => [__('Dashboard', 'pageforge'), 'pageforge'],
			'pages'       => [__('Generate', 'pageforge'), 'pageforge-pages'],
			'datasources' => [__('Data Sources', 'pageforge'), 'pageforge-datasources'],
			'templates'   => [__('Templates', 'pageforge'), 'pageforge-templates'],
			'blocks'      => [__('Blocks', 'pageforge'), 'pageforge-shortcodes'],
			'ai'          => [__('AI Site Planner', 'pageforge'), 'pageforge-ai'],
			'blog'        => [__('AI Blog', 'pageforge'), 'pageforge-blog'],
			'connections' => [__('Connections', 'pageforge'), 'pageforge-connections'],
			'settings'    => [__('Settings', 'pageforge'), 'pageforge-settings'],
		];

		// WordPress.org build: no upsells or premium plan state.
		?>
        <div class="pf-topbar">
            <div class="pf-topbar-left">
                <div class="pf-brand">
                    <img class="pf-brand-logo" src="<?php echo esc_url(PAGEFORGE_URL . 'assets/logo-100x100.png'); ?>" width="40" height="40" alt="<?php echo esc_attr__('PageForge', 'pageforge'); ?>" />
                    <div>
                        <div class="pf-brand-title">PageForge</div>
                        <div class="pf-brand-sub"><?php echo esc_html($subtitle !== '' ? $subtitle : __('AI SEO Page Generator', 'pageforge')); ?></div>
                    </div>
                </div>
            </div>
            <div class="pf-topbar-right">
                <a href="https://pageforge.pro/pricing/?utm_source=plugin&utm_medium=topbar&utm_campaign=upgrade" target="_blank" class="pf-btn-upgrade"><?php echo esc_html__('Upgrade to Pro', 'pageforge'); ?></a>
					<nav class="pf-nav" aria-label="<?php echo esc_attr__('PageForge navigation', 'pageforge'); ?>">
						<?php foreach ($items as $key => [$label, $page]):
							$url = admin_url('admin.php?page=' . $page);
							$cls = $key === $active ? 'pf-nav-link is-active' : 'pf-nav-link';
						?>
							<a class="<?php echo esc_attr($cls); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
						<?php endforeach; ?>
					</nav>
				<div class="pf-topbar-status">
				</div>
			</div>
		</div>
		<?php
	}

    private static function active_ai_context(?array $settings = null): array {
        if ($settings === null) {
            $settings = settings_get();
        }

        $provider = ai_get_provider($settings);
        $credentials = ai_provider_credentials($settings, $provider);

        return [
            'provider' => $provider,
            'label' => (string) $credentials['label'],
            'api_label' => (string) $credentials['api_label'],
            'has_key' => trim((string) $credentials['key']) !== '',
        ];
    }

    private static function render_ai_key_notice(array $context, string $message = ''): void {
        ?>
        <div class="pf-alert pf-mt-md">
            <strong><?php echo esc_html(sprintf(__('%s API key missing.', 'pageforge'), $context['api_label'])); ?></strong>
            <div class="pf-mt-xs">
                <a class="button button-primary" href="<?php echo esc_url(\admin_url('admin.php?page=pageforge-settings')); ?>"><?php echo esc_html__('Add API key in Settings', 'pageforge'); ?></a>
                <?php if ($message !== ''): ?>
                    <span class="pf-inline-note"><?php echo esc_html($message); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ---------- MENU RENDERERS ----------
    public static function render_dashboard() {
        if (!\current_user_can('manage_options')) return;
        $tpl_count = self::count_published_posts(Compatibility::template_post_types());
        $pg_count  = self::count_published_posts(Compatibility::generated_post_types());
        $activity  = (array) \get_option('pageforge_activity', []);
        $settings  = settings_get();
        $run_limit = PAGEFORGE_FREE_ROW_LIMIT > 0 ? (string) PAGEFORGE_FREE_ROW_LIMIT : __('Unlimited', 'pageforge');
        ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('dashboard', __('Dashboard', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Dashboard', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Track CSV page generation, manage templates, and launch high-volume local SEO workflows from one place.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions"></div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-kpis">
                            <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo \esc_html($pg_count); ?></div><div class="pf-kpi-label"><?php echo esc_html__('Total Pages', 'pageforge'); ?></div></div>
                            <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo \esc_html($tpl_count); ?></div><div class="pf-kpi-label"><?php echo esc_html__('Active Templates', 'pageforge'); ?></div></div>
                            <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo \esc_html($run_limit); ?></div><div class="pf-kpi-label"><?php echo esc_html__('Rows per Run', 'pageforge'); ?></div></div>
                        </div>

                        <?php
                        // Build simple 7-day histogram from activity log
                        $byDay = [];
                        for ($i=6; $i>=0; $i--) {
                            $ts = strtotime('-'.$i.' days');
                            // Use gmdate() to avoid runtime timezone issues flagged by PHPCS.
                            $label = gmdate('D', $ts); // Mon..Sun
                            $key = gmdate('Y-m-d', $ts);
                            $byDay[$label] = 0;
                            foreach ($activity as $a) {
                                $d = gmdate('Y-m-d', (int) ($a['time'] ?? time()));
                                if ($d === $key) $byDay[$label]++;
                            }
                        }
                        $max = max(1, max($byDay));
                        ?>

                        <div class="pf-card pf-mt-md">
                            <div class="pf-section-title"><?php echo esc_html__('Page Generation Activity', 'pageforge'); ?></div>
                            <div class="pf-chart">
                                <?php foreach ($byDay as $label => $count): $h = intval(($count / $max) * 120) + 8; ?>
                                    <div style="display:flex;flex-direction:column;align-items:center">
                                        <div class="pf-bar" style="height:<?php echo (int) $h; ?>px" title="<?php echo esc_attr($count . ' events'); ?>"></div>
                                        <small><?php echo esc_html($label); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <div class="pf-section-title"><?php echo esc_html__('Recent Generations', 'pageforge'); ?></div>
                            <div class="pf-list">
                                <?php $recent = array_slice(array_reverse($activity),0,3); if (empty($recent)) : ?>
                                    <div class="pf-item"><span><?php echo esc_html__('No recent generations.', 'pageforge'); ?></span><span class="pf-badge">—</span></div>
                                <?php else: foreach ($recent as $row):
                                    $badge = 'ok'; $label = 'Completed';
                                    $st = (string)($row['status'] ?? '');
                                    if (stripos($st, 'progress') !== false) { $badge='warn'; $label='In Progress'; }
                                ?>
                                    <div class="pf-item">
                                        <span><?php echo esc_html($row['title'] ?? ''); ?></span>
                                        <span class="pf-badge <?php echo esc_attr($badge); ?>"><?php echo esc_html($label); ?></span>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-hero pf-mt-md">
                            <div class="pf-card" style="flex:1">
                                <h3><?php echo esc_html__('Programmatic SEO for WordPress', 'pageforge'); ?></h3>
                                <p><?php echo esc_html__('Create up to 100 unique pages per run from CSV with dynamic tokens, schema, and builder-friendly templates.', 'pageforge'); ?></p>
                                <p class="pf-dashboard-actions">
                                    <a href="<?php echo esc_url(\admin_url('admin.php?page=pageforge-templates')); ?>" class="button button-primary"><?php echo esc_html__('Create Template', 'pageforge'); ?></a>
                                    <a href="<?php echo esc_url(\admin_url('admin.php?page=pageforge-datasources')); ?>" class="button"><?php echo esc_html__('Add Data Source', 'pageforge'); ?></a>
                                    <a href="<?php echo esc_url(\admin_url('admin.php?page=pageforge-pages')); ?>" class="button"><?php echo esc_html__('Generate Pages', 'pageforge'); ?></a>
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

    public static function render_datasources() {
        if (!\current_user_can('manage_options')) return; ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('datasources', __('Data Sources', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Data Sources', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Upload CSV files and manage external data sources for your templates.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions"></div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <h2><?php echo esc_html__('CSV Upload', 'pageforge'); ?></h2>
                            <form id="pf-upload-form" enctype="multipart/form-data">
                                <input type="file" name="file" accept=".csv" required />
                                <button class="button button-primary" type="submit"><?php echo esc_html__('Upload CSV', 'pageforge'); ?></button>
                            </form>
                            <div id="pf-upload-result"></div>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h2><?php echo esc_html__('Available CSVs', 'pageforge'); ?></h2>
                            <table class="widefat fixed" id="pf-csv-table">
                                <thead><tr><th><?php echo esc_html__('Title', 'pageforge'); ?></th><th><?php echo esc_html__('URL', 'pageforge'); ?></th></tr></thead>
                                <tbody><tr><td colspan="2"><?php echo esc_html__('Loading…', 'pageforge'); ?></td></tr></tbody>
                            </table>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h3><?php echo esc_html__('Sample CSV', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('Download a sample CSV to get started quickly.', 'pageforge'); ?></p>
                            <button class="button" id="pf-download-sample"><?php echo esc_html__('Download Sample CSV', 'pageforge'); ?></button>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <h3><?php echo esc_html__('Instructions', 'pageforge'); ?></h3>
                            <ul>
                                <li><?php echo esc_html__('Prepare a CSV with the first row as headers, e.g., City, State, Service. Delimiters supported: comma, semicolon, tab, or pipe.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Placeholders derived from headers (e.g., {City}, {State}, {Service}) can be used anywhere in your Template content, Title, and Slug patterns.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Parser auto-detects delimiter and strips BOM; blank lines are ignored.', 'pageforge'); ?></li>
                            </ul>
                        </div>

						<div class="pf-card pf-mt-md">
							<h2><?php echo esc_html__('Google Sheets', 'pageforge'); ?></h2>
							<p><?php echo esc_html__('Google Sheets integration is not included in this build.', 'pageforge'); ?></p>
						</div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

	public static function render_templates() {
		if (!\current_user_can('edit_posts')) return;
		$list = \get_posts(self::template_query_args()); ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('templates', __('Templates', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Templates', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Create reusable templates with dynamic placeholders like {City}, {State}, {Service} anywhere in your content, title, or slug pattern.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions">
                        <a class="button button-primary" href="<?php echo esc_url(\admin_url('post-new.php?post_type=pageforge_template')); ?>"><?php echo esc_html__('Add New Template', 'pageforge'); ?></a>
                    </div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <table class="widefat fixed">
                                <thead><tr><th><?php echo esc_html__('Title', 'pageforge'); ?></th><th><?php echo esc_html__('Title Pattern', 'pageforge'); ?></th><th><?php echo esc_html__('Slug Pattern', 'pageforge'); ?></th><th><?php echo esc_html__('Actions', 'pageforge'); ?></th></tr></thead>
                                <tbody>
                                    <?php if (empty($list)): ?>
                                        <tr><td colspan="4"><?php echo esc_html__('No templates yet.', 'pageforge'); ?></td></tr>
                                    <?php else: foreach ($list as $tpl): ?>
                                        <tr>
                                            <td><a href="<?php echo \esc_url(\get_edit_post_link($tpl->ID)); ?>"><?php echo \esc_html(\get_the_title($tpl)); ?></a></td>
                                            <td><?php echo \esc_html(\get_post_meta($tpl->ID, '_pageforge_title_pattern', true)); ?></td>
                                            <td><?php echo \esc_html(\get_post_meta($tpl->ID, '_pageforge_slug_pattern', true)); ?></td>
                                            <td>
                                                <button class="button pf-export-template" data-id="<?php echo intval($tpl->ID); ?>"><?php echo esc_html__('Export', 'pageforge'); ?></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <h3><?php echo esc_html__('Create robust templates', 'pageforge'); ?></h3>
                            <ol>
                                <li><?php echo esc_html__('Use placeholders like {City}, {State}, {Service} directly in the editor content.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Set Title and Slug patterns in the metabox below. Examples: “Best {Service} in {City}, {State}” and “{service}-in-{city}-{state}”.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('To use an existing Elementor/Gutenberg/Divi page as the base layout, set “Base Layout from Existing Page” to that post ID. Its layout will be cloned and text placeholders replaced.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Runtime replacement also applies when builders render content, ensuring tokens inside widgets are replaced on the front end.', 'pageforge'); ?></li>
                            </ol>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

	public static function render_pages() {
        if (!\current_user_can('edit_posts')) return;
		$tpls = \get_posts(self::template_query_args());
		?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('pages', __('Generate Pages', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Generate Pages', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Use templates and CSV data sources to generate SEO-optimized pages in bulk, up to 100 pages per run in this free build.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions"></div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <table class="form-table pf-form-grid">
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Template', 'pageforge'); ?></th>
                                    <td>
                                        <select id="pf-template" class="regular-text pf-input-wide">
                                            <option value=""><?php echo esc_html__('Select Template', 'pageforge'); ?></option>
                                            <?php foreach ($tpls as $t): ?>
                                                <option value="<?php echo intval($t->ID); ?>"><?php echo \esc_html($t->post_title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Data Source (CSV)', 'pageforge'); ?></th>
                                    <td>
                                        <p id="pf-generation-destination" role="status" aria-live="polite"></p><select id="pf-csv" class="regular-text pf-input-wide"><option value=""><?php echo esc_html__('Loading…', 'pageforge'); ?></option></select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Post Status', 'pageforge'); ?></th>
                                    <td>
                                        <select id="pf-status" class="regular-text pf-input-medium">
                                            <option value="draft"><?php echo esc_html__('Draft', 'pageforge'); ?></option>
                                            <option value="publish"><?php echo esc_html__('Publish', 'pageforge'); ?></option>
                                            <option value="private"><?php echo esc_html__('Private', 'pageforge'); ?></option>
                                            <option value="pending"><?php echo esc_html__('Pending Review', 'pageforge'); ?></option>
                                        </select>
                                        <p class="description"><?php echo esc_html__('A template-level Generated Post Status overrides this setting.', 'pageforge'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Mode', 'pageforge'); ?></th>
                                    <td>
                                        <select id="pf-mode" class="regular-text pf-input-medium">
          <option value="instant" <?php selected(true, true); ?>><?php echo esc_html__('Instant (Run Now)', 'pageforge'); ?></option>
         </select>
         <p class="description pf-mode-note"><?php echo esc_html__('Generates pages immediately in your browser session, with up to 100 pages per run in free.', 'pageforge'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <p class="pf-mt-md">
                                <button id="pf-start" class="button button-primary"><?php echo esc_html__('Start', 'pageforge'); ?></button>
                            </p>
                            <div id="pf-log" class="pf-log pf-mt-md"></div>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <h3><?php echo esc_html__('How it works', 'pageforge'); ?></h3>
                            <ol>
                                <li><?php echo esc_html__('Select a Template that contains placeholders (e.g., {City}, {State}, {Service}).', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Choose a CSV uploaded in Data Sources. Headers define available placeholders.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Set Post Status and run the generation. This free build processes up to 100 CSV rows per run.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Generated posts keep variables for runtime replacement, so builders like Elementor/Divi/Gutenberg show tokens correctly.', 'pageforge'); ?></li>
                            </ol>
                        </div>
                        <div class="pf-card pf-mt-md">
                            <strong><?php echo esc_html__('Tips', 'pageforge'); ?></strong>
                            <ul>
                                <li><?php echo esc_html__('Ensure CSV headers match placeholders exactly (case-insensitive).', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Upload data in Data Sources; refresh here to see new files.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Split larger CSV files into batches of 100 rows for the smoothest runs.', 'pageforge'); ?></li>
                            </ul>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

    public static function render_shortcodes() {
        if (!\current_user_can('edit_posts')) return;
        $generated_count = self::count_generated_posts();
        $template_count = (int) count(\get_posts(self::template_query_args([
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ])));
        $csv_count = (int) count(DataSource::list_csv_attachments(200));
        ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('blocks', __('Blocks & Shortcodes', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Blocks & Shortcodes', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Use PageForge content with native WordPress blocks, shortcodes, and builder tokens.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions">
                        <a class="button button-primary" href="<?php echo esc_url(\admin_url('post-new.php?post_type=pageforge_template')); ?>"><?php echo esc_html__('Create Template', 'pageforge'); ?></a>
                    </div>
                </header>

                <div class="pf-kpis pf-block-kpis">
                    <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo esc_html(\number_format_i18n($generated_count)); ?></div><div class="pf-kpi-label"><?php echo esc_html__('Generated URLs', 'pageforge'); ?></div></div>
                    <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo esc_html(\number_format_i18n($template_count)); ?></div><div class="pf-kpi-label"><?php echo esc_html__('Templates', 'pageforge'); ?></div></div>
                    <div class="pf-card pf-kpi"><div class="pf-kpi-value"><?php echo esc_html(\number_format_i18n($csv_count)); ?></div><div class="pf-kpi-label"><?php echo esc_html__('CSV Sources', 'pageforge'); ?></div></div>
                    <div class="pf-card pf-kpi"><div class="pf-kpi-value">1</div><div class="pf-kpi-label"><?php echo esc_html__('Native Blocks', 'pageforge'); ?></div></div>
                </div>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <div class="pf-card-head">
                                <div>
                                    <div class="pf-h"><?php echo esc_html__('PageForge Blocks', 'pageforge'); ?></div>
                                    <div class="pf-sub"><?php echo esc_html__('Native Gutenberg blocks connected to generated PageForge content.', 'pageforge'); ?></div>
                                </div>
                                <span class="pf-tag"><?php echo esc_html__('Free', 'pageforge'); ?></span>
                            </div>

                            <div class="pf-block-usage-grid">
                                <div class="pf-block-usage-card">
                                    <div class="pf-block-usage-icon">PF</div>
                                    <div>
                                        <h3><?php echo esc_html__('Sitemap', 'pageforge'); ?></h3>
                                        <p><?php echo esc_html__('Adds an internal-linking sitemap of generated PageForge pages. It groups by City by default and links every matching generated URL.', 'pageforge'); ?></p>
                                        <ul class="pf-check-list">
                                            <li><?php echo esc_html__('Customize grouping by City, State, Service, or one list.', 'pageforge'); ?></li>
                                            <li><?php echo esc_html__('Control columns, page limit, counts, heading level, and colors.', 'pageforge'); ?></li>
                                            <li><?php echo esc_html__('Works as a native block or shortcode on any page.', 'pageforge'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <div class="pf-section-title"><?php echo esc_html__('Sitemap Shortcode', 'pageforge'); ?></div>
                            <p><?php echo esc_html__('Use this when you are editing with a builder, classic editor, widget, or reusable pattern.', 'pageforge'); ?></p>
                            <pre class="pf-code">[pageforge_sitemap]
[pageforge_sitemap group_by="city" columns="3" style="cards"]
[pageforge_sitemap post_type="pageforge_page,page,post" group_by="state" show_counts="1"]</pre>
                            <p><?php echo esc_html__('The shortcode lists published generated pages only, using PageForge metadata stored during generation.', 'pageforge'); ?></p>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <div class="pf-section-title"><?php echo esc_html__('Dynamic Variables', 'pageforge'); ?></div>
                            <p><?php echo esc_html__('Use a shortcode when a builder field does not allow raw token text, or place tokens directly in supported text and heading controls.', 'pageforge'); ?></p>
                            <pre class="pf-code">[pageforge var="City"]
[pageforge var="Service" fallback="General Service"]
[pageforge var="State"]

Title Pattern: Best {Service} in {City}, {State}
Slug Pattern: {service}-in-{city}-{state}</pre>
                            <p><?php echo wp_kses_post(__('Shortcodes render the value from the generated row for the current post. Direct tokens such as <code>{City}</code> are replaced at render time on generated pages.', 'pageforge')); ?></p>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <div class="pf-section-title"><?php echo esc_html__('How to add the Sitemap block', 'pageforge'); ?></div>
                            <ol class="pf-status-list">
                                <li><?php echo esc_html__('Open any page in the WordPress block editor.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Click Add Block and search for PageForge Sitemap.', 'pageforge'); ?></li>
                                <li><?php echo esc_html__('Choose grouping, columns, colors, and heading level from the block sidebar.', 'pageforge'); ?></li>
                            </ol>
                        </div>

                        <div class="pf-card">
                            <div class="pf-section-title"><?php echo esc_html__('Builder-ready tokens', 'pageforge'); ?></div>
                            <p><?php echo esc_html__('Insert tokens like {City}, {State}, {Service}, or any CSV header directly into Gutenberg, Elementor, and Divi text areas. PageForge replaces them on generated pages, including theme titles and excerpts.', 'pageforge'); ?></p>
                        </div>

                        <div class="pf-card">
                            <div class="pf-section-title"><?php echo esc_html__('Base layout cloning', 'pageforge'); ?></div>
                            <p><?php echo esc_html__('Templates can clone an existing Gutenberg, Elementor, or Divi page by setting its post ID in Base Layout from Existing Page.', 'pageforge'); ?></p>
                            <a class="button" href="<?php echo esc_url(\admin_url('edit.php?post_type=pageforge_template')); ?>"><?php echo esc_html__('Open Templates', 'pageforge'); ?></a>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

    public static function render_settings() {
        if (!\current_user_can('manage_options')) return;
		$s = settings_get();
        $active_provider = ai_get_provider($s);
        ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('settings', __('Settings', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Settings', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Configure AI providers, generation defaults, analytics, and schema options.', 'pageforge'); ?></p>
                    </div>
                    <div class="pf-page-actions"></div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <form id="pf-settings-form">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('AI Provider', 'pageforge'); ?></th>
                                        <td>
                                            <select name="ai_provider" id="pf-ai-provider" class="regular-text">
                                                <?php foreach (ai_provider_catalog() as $provider_key => $provider_config): ?>
                                                    <option value="<?php echo esc_attr($provider_key); ?>" <?php \selected($active_provider, $provider_key); ?>><?php echo esc_html((string) ($provider_config['label'] ?? $provider_key)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php echo esc_html__('Choose which AI provider powers AI content, meta generation, the site planner, and blog drafts in the free plugin.', 'pageforge'); ?></p>
                                        </td>
                                    </tr>
                                    <tr data-ai-provider-section="openai"<?php echo $active_provider === 'openai' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('OpenAI API Key', 'pageforge'); ?></th>
                                        <td><?php self::secret_field('openai_key', (string) array_get($s, 'openai_key', '')); ?></td>
                                    </tr>
                                    <tr data-ai-provider-section="openai"<?php echo $active_provider === 'openai' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('OpenAI Model', 'pageforge'); ?></th>
                                        <td>
                                            <select name="openai_model" class="regular-text">
                                                <?php foreach (ai_provider_model_options('openai') as $model_id => $model_label): ?>
                                                    <option value="<?php echo esc_attr($model_id); ?>" <?php \selected(array_get($s, 'openai_model', ai_provider_default_model('openai')), $model_id); ?>><?php echo esc_html((string) $model_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr data-ai-provider-section="anthropic"<?php echo $active_provider === 'anthropic' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('Claude API Key', 'pageforge'); ?></th>
                                        <td><?php self::secret_field('anthropic_key', (string) array_get($s, 'anthropic_key', '')); ?></td>
                                    </tr>
                                    <tr data-ai-provider-section="anthropic"<?php echo $active_provider === 'anthropic' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('Claude Model', 'pageforge'); ?></th>
                                        <td>
                                            <select name="anthropic_model" class="regular-text">
                                                <?php foreach (ai_provider_model_options('anthropic') as $model_id => $model_label): ?>
                                                    <option value="<?php echo esc_attr($model_id); ?>" <?php \selected(array_get($s, 'anthropic_model', ai_provider_default_model('anthropic')), $model_id); ?>><?php echo esc_html((string) $model_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr data-ai-provider-section="gemini"<?php echo $active_provider === 'gemini' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('Gemini API Key', 'pageforge'); ?></th>
                                        <td><?php self::secret_field('gemini_key', (string) array_get($s, 'gemini_key', '')); ?></td>
                                    </tr>
                                    <tr data-ai-provider-section="gemini"<?php echo $active_provider === 'gemini' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('Gemini Model', 'pageforge'); ?></th>
                                        <td>
                                            <select name="gemini_model" class="regular-text">
                                                <?php foreach (ai_provider_model_options('gemini') as $model_id => $model_label): ?>
                                                    <option value="<?php echo esc_attr($model_id); ?>" <?php \selected(array_get($s, 'gemini_model', ai_provider_default_model('gemini')), $model_id); ?>><?php echo esc_html((string) $model_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr data-ai-provider-section="deepseek"<?php echo $active_provider === 'deepseek' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('DeepSeek API Key', 'pageforge'); ?></th>
                                        <td><?php self::secret_field('deepseek_key', (string) array_get($s, 'deepseek_key', '')); ?></td>
                                    </tr>
                                    <tr data-ai-provider-section="deepseek"<?php echo $active_provider === 'deepseek' ? '' : ' style="display:none"'; ?>>
                                        <th scope="row"><?php echo esc_html__('DeepSeek Model', 'pageforge'); ?></th>
                                        <td>
                                            <select name="deepseek_model" class="regular-text">
                                                <?php foreach (ai_provider_model_options('deepseek') as $model_id => $model_label): ?>
                                                    <option value="<?php echo esc_attr($model_id); ?>" <?php \selected(array_get($s, 'deepseek_model', ai_provider_default_model('deepseek')), $model_id); ?>><?php echo esc_html((string) $model_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php echo esc_html__('DeepSeek uses its current OpenAI-compatible API models. Legacy deepseek-chat and deepseek-reasoner are intentionally excluded.', 'pageforge'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Pexels API Key', 'pageforge'); ?></th>
                                        <td>
                                            <?php self::secret_field('pexels_key', (string) array_get($s, 'pexels_key', '')); ?>
                                            <p class="description"><?php echo \wp_kses_post(sprintf(__('Photos provided by %s. Credit photographers when possible.', 'pageforge'), '<a href="https://www.pexels.com/" target="_blank" rel="noopener noreferrer">Pexels</a>')); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Brand Context / Voice', 'pageforge'); ?></th>
                                        <td>
                                            <textarea name="brand_context" rows="4" class="large-text" placeholder="<?php echo esc_attr__('Describe your brand, tone, USPs. Added to AI prompts.', 'pageforge'); ?>"><?php echo esc_textarea(array_get($s,'brand_context')); ?></textarea>
                                            <p class="description"><?php echo esc_html__('This brand context is appended to AI prompts to keep generated pages on-message.', 'pageforge'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Business Type (Schema)', 'pageforge'); ?></th>
                                        <td>
                                            <select name="business_type" class="regular-text">
                                                <?php $bt = schema_normalize_business_type((string) array_get($s, 'business_type', 'LocalBusiness')); ?>
                                                <?php foreach (schema_business_type_options() as $schema_type => $schema_label): ?>
                                                    <option value="<?php echo esc_attr($schema_type); ?>" <?php \selected($bt, $schema_type); ?>><?php echo esc_html($schema_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php echo esc_html__('Used as the Schema.org @type for the business/provider object (and influences schema output).', 'pageforge'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Target Country (Schema)', 'pageforge'); ?></th>
                                        <td>
                                            <input type="text" name="target_country" value="<?php echo esc_attr((string) array_get($s, 'target_country', '')); ?>" class="regular-text" placeholder="<?php echo esc_attr__('e.g. AU, US, GB (ISO 2-letter)', 'pageforge'); ?>" />
                                            <p class="description"><?php echo esc_html__('Leave blank to auto-detect. If an AI API key is connected, PageForge will prefer global prevalence (auto country) instead of forcing AU.', 'pageforge'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Generated Post Type', 'pageforge'); ?></th>
                                        <td>
                                            <select name="post_type" class="regular-text">
                                                <option value="pageforge_page" <?php \selected(array_get($s,'post_type','pageforge_page'),'pageforge_page'); ?>><?php echo esc_html__('PageForge Page (pageforge_page)', 'pageforge'); ?></option>
                                                <option value="page" <?php \selected(array_get($s,'post_type'),'page'); ?>><?php echo esc_html__('Page', 'pageforge'); ?></option>
                                                <option value="post" <?php \selected(array_get($s,'post_type'),'post'); ?>><?php echo esc_html__('Post', 'pageforge'); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Generated CPT Slug Base', 'pageforge'); ?></th>
                                        <td><input type="text" name="cpt_base" value="<?php echo esc_attr(array_get($s,'cpt_base','pageforge')); ?>" class="regular-text" /> <span class="description"><?php echo esc_html__('Changes only /{base}/slug/ for PageForge custom content. A nonempty base is required. Choose native Page output with no parent for /the-magnolias/, or assign a Parent Page for /maths-tuition-gurgaon/dlf-phase-iv/. Changing an established base changes custom-content URLs: plan redirects separately; PageForge does not create them.', 'pageforge'); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('GA4 Measurement ID', 'pageforge'); ?></th>
                                        <td><input type="text" name="ga4_measurement_id" value="<?php echo esc_attr(array_get($s,'ga4_measurement_id')); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" /></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('GA4 API Secret', 'pageforge'); ?></th>
                                        <td><?php self::secret_field('ga4_api_secret', (string) array_get($s, 'ga4_api_secret', '')); ?></td>
                                    </tr>
                                  </table>
                                  <p class="pf-mt-md"><button class="button button-primary" type="submit"><?php echo esc_html__('Save Settings', 'pageforge'); ?></button></p>
                              </form>
                          </div>
                    </main>

					<aside class="pf-page-aside"></aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }


		public static function render_plan() {
		// WordPress.org build: no licensing/plan UI.
		return;
		?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('plan', __('Plan & License', 'pageforge')); ?>
            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Plan & License', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Review your PageForge plan, usage limits, and license options.', 'pageforge'); ?></p>
                    </div>
					<div class="pf-page-actions">
                    </div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <h2><?php echo esc_html__('Plan overview', 'pageforge'); ?></h2>
                            <p class="pf-mt-xs">
                                <strong><?php echo esc_html__('Current plan:', 'pageforge'); ?></strong>
                                <span class="pf-plan-pill pf-plan-pill--<?php echo esc_attr($plan_tier); ?>">
                                    <?php
                                    echo esc_html($plan_label);
                                    echo $plan_id ? esc_html(' #' . (int) $plan_id) : '';
                                    ?>
                                </span>
                            </p>
                            <p class="pf-mt-xs">
                                <strong><?php echo esc_html__('Site URL:', 'pageforge'); ?></strong>
                                <code><?php echo esc_html($site_url); ?></code>
                            </p>
                            <p class="pf-mt-xs">
                                <?php echo esc_html__('PageForge uses Freemius to manage billing, licensing, and plan entitlements for this site.', 'pageforge'); ?>
                            </p>
						<div class="pf-actions pf-mt-md">
							<a class="button button-primary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Upgrade plan', 'pageforge'); ?></a>
							<?php if ($account_url): ?>
								<a class="button" href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Manage billing & license', 'pageforge'); ?></a>
							<?php endif; ?>
						</div>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h2><?php echo esc_html__('Usage & limits', 'pageforge'); ?></h2>
                            <p class="pf-mt-xs">
                                <?php
                                if ($free_limit > 0) {
                                    printf(
                                        /* translators: %d: free row limit */
                                        esc_html__('Free builds can generate up to %d rows once per site. After that, upgrading unlocks unlimited rows.', 'pageforge'),
                                        (int) $free_limit
                                    );
                                } else {
                                    echo esc_html__('This build does not enforce a specific free row limit. Production builds typically include a one-time free allowance.', 'pageforge');
                                }
                                ?>
                            </p>
                            <p class="pf-mt-xs">
                                <?php
                                $quota = AI::ai_quota_state();
                                if (!empty($quota['limit'])) {
                                    printf(
                                        /* translators: 1: used AI messages, 2: monthly AI quota */
                                        esc_html__('Free AI tools: %1$d of %2$d messages used this month on this site.', 'pageforge'),
                                        (int) ($quota['count'] ?? 0),
                                        (int) ($quota['limit'] ?? 0)
                                    );
                                } else {
                                    echo esc_html__('AI tools are unlimited for paid plans.', 'pageforge');
                                }
                                ?>
                            </p>
                            <?php if ($free_limit > 0): ?>
                                <p class="pf-mt-xs">
                                    <strong><?php echo esc_html__('Free limit status:', 'pageforge'); ?></strong>
                                    <?php echo esc_html($free_status); ?>
                                </p>
                            <?php endif; ?>

                            <table class="widefat fixed pf-plan-matrix pf-mt-md">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Feature', 'pageforge'); ?></th>
                                        <th><?php echo esc_html__('Free', 'pageforge'); ?></th>
                                        <th><?php echo esc_html__('Starter', 'pageforge'); ?></th>
                                        <th><?php echo esc_html__('Professional', 'pageforge'); ?></th>
                                        <th><?php echo esc_html__('Enterprise', 'pageforge'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo esc_html__('One-time bulk generation (limited rows)', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo esc_html__('Unlimited rows & automation', 'pageforge'); ?></td>
                                        <td>—</td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo esc_html__('AI Meta Generator', 'pageforge'); ?></td>
                                        <td>—</td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo esc_html__('AI Site Planner & SEO report', 'pageforge'); ?></td>
                                        <td>—</td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo esc_html__('AI Blog generator & images', 'pageforge'); ?></td>
                                        <td>—</td>
                                        <td>—</td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                        <td><?php echo esc_html__('Included', 'pageforge'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <h3><?php echo esc_html__('License management', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('Licenses and billing for PageForge are managed via Freemius. Use the account portal to update payment details, move licenses between sites, or cancel a subscription.', 'pageforge'); ?></p>
                            <?php if ($account_url): ?>
                                <a class="button" href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open Freemius account portal', 'pageforge'); ?></a>
                            <?php else: ?>
                                <p class="pf-note"><?php echo esc_html__('Account portal URL is not available in this environment. Use your Freemius dashboard to manage licenses.', 'pageforge'); ?></p>
                            <?php endif; ?>
                            <p class="pf-note pf-mt-sm"><?php echo esc_html__('Use the Freemius account portal for license activation, billing, and subscription changes.', 'pageforge'); ?></p>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h3><?php echo esc_html__('Need Enterprise?', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('For higher volumes, custom workflows, or security reviews, talk to us about an Enterprise plan.', 'pageforge'); ?></p>
                            <a class="button button-primary" href="mailto:support@pageforge.pro?subject=PageForge%20Enterprise">
                                <?php echo esc_html__('Contact sales', 'pageforge'); ?>
                            </a>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

	public static function render_account() {
		// WordPress.org build: no licensing/account UI.
		return;
		?>

/*
 * NOTE: The remainder of the legacy Plan/Account UI is intentionally kept in
 * the file for now, but is unreachable due to the early return above.
 * It will be removed completely once the wp.org build is validated.
 */
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('account', __('Account', 'pageforge')); ?>

            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Account', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('Manage your PageForge connection, license, and access the Freemius account portal.', 'pageforge'); ?></p>
                    </div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <div class="pf-card">
                            <h2><?php echo esc_html__('Site & plan status', 'pageforge'); ?></h2>
                            <p class="pf-mt-xs">
                                <strong><?php echo esc_html__('Site URL:', 'pageforge'); ?></strong>
                                <code><?php echo esc_html($site_url); ?></code>
                            </p>
                            <p class="pf-mt-xs">
                                <strong><?php echo esc_html__('Current plan:', 'pageforge'); ?></strong>
                                <span class="pf-plan-pill pf-plan-pill--<?php echo esc_attr($plan_tier); ?>">
                                    <span class="pf-plan-pill-label"><?php echo esc_html($plan_label); ?></span>
                                </span>
                            </p>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h2><?php echo esc_html__('AI usage limits', 'pageforge'); ?></h2>
                            <?php if (!empty($quota['limit'])): ?>
                                <p><?php
                                    printf(
                                        /* translators: 1: used AI messages, 2: limit per month */
                                        esc_html__('Free plan: %1$d of %2$d AI messages used this month on this site.', 'pageforge'),
                                        (int) ($quota['count'] ?? 0),
                                        (int) ($quota['limit'] ?? 0)
                                    );
                                ?></p>
                                <p class="pf-note"><?php echo esc_html__('The quota is shared across AI Meta, AI Blog, SEO report, and Site Planner tools. Upgrading removes this limit.', 'pageforge'); ?></p>
                            <?php else: ?>
                                <p><?php echo esc_html__('Paid plans do not enforce a PageForge-level AI message cap. Usage is limited only by your selected AI provider account and fair-use.', 'pageforge'); ?></p>
                            <?php endif; ?>
                        </div>
                    </main>

                    <aside class="pf-page-aside">
                        <div class="pf-card">
                            <h3><?php echo esc_html__('Freemius account portal', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('Licenses, billing details, invoices, and subscription management are handled by Freemius. Use the account portal to manage your license across sites.', 'pageforge'); ?></p>
                            <?php if ($account_url): ?>
                                <p class="pf-mt-xs">
                                    <a class="button button-primary" href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open Account Portal', 'pageforge'); ?></a>
                                </p>
                            <?php else: ?>
                                <p class="pf-note"><?php echo esc_html__('Account portal URL is currently unavailable. Ensure the Freemius SDK is loaded and this site is connected.', 'pageforge'); ?></p>
                            <?php endif; ?>
                            <p class="pf-note pf-mt-sm"><?php echo esc_html__('If you have enabled white-label mode for this license, some owner and billing information may be hidden from your client sites.', 'pageforge'); ?></p>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h3><?php echo esc_html__('Need to move or deactivate a license?', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('You can deactivate a license on this site and move it to another by using the Freemius account portal. Disconnecting a site from Freemius will also remove it from your user dashboard.', 'pageforge'); ?></p>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
        <?php self::checkout_snippet(); }

	    public static function render_ai_tools() {
	    if (!\current_user_can('edit_posts')) return;
		$plan_id          = 0;
		$s                = settings_get();
        $ai_context       = self::active_ai_context($s);
		$can_seo_report   = \current_user_can('manage_options');
		$can_site_planner = AI::feature_enabled('ai_site_planner');
		?>
	        <div class="wrap pf-wrap pf-ai pf-shell">
			<?php self::topbar('ai', __('AI Site Planner', 'pageforge')); ?>

            <div class="pf-shell-main pf-ai-main-shell">
                <div class="pf-ai-layout">

                    <aside class="pf-ai-sidebar" aria-label="<?php echo esc_attr__('AI navigation and conversations', 'pageforge'); ?>">
                        <div class="pf-ai-sidebar-section pf-ai-sidebar-section--top">
                            <button type="button" class="button button-primary pf-btn-sm" id="pf-ai-new-chat"><?php echo esc_html__('New chat', 'pageforge'); ?></button>
                        </div>

                        <div class="pf-ai-sidebar-section pf-ai-conversations">
                            <div class="pf-ai-sidebar-heading"><?php echo esc_html__('Recent chats', 'pageforge'); ?></div>
                            <div class="pf-ai-conversation-list" id="pf-ai-conversation-list"></div>
                        </div>

                        <div class="pf-ai-sidebar-section pf-ai-sidebar-shortcuts">
                            <div class="pf-ai-sidebar-heading"><?php echo esc_html__('Shortcuts', 'pageforge'); ?></div>
                            <ul class="pf-ai-shortcuts-list">
                                <li><a href="<?php echo esc_url(\admin_url('admin.php?page=pageforge-settings')); ?>"><?php echo esc_html__('Settings', 'pageforge'); ?></a></li>
                                <li><a href="<?php echo esc_url('https://pageforge.pro/docs'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Documentation', 'pageforge'); ?></a></li>
                            </ul>
                        </div>
                    </aside>

                    <section class="pf-ai-main" aria-label="<?php echo esc_attr__('AI chat and tools', 'pageforge'); ?>">
                        <header class="pf-ai-header">
                            <div class="pf-page-title-group">
                                <h1 class="pf-page-title"><?php echo esc_html__('AI Site Planner', 'pageforge'); ?></h1>
                                <p class="pf-page-subtitle"><?php echo esc_html__('Chat-style assistant to plan your sitemap, pages, SEO, and launch.', 'pageforge'); ?></p>
                            </div>
                            <div class="pf-ai-header-meta">
                                <?php
?>

                            </div>
                        </header>

                        <?php if (!$ai_context['has_key']) {
                            self::render_ai_key_notice($ai_context, __('Add your selected AI provider API key to enable the Site Planner and other AI tools.', 'pageforge'));
                        } ?>

                        <div class="pf-ai-body">
                            <div class="pf-ai-chat-wrapper">


                                <div class="pf-chat" id="pf-site-planner">
                                    <div class="pf-chat-log" id="pf-chat-log" role="log" aria-live="polite" aria-relevant="additions"></div>
                                    <div class="pf-chat-typing" id="pf-chat-typing" style="display:none;">
                                        <?php echo esc_html__('Thinking…', 'pageforge'); ?>
                                    </div>

                                    <div class="pf-chat-composer">
                                        <div class="pf-chat-input-group">
                                            <textarea id="pf-chat-input" class="pf-chat-input" rows="1" placeholder="<?php echo esc_attr__('Describe your business, location, services, and goals…', 'pageforge'); ?>"></textarea>
                                            <div class="pf-chat-meta">
                                                <span class="pf-chat-context"><?php echo esc_html__('Using Brand Context from Settings', 'pageforge'); ?></span>
                                                <label class="pf-chat-toggle">
                                                    <input type="checkbox" id="pf-chat-include-urls" checked />
                                                    <span><?php echo esc_html__('Include recent site URLs for context', 'pageforge'); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="pf-chat-actions">
											<button class="button button-primary" id="pf-chat-send" <?php disabled(!$ai_context['has_key']); ?>><?php echo esc_html__('Send', 'pageforge'); ?></button>
                                            <button class="button" id="pf-chat-new"><?php echo esc_html__('New chat', 'pageforge'); ?></button>
                                            <button class="button" id="pf-chat-export"><?php echo esc_html__('Export', 'pageforge'); ?></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="pf-ai-empty-state" id="pf-ai-empty-state" hidden>
                                    <div class="pf-card">
                                        <div class="pf-card-head">
                                            <div>
                                                <div class="pf-h"><?php echo esc_html__('Start a plan with one click', 'pageforge'); ?></div>
                                                <div class="pf-sub"><?php echo esc_html__('Use a quick prompt to pre-fill the planner with a common scenario.', 'pageforge'); ?></div>
                                            </div>
                                            <span class="pf-tag"><?php echo esc_html__('Templates', 'pageforge'); ?></span>
                                        </div>
                                        <div class="pf-prompt-list" id="pf-prompt-list">
                                            <div class="pf-chip" data-prompt="Create a complete website plan for a local service business. Include sitemap, pages, key sections per page, SEO keywords, internal linking, and a 30-day launch checklist.">
                                                <div>
                                                    <div class="pf-chip-title"><?php echo esc_html__('Full website plan', 'pageforge'); ?></div>
                                                    <div class="pf-chip-sub"><?php echo esc_html__('Sitemap + sections + SEO + launch checklist', 'pageforge'); ?></div>
                                                </div>
                                                <span class="pf-tag"><?php echo esc_html__('Plan', 'pageforge'); ?></span>
                                            </div>
                                            <div class="pf-chip" data-prompt="Generate a high-converting Home page wireframe: hero, trust, services, process, testimonials, FAQs, CTA. Provide copy suggestions and recommended headings.">
                                                <div>
                                                    <div class="pf-chip-title"><?php echo esc_html__('Home page wireframe', 'pageforge'); ?></div>
                                                    <div class="pf-chip-sub"><?php echo esc_html__('Conversion-focused structure + heading set', 'pageforge'); ?></div>
                                                </div>
                                                <span class="pf-tag"><?php echo esc_html__('Home', 'pageforge'); ?></span>
                                            </div>
                                            <div class="pf-chip" data-prompt="Create a Services page structure and propose 8-15 service subpages. Include URL slugs, title tags, and suggested FAQs.">
                                                <div>
                                                    <div class="pf-chip-title"><?php echo esc_html__('Services architecture', 'pageforge'); ?></div>
                                                    <div class="pf-chip-sub"><?php echo esc_html__('Subpages, slugs, title tags, FAQs', 'pageforge'); ?></div>
                                                </div>
                                                <span class="pf-tag"><?php echo esc_html__('SEO', 'pageforge'); ?></span>
                                            </div>
                                            <div class="pf-chip" data-prompt="Create a Local SEO plan: city/area pages, GBP optimization checklist, review strategy, citations, and schema recommendations.">
                                                <div>
                                                    <div class="pf-chip-title"><?php echo esc_html__('Local SEO plan', 'pageforge'); ?></div>
                                                    <div class="pf-chip-sub"><?php echo esc_html__('Location pages + GBP + schema', 'pageforge'); ?></div>
                                                </div>
                                                <span class="pf-tag"><?php echo esc_html__('Local', 'pageforge'); ?></span>
                                            </div>
                                        </div>
                                        <p class="pf-note pf-mt-sm">
                                            <?php echo esc_html__('Planner uses your Brand Context / Voice from Settings, your site details, and recent URLs (when enabled). Export produces Markdown you can save or share.', 'pageforge'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

	                            <div class="pf-ai-tools">
	                                <div class="pf-card pf-mt-lg">
	                                    <div class="pf-card-head">
	                                        <div>
	                                            <div class="pf-h"><?php echo esc_html__('AI SEO Report / Planner', 'pageforge'); ?></div>
	                                            <div class="pf-sub"><?php echo esc_html__('Generate a prioritized SEO plan for your site. Saves the latest report in the database.', 'pageforge'); ?></div>
	                                        </div>
	                                        <span class="pf-tag"><?php echo esc_html__('Planner', 'pageforge'); ?></span>
	                                    </div>
                                    <div class="pf-field">
                                        <label>
                                            <input type="checkbox" id="pf-seo-include-urls" value="1" checked />
                                            <?php echo esc_html__('Include up to 20 recent URLs in the report context', 'pageforge'); ?>
                                        </label>
                                    </div>
                                    <?php if (!$can_seo_report): ?>
                                        <div class="pf-note"><?php echo esc_html__('SEO Report generation requires an Administrator role (manage_options).', 'pageforge'); ?></div>
                                    <?php endif; ?>
                                    <div class="pf-actions">
											<button class="button button-primary" id="pf-ai-seo-generate" <?php disabled(!$can_seo_report); ?>><?php echo esc_html__('Generate Report', 'pageforge'); ?></button>
                                        <button class="button" id="pf-ai-seo-download-json" disabled><?php echo esc_html__('Download JSON', 'pageforge'); ?></button>
                                        <button class="button" id="pf-ai-seo-download-md" disabled><?php echo esc_html__('Download Markdown', 'pageforge'); ?></button>

                                    </div>
	                                    <div id="pf-ai-seo-output" class="pf-output pf-output-large"></div>
	                                </div>
	                            </div>
	                        </div>
	                    </section>

                </div>
            </div>
        </div>
	        <?php self::checkout_snippet(); }

	    /**
	     * Admin screen: Meta Title & Description generator.
	     * Free: single post optimization.
	     * Premium: bulk optimization.
	     */
	    public static function render_ai_meta() {
	        if (!\current_user_can('edit_posts')) return;
	        $s = settings_get();
            $ai_context = self::active_ai_context($s);

			// Build a dynamic list of post types for bulk optimization.
			// Keep a few common types at the top, then include any other UI-visible CPTs.
			$pt_objects = \get_post_types(['show_ui' => true], 'objects');
			$excluded = [
				'attachment',
				'revision',
				'nav_menu_item',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'pageforge_template', // Template CPT is not content that should be SEO-optimized.
			];
			$preferred = [
				'page'    => __('Pages', 'pageforge'),
				'post'    => __('Posts', 'pageforge'),
				'pageforge_page' => __('PageForge Pages (pageforge_page)', 'pageforge'),
				'product' => __('Products (product)', 'pageforge'),
			];
			$extra_pts = [];
			foreach ($pt_objects as $pt => $obj) {
				$pt = (string) $pt;
				if (in_array($pt, $excluded, true)) {
					continue;
				}
				if (isset($preferred[$pt])) {
					continue;
				}
				$label = '';
				if (is_object($obj) && isset($obj->labels) && is_object($obj->labels) && !empty($obj->labels->name)) {
					$label = (string) $obj->labels->name;
				} elseif (is_object($obj) && !empty($obj->label)) {
					$label = (string) $obj->label;
				}
				if ($label === '') {
					$label = $pt;
				}
				$extra_pts[$pt] = $label;
			}
			// Sort custom post types alphabetically by label for usability.
			if (!empty($extra_pts)) {
				\natcasesort($extra_pts);
			}
	        ?>
	        <div class="wrap pf-wrap pf-shell">
	            <?php self::topbar('ai', __('Meta Title & Description', 'pageforge')); ?>
	            <div class="pf-shell-main">
	                <header class="pf-page-header">
	                    <div class="pf-page-title-group">
	                        <h1 class="pf-page-title"><?php echo esc_html__('Meta Title & Description', 'pageforge'); ?></h1>
	                        <p class="pf-page-subtitle"><?php echo esc_html__('Generate search and social metadata, then save it through a supported SEO integration or PageForge built-in metadata.', 'pageforge'); ?></p>
	                    </div>
	                </header>

	                <?php if (!$ai_context['has_key']) {
                        self::render_ai_key_notice($ai_context);
                    } ?>

	                <div class="pf-page-layout">
	                    <main class="pf-page-main">
	                        <div class="pf-card">
	                            <div class="pf-card-head">
	                                <div>
	                                    <div class="pf-h"><?php echo esc_html__('Single page optimization', 'pageforge'); ?></div>
	                                    <div class="pf-sub"><?php echo esc_html__('Search any page/post and generate its meta.', 'pageforge'); ?></div>
	                                </div>
	                                <span class="pf-tag"><?php echo esc_html__('Single', 'pageforge'); ?></span>
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Search post', 'pageforge'); ?></label>
	                                <input type="text" id="pf-post-search" class="regular-text" placeholder="<?php echo esc_attr__('Type a title…', 'pageforge'); ?>" autocomplete="off" />
	                                <div id="pf-post-results" class="pf-search-results" style="display:none"></div>
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Selected Post ID', 'pageforge'); ?></label>
	                                <input type="number" id="pf-post-id" class="small-text" min="1" />
	                            </div>
	                            <div class="pf-actions">
	                                <button class="button button-primary" id="pf-ai-meta-generate" <?php disabled(!$ai_context['has_key']); ?>><?php echo esc_html__('Generate & Save Meta', 'pageforge'); ?></button>
	                            </div>
	                            <div id="pf-ai-meta-output" class="pf-output"></div>
	                        </div>

	                    </main>
	                </div>
	            </div>
	        </div>
	        <?php self::checkout_snippet();
	    }

	    /**
	     * Admin screen: Blog Generator.
	     */
	    public static function render_ai_blog() {
		if (!\current_user_can('edit_posts')) return;
		$s = settings_get();
	        $ai_context = self::active_ai_context($s);
	        ?>
	        <div class="wrap pf-wrap pf-shell">
	            <?php self::topbar('blog', __('Blog Generator', 'pageforge')); ?>
	            <div class="pf-shell-main">
	                <header class="pf-page-header">
	                    <div class="pf-page-title-group">
	                        <h1 class="pf-page-title"><?php echo esc_html__('Blog Generator', 'pageforge'); ?></h1>
	                        <p class="pf-page-subtitle"><?php echo esc_html__('Generate a standard WordPress blog post with SEO metadata and an optional Pexels featured image.', 'pageforge'); ?></p>
	                    </div>
	                </header>

	                <?php if (!$ai_context['has_key']) {
                        self::render_ai_key_notice($ai_context);
                    } ?>

	                <div class="pf-page-layout">
	                    <main class="pf-page-main">
	                        <div class="pf-card">
	                            <div class="pf-card-head">
	                                <div>
	                                    <div class="pf-h"><?php echo esc_html__('AI Blog Generator', 'pageforge'); ?></div>
	                                    <div class="pf-sub"><?php echo esc_html__('Provide a topic and optional target keywords. Output is always saved as a WordPress post.', 'pageforge'); ?></div>
	                                </div>
	                                <span class="pf-tag"><?php echo esc_html__('Blog', 'pageforge'); ?></span>
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Topic', 'pageforge'); ?></label>
	                                <input type="text" id="pf-blog-topic" class="regular-text" placeholder="<?php echo esc_attr__('e.g. Local SEO checklist for plumbers', 'pageforge'); ?>" />
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Audience (optional)', 'pageforge'); ?></label>
	                                <input type="text" id="pf-blog-audience" class="regular-text" placeholder="<?php echo esc_attr__('e.g. Small business owners', 'pageforge'); ?>" />
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Target keywords (optional)', 'pageforge'); ?></label>
	                                <textarea id="pf-blog-keywords" class="large-text" rows="3" placeholder="<?php echo esc_attr__('Add keywords separated by commas or new lines', 'pageforge'); ?>"></textarea>
	                            </div>
	                            <div class="pf-field">
	                                <label><?php echo esc_html__('Content length', 'pageforge'); ?></label>
                                    <select id="pf-blog-length">
                                        <option value="short"><?php echo esc_html__('Short (~400 words)', 'pageforge'); ?></option>
                                        <option value="medium" selected><?php echo esc_html__('Medium (~700 words)', 'pageforge'); ?></option>
                                        <option value="long"><?php echo esc_html__('Long (~1000 words max in free)', 'pageforge'); ?></option>
                                    </select>
	                            </div>
                                <input type="hidden" id="pf-blog-post-type" value="post" />
	                            <div class="pf-field-row">
	                                <div class="pf-field">
	                                    <label><?php echo esc_html__('Status', 'pageforge'); ?></label>
	                                    <select id="pf-blog-status">
	                                        <option value="draft"><?php echo esc_html__('Draft', 'pageforge'); ?></option>
	                                        <option value="pending"><?php echo esc_html__('Pending', 'pageforge'); ?></option>
	                                    </select>
	                                </div>
	                            </div>
	                            <div class="pf-field">
	                                <label>
	                                    <input type="checkbox" id="pf-blog-image" value="1" />
	                                    <?php echo esc_html__('Include featured image (Pexels)', 'pageforge'); ?>
	                                </label>
                                    <p class="description"><?php echo \wp_kses_post(sprintf(__('Featured image searches use Pexels. Photos provided by %s.', 'pageforge'), '<a href="https://www.pexels.com/" target="_blank" rel="noopener noreferrer">Pexels</a>')); ?></p>
	                            </div>
	                            <div class="pf-actions">
	                                <button class="button button-primary" id="pf-ai-blog-generate" <?php disabled(!$ai_context['has_key']); ?>><?php echo esc_html__('Generate Post', 'pageforge'); ?></button>
	                            </div>
	                            <div id="pf-ai-blog-output" class="pf-output"></div>
	                        </div>
	                    </main>
	                </div>
	            </div>
	        </div>
	        <?php self::checkout_snippet();
	    }

    // ---------- Template Meta ----------
     public static function register_template_metabox() {
         foreach (Compatibility::template_post_types() as $template_post_type) {
             \add_meta_box('pageforge_template_meta', __('PageForge Template Settings', 'pageforge'), [__CLASS__, 'metabox_template'], $template_post_type, 'normal', 'high');
         }
     }

    public static function metabox_template($post) {
        $title_pattern = Compatibility::read_meta((int) $post->ID, 'title_pattern', '');
        $meta_description_pattern = Compatibility::read_meta((int) $post->ID, 'meta_description_pattern', '');
        $slug_pattern  = Compatibility::read_meta((int) $post->ID, 'slug_pattern', '');
        $post_type = (string) Compatibility::read_meta((int) $post->ID, 'post_type', '');
        $global_type = Generator::resolve_generated_post_type((string) array_get(settings_get(), 'post_type', Compatibility::post_type('generated')));
        $effective_type = $post_type !== '' ? Generator::resolve_generated_post_type($post_type) : $global_type;
        $parent_page_raw = (string) Compatibility::read_meta((int) $post->ID, 'parent_page_id', '0');
        $parent_page_id = ctype_digit($parent_page_raw) ? (int) $parent_page_raw : 0;
        $post_status   = Compatibility::read_meta((int) $post->ID, 'default_status', '') ?: '';
        $base_post_id  = (int) Compatibility::read_meta((int) $post->ID, 'base_post_id', 0);
        $base_post_types = array_values(array_filter(array_merge([
            'page',
            'post',
        ], Compatibility::generated_post_types(), [
            'elementor_library',
            'et_pb_layout',
            'wp_block',
            'wp_template',
            'wp_template_part',
        ]), 'post_type_exists'));
        $base_choices  = get_posts([
            'post_type'      => $base_post_types,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        \wp_nonce_field('pageforge_template_save','pageforge_template_nonce');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('Title Pattern', 'pageforge'); ?></th>
                <td><input type="text" class="regular-text" name="pageforge_title_pattern" value="<?php echo esc_attr($title_pattern); ?>" placeholder="<?php echo esc_attr__('Best {Service} in {City}, {State}', 'pageforge'); ?>"/></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Meta Description Pattern', 'pageforge'); ?></th>
                <td>
                    <textarea class="large-text" rows="2" name="pageforge_meta_description_pattern" placeholder="<?php echo esc_attr__('Need {Service} in {City}, {State}? Contact us for fast local help.', 'pageforge'); ?>"><?php echo esc_textarea($meta_description_pattern); ?></textarea>
                    <p class="description"><?php echo esc_html__('Optional. CSV placeholders are replaced for each generated post and written to PageForge and the active supported SEO plugin. Leave blank to use generated metadata.', 'pageforge'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Slug Pattern', 'pageforge'); ?></th>
                <td><input type="text" class="regular-text" name="pageforge_slug_pattern" value="<?php echo esc_attr($slug_pattern); ?>" placeholder="{service}-in-{city}-{state}"/></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Target Post Type', 'pageforge'); ?></th>
                <td>
                    <select name="pageforge_post_type" id="pageforge-template-post-type" data-global-type="<?php echo esc_attr($global_type); ?>">
                        <option value="" <?php selected($post_type, ''); ?>><?php echo esc_html(sprintf(__('Use global default (%s)', 'pageforge'), $global_type)); ?></option>
                        <option value="<?php echo esc_attr(Compatibility::post_type('generated')); ?>" <?php selected($post_type,Compatibility::post_type('generated')); ?>><?php echo esc_html__('PageForge Page (pageforge_page)', 'pageforge'); ?></option>
                        <?php if (in_array($post_type, Compatibility::generated_post_types(), true) && $post_type !== Compatibility::post_type('generated')) : ?>
                        <option value="<?php echo esc_attr($post_type); ?>" selected><?php echo esc_html($post_type); ?></option>
                        <?php endif; ?>
                        <option value="page" <?php selected($post_type,'page'); ?>><?php echo esc_html__('Page', 'pageforge'); ?></option>
                        <option value="post" <?php selected($post_type,'post'); ?>><?php echo esc_html__('Post', 'pageforge'); ?></option>
                    </select>
                </td>
            </tr>
            <tr id="pageforge-parent-page-row" <?php echo $effective_type !== 'page' ? 'hidden' : ''; ?>>
                <th scope="row"><?php echo esc_html__('Parent Page', 'pageforge'); ?></th>
                <td><?php $eligible_parents = static function ($pages) { return array_values(array_filter($pages, static function ($page) { return current_user_can('read_post', $page->ID) && current_user_can('edit_post', $page->ID); })); };
                add_filter('get_pages', $eligible_parents);
                $parent_dropdown = wp_dropdown_pages(['name' => 'pageforge_parent_page_id', 'selected' => $parent_page_id, 'show_option_none' => __('None (root Page)', 'pageforge'), 'option_none_value' => '0', 'post_status' => ['publish', 'private', 'draft', 'pending', 'future'], 'echo' => 0]);
                if ($parent_dropdown === '') {
                    $parent_dropdown = '<select name="pageforge_parent_page_id" id="pageforge_parent_page_id"><option value="0">' . esc_html__('None (root Page)', 'pageforge') . '</option></select>';
                }
                if ($parent_page_raw !== '' && $parent_page_raw !== '0' && (strpos($parent_dropdown, 'value="' . esc_attr($parent_page_raw) . '"') === false || !ctype_digit($parent_page_raw))) {
                    $parent_dropdown = str_replace('</select>', '<option value="' . esc_attr($parent_page_raw) . '" selected>' . esc_html__('Invalid configured parent — choose an existing Page or None', 'pageforge') . '</option></select>', $parent_dropdown);
                }
                echo $parent_dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core dropdown plus escaped option.

                remove_filter('get_pages', $eligible_parents); ?>
                <p class="description"><?php echo esc_html__('Native Pages only. Choose an existing Page for /parent/child/ URLs; choose None for /child/. Parent permissions are checked before generation.', 'pageforge'); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Generated Post Status', 'pageforge'); ?></th>
                <td>
                    <select name="pageforge_post_status">
                        <option value="" <?php selected($post_status, ''); ?>><?php echo esc_html__('Use Generate screen setting', 'pageforge'); ?></option>
                        <option value="publish" <?php selected($post_status, 'publish'); ?>><?php echo esc_html__('Public / Published', 'pageforge'); ?></option>
                        <option value="private" <?php selected($post_status, 'private'); ?>><?php echo esc_html__('Private', 'pageforge'); ?></option>
                        <option value="draft" <?php selected($post_status, 'draft'); ?>><?php echo esc_html__('Draft', 'pageforge'); ?></option>
                        <option value="pending" <?php selected($post_status, 'pending'); ?>><?php echo esc_html__('Pending Review', 'pageforge'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('When set, PageForge uses this status automatically for every post generated from this template.', 'pageforge'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Base Layout to Clone', 'pageforge'); ?></th>
                <td>
                    <select name="pageforge_base_post_select" id="pageforge_base_post_select" onchange="var f=document.getElementById('pageforge_base_post_id');if(f){f.value=this.value;}">
                        <option value="0"><?php esc_html_e('— None —', 'pageforge'); ?></option>
                        <?php foreach ($base_choices as $p) : ?>
                            <option value="<?php echo (int) $p->ID; ?>" <?php selected($base_post_id, (int) $p->ID); ?>>
                                <?php echo esc_html($p->post_title . ' (#' . $p->ID . ' – ' . $p->post_type . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Pick a page, post, Elementor template, Divi layout, or reusable block to clone its layout, or enter an ID manually. This does not set the Parent Page or URL hierarchy.', 'pageforge'); ?>
                    </p>
                    <label>
                        <?php esc_html_e('Base Page/Template ID:', 'pageforge'); ?>
                        <input type="number" class="small-text" id="pageforge_base_post_id" name="pageforge_base_post_id" value="<?php echo (int) $base_post_id; ?>" />
                    </label>
                </td>
            </tr>
        </table>
        <p><?php echo esc_html__('Use placeholders anywhere in the content editor and above patterns: {City}, {State}, {Service}, and any CSV column header you provide.', 'pageforge'); ?></p>
        <?php
    }

     /**
      * Template lists must cover both namespaces so a mixed site never hides
      * legacy templates from the operator.
      */
     private static function template_query_args(array $overrides = []): array {
         return array_merge([
             'post_type'   => Compatibility::template_post_types(),
             'post_status' => 'any',
             'numberposts' => -1,
         ], $overrides);
     }

     public static function save_template_meta($post_id) {
         $nonce = isset($_POST['pageforge_template_nonce']) ? sanitize_text_field(wp_unslash($_POST['pageforge_template_nonce'])) : '';
         if (!$nonce || !\wp_verify_nonce($nonce,'pageforge_template_save')) return;
         if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
         if (!\current_user_can('edit_post', $post_id)) return;
         $allowed_statuses = ['publish', 'private', 'draft', 'pending'];
         $post_status = \sanitize_key(isset($_POST['pageforge_post_status']) ? wp_unslash($_POST['pageforge_post_status']) : '');
         if (!in_array($post_status, $allowed_statuses, true)) {
             $post_status = '';
         }
         $target_post_type = \sanitize_text_field(isset($_POST['pageforge_post_type']) ? wp_unslash($_POST['pageforge_post_type']) : '');
         Compatibility::write_meta($post_id, 'title_pattern', \sanitize_text_field(isset($_POST['pageforge_title_pattern']) ? wp_unslash($_POST['pageforge_title_pattern']) : ''));
         Compatibility::write_meta($post_id, 'meta_description_pattern', \sanitize_textarea_field(isset($_POST['pageforge_meta_description_pattern']) ? wp_unslash($_POST['pageforge_meta_description_pattern']) : ''));
         Compatibility::write_meta($post_id, 'slug_pattern', \sanitize_text_field(isset($_POST['pageforge_slug_pattern']) ? wp_unslash($_POST['pageforge_slug_pattern']) : ''));
         if ($target_post_type === '') {
             foreach (Compatibility::meta_keys('post_type') as $key) { \delete_post_meta($post_id, $key); }
         } elseif (Generator::resolve_generated_post_type($target_post_type) !== '') {
             Compatibility::write_meta($post_id, 'post_type', Generator::resolve_generated_post_type($target_post_type));
         }
         $saved_type = (string) Compatibility::read_meta((int) $post_id, 'post_type', '');
         $effective_type = Generator::resolve_generated_post_type($saved_type !== '' ? $saved_type : (string) array_get(settings_get(), 'post_type', Compatibility::post_type('generated')));
         $raw_parent = isset($_POST['pageforge_parent_page_id']) ? sanitize_text_field(wp_unslash($_POST['pageforge_parent_page_id'])) : '0';
         Compatibility::write_meta($post_id, 'parent_page_id', $effective_type === 'page' ? (ctype_digit($raw_parent) ? $raw_parent : 'invalid') : 0);
         Compatibility::write_meta($post_id, 'parent_source', $effective_type === 'page' ? 'static' : 'none');
         Compatibility::write_meta($post_id, 'default_status', $post_status);
         Compatibility::write_meta($post_id, 'base_post_id', isset($_POST['pageforge_base_post_id']) ? absint(wp_unslash($_POST['pageforge_base_post_id'])) : 0);
     }


     // ---------- Basic SEO Meta (when no SEO plugin is active) ----------
     public static function register_seo_metabox() {
         if (pageforge_seo_plugins_active()) return;
         $screens = ['page', 'post', 'pageforge_page'];
          foreach ($screens as $screen) {
              \add_meta_box('pageforge_seo', __('PageForge SEO', 'pageforge'), [__CLASS__, 'metabox_seo'], $screen, 'normal', 'default');
         }
     }

     public static function metabox_seo($post) {
         \wp_nonce_field('pageforge_seo_save', 'pageforge_seo_nonce');

         $meta_title = (string) \get_post_meta($post->ID, '_pageforge_meta_title', true);
         $meta_desc  = (string) \get_post_meta($post->ID, '_pageforge_meta_description', true);
         $focus_kw   = (string) \get_post_meta($post->ID, '_pageforge_focus_keyword', true);
         ?>
         <p class="description"><?php echo esc_html__('PageForge can output basic SEO title and meta description when no dedicated SEO plugin is active.', 'pageforge'); ?></p>
         <table class="form-table">
             <tr>
                 <th scope="row"><?php echo esc_html__('SEO Title', 'pageforge'); ?></th>
                 <td>
                     <input type="text" class="regular-text" name="pageforge_meta_title" value="<?php echo esc_attr($meta_title); ?>" maxlength="70" />
                     <p class="description"><?php echo esc_html__('Will be used as the browser title when no SEO plugin is installed.', 'pageforge'); ?></p>
                 </td>
             </tr>
             <tr>
                 <th scope="row"><?php echo esc_html__('Meta Description', 'pageforge'); ?></th>
                 <td>
                     <textarea name="pageforge_meta_description" rows="3" class="large-text"><?php echo esc_textarea($meta_desc); ?></textarea>
                     <p class="description"><?php echo esc_html__('Recommended length: up to ~155 characters.', 'pageforge'); ?></p>
                 </td>
             </tr>
             <tr>
                 <th scope="row"><?php echo esc_html__('Focus Keyword (optional)', 'pageforge'); ?></th>
                 <td>
                     <input type="text" class="regular-text" name="pageforge_focus_keyword" value="<?php echo esc_attr($focus_kw); ?>" />
                 </td>
             </tr>
         </table>
         <?php
     }

     public static function save_seo_meta($post_id) {
         if (pageforge_seo_plugins_active()) return;
         $nonce = isset($_POST['pageforge_seo_nonce']) ? sanitize_text_field(wp_unslash($_POST['pageforge_seo_nonce'])) : '';
         if (!$nonce || !\wp_verify_nonce($nonce, 'pageforge_seo_save')) return;
         if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
         if (!\current_user_can('edit_post', $post_id)) return;

         $meta_title = isset($_POST['pageforge_meta_title']) ? \sanitize_text_field(wp_unslash($_POST['pageforge_meta_title'])) : '';
         $meta_desc  = isset($_POST['pageforge_meta_description']) ? \sanitize_textarea_field(wp_unslash($_POST['pageforge_meta_description'])) : '';
         $focus_kw   = isset($_POST['pageforge_focus_keyword']) ? \sanitize_text_field(wp_unslash($_POST['pageforge_focus_keyword'])) : '';

         Compatibility::write_meta($post_id, 'meta_title', $meta_title);
         Compatibility::write_meta($post_id, 'meta_description', $meta_desc);
         Compatibility::write_meta($post_id, 'focus_keyword', $focus_kw);

         // Keep Yoast/Rank Math keys in sync in case a plugin is installed later.
         if ($meta_title !== '') {
             \update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
             \update_post_meta($post_id, 'rank_math_title', $meta_title);
         }
         if ($meta_desc !== '') {
             \update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
             \update_post_meta($post_id, 'rank_math_description', $meta_desc);
         }
         if ($focus_kw !== '') {
             \update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
             \update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
         }
     }

    // ---------- Connections ----------
    public static function render_connections(): void {
        if (!\current_user_can('manage_options')) return;
        $settings = settings_get();
        $mcp_enabled = !empty($settings['mcp_enabled']);
        $rest_url = \untrailingslashit((string) \home_url()) . '/wp-json/pageforge/v1/mcp';
        ?>
        <div class="wrap pf-wrap pf-shell">
            <?php self::topbar('connections', __('Connections', 'pageforge')); ?>
            <div class="pf-shell-main">
                <header class="pf-page-header">
                    <div class="pf-page-title-group">
                        <h1 class="pf-page-title"><?php echo esc_html__('Connections', 'pageforge'); ?></h1>
                        <p class="pf-page-subtitle"><?php echo esc_html__('AI providers live in Settings. This screen covers external AI clients and services PageForge talks to.', 'pageforge'); ?></p>
                    </div>
                </header>

                <div class="pf-page-layout">
                    <main class="pf-page-main">
                        <section class="pf-card pf-mcp-panel">
                            <div class="pf-mcp-panel-head">
                                <div>
                                    <h2 class="pf-mcp-title"><?php echo esc_html__('MCP / AI Clients', 'pageforge'); ?></h2>
                                    <p class="description">
                                        <?php echo esc_html__('Let ChatGPT, Claude, or any MCP-compatible client read your PageForge site: templates, generated pages, SEO status, and preview/validate tools. Protocol 2026-07-28.', 'pageforge'); ?>
                                    </p>
                                </div>
                                <span class="pf-badge <?php echo $mcp_enabled ? 'ok' : 'warn'; ?>" id="pf-mcp-state-badge">
                                    <?php echo esc_html($mcp_enabled ? __('Enabled', 'pageforge') : __('Disabled', 'pageforge')); ?>
                                </span>
                            </div>

                            <div class="pf-mcp-toggle-row">
                                <label class="pf-toggle">
                                    <input type="checkbox" id="pf-mcp-enable" <?php checked($mcp_enabled); ?> />
                                    <span><?php echo esc_html__('Enable PageForge MCP Lite (read, analyze, validate, preview only)', 'pageforge'); ?></span>
                                </label>
                                <span class="pf-pill" title="<?php echo esc_attr__('PageForge Free never registers a write MCP tool.', 'pageforge'); ?>">
                                    <?php echo esc_html__('MCP Lite · Read & Preview', 'pageforge'); ?>
                                </span>
                            </div>

                            <table class="pf-mcp-meta">
                                <tr><th scope="row"><?php echo esc_html__('Protocol', 'pageforge'); ?></th><td>2026-07-28</td></tr>
                                <tr><th scope="row"><?php echo esc_html__('Endpoint', 'pageforge'); ?></th><td><code id="pf-mcp-endpoint"><?php echo esc_html($rest_url); ?></code></td></tr>
                            </table>

                            <?php
                            /*
                             * Two distinct authentication models, kept visually
                             * apart because conflating them is what previously
                             * made these buttons unable to complete a
                             * connection:
                             *
                             *  - ChatGPT and Claude are remote connectors. They
                             *    perform OAuth discovery against this site and
                             *    never accept a pasted PageForge token, so their
                             *    action is "Set up", not "Connect", and it mints
                             *    nothing.
                             *  - Other MCP clients supply an Authorization
                             *    header themselves, so they get a Personal
                             *    Access Token.
                             */
                            ?>
                            <h3 class="pf-mcp-subhead"><?php echo esc_html__('Remote connectors (OAuth)', 'pageforge'); ?></h3>
                            <p class="description pf-mcp-subhead-note"><?php echo esc_html__('These clients sign in to your site with OAuth. You approve the connection in WordPress; no token is copied by hand.', 'pageforge'); ?></p>
                            <div class="pf-mcp-clients">
                                <div class="pf-mcp-client-card">
                                    <div class="pf-mcp-client-head">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/icon-chatgpt.png'); ?>" width="28" height="28" alt="ChatGPT" />
                                        <strong><?php echo esc_html__('ChatGPT', 'pageforge'); ?></strong>
                                    </div>
                                    <span class="pf-mcp-client-state" data-pf-mcp-state-for="chatgpt"><?php echo esc_html__('Checking…', 'pageforge'); ?></span>
                                    <button type="button" class="button button-primary pf-mcp-oauth-setup" data-pf-mcp-client="chatgpt" data-pf-mcp-label="ChatGPT"><?php echo esc_html__('Set up ChatGPT', 'pageforge'); ?></button>
                                </div>
                                <div class="pf-mcp-client-card">
                                    <div class="pf-mcp-client-head">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/icon-claude.png'); ?>" width="28" height="28" alt="Claude" />
                                        <strong><?php echo esc_html__('Claude', 'pageforge'); ?></strong>
                                    </div>
                                    <span class="pf-mcp-client-state" data-pf-mcp-state-for="claude"><?php echo esc_html__('Checking…', 'pageforge'); ?></span>
                                    <button type="button" class="button button-primary pf-mcp-oauth-setup" data-pf-mcp-client="claude" data-pf-mcp-label="Claude"><?php echo esc_html__('Set up Claude', 'pageforge'); ?></button>
                                </div>
                            </div>

                            <h3 class="pf-mcp-subhead"><?php echo esc_html__('Other MCP clients (personal access token)', 'pageforge'); ?></h3>
                            <div class="pf-mcp-clients">
                                <div class="pf-mcp-client-card pf-mcp-client-card-wide">
                                    <div class="pf-mcp-client-head">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/mcp.svg'); ?>" width="28" height="28" alt="" />
                                        <strong><?php echo esc_html__('Other MCP Clients', 'pageforge'); ?></strong>
                                    </div>
                            <p class="description"><?php echo esc_html__('For clients where you supply an authentication header yourself: VS Code, Codex, GitHub Copilot, Roo Code, Cursor, Claude Code, or any generic MCP client.', 'pageforge'); ?></p>
                                    <button type="button" class="button pf-mcp-create-pat" data-pf-mcp-client="generic" data-pf-mcp-label="Generic MCP Client"><?php echo esc_html__('Create personal access token', 'pageforge'); ?></button>
                                </div>
                            </div>

                            <?php
                            /*
                             * Claude's remote connector UI can ask for a Client
                             * ID and Secret. Those are OAuth client credentials
                             * and are NOT the Claude API key in Settings, a
                             * PageForge personal access token, or a PageForge
                             * licence key, so they get their own section rather
                             * than being confused with any of the three.
                             */
                            ?>
                            <h3 class="pf-mcp-subhead"><?php echo esc_html__('OAuth clients', 'pageforge'); ?></h3>
                            <p class="description pf-mcp-subhead-note"><?php echo esc_html__('Only needed when a connector asks you to supply a Client ID and Client Secret. These are separate from your AI provider API keys.', 'pageforge'); ?></p>
                            <div id="pf-mcp-oauth-client-list" class="pf-mcp-credential-list">
                                <p class="pf-mcp-empty"><?php echo esc_html__('Loading…', 'pageforge'); ?></p>
                            </div>
                            <p>
                                <button type="button" class="button" id="pf-mcp-create-oauth-client"><?php echo esc_html__('Create OAuth client', 'pageforge'); ?></button>
                            </p>

                            <h3 class="pf-mcp-subhead"><?php echo esc_html__('Personal access tokens', 'pageforge'); ?></h3>
                            <div id="pf-mcp-credential-list" class="pf-mcp-credential-list">
                                <p class="pf-mcp-empty"><?php echo esc_html__('Loading…', 'pageforge'); ?></p>
                            </div>

                            <h3 class="pf-mcp-subhead"><?php echo esc_html__('OAuth grants', 'pageforge'); ?></h3>
                            <div id="pf-mcp-grant-list" class="pf-mcp-credential-list">
                                <p class="pf-mcp-empty"><?php echo esc_html__('Loading…', 'pageforge'); ?></p>
                            </div>
                        </section>

                        <?php
                        /*
                         * Diagnostics are on the page rather than behind a
                         * button because the failure they explain (a stripped
                         * Authorization header, a blocked REST route, a
                         * subdirectory install) is invisible from the client
                         * side and produces a generic "connection failed".
                         */
                        $diagnostics = Mcp_Diagnostics::run();
                        $overall = Mcp_Diagnostics::overall($diagnostics);
                        ?>
                        <section class="pf-card pf-mcp-panel">
                            <div class="pf-mcp-panel-head">
                                <div>
                                    <h2 class="pf-mcp-title"><?php echo esc_html__('Connection diagnostics', 'pageforge'); ?></h2>
                                    <p class="description"><?php echo esc_html__('What PageForge can verify from inside WordPress about whether an AI client can reach and authenticate to this site.', 'pageforge'); ?></p>
                                </div>
                                <span class="pf-badge <?php echo esc_attr(Mcp_Diagnostics::OK === $overall ? 'ok' : (Mcp_Diagnostics::FAIL === $overall ? 'bad' : 'warn')); ?>">
                                    <?php
                                    echo esc_html(
                                        Mcp_Diagnostics::OK === $overall
                                            ? __('All checks passed', 'pageforge')
                                            : (Mcp_Diagnostics::FAIL === $overall ? __('Action required', 'pageforge') : __('Review recommended', 'pageforge'))
                                    );
                                    ?>
                                </span>
                            </div>
                            <?php
                            /*
                             * Problems first, in plain language. Protocol
                             * internals and endpoint URLs are real diagnostic
                             * value but they are not what a normal operator
                             * needs to read, so they live behind a disclosure.
                             */
                            $problems = array_values(array_filter($diagnostics, static function (array $check): bool {
                                return Mcp_Diagnostics::OK !== $check['state'];
                            }));
                            ?>
                            <?php if (empty($problems)) : ?>
                                <p class="pf-mcp-diag-summary pf-mcp-diag-ok">
                                    <?php echo esc_html__('MCP is ready. PageForge can serve OAuth discovery and accept authenticated AI clients.', 'pageforge'); ?>
                                </p>
                            <?php else : ?>
                                <p class="pf-mcp-diag-summary pf-mcp-diag-attention">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: number of diagnostic items needing attention. */
                                            _n(
                                                'Action required: %d item needs attention before AI clients can connect reliably.',
                                                'Action required: %d items need attention before AI clients can connect reliably.',
                                                count($problems),
                                                'pageforge'
                                            ),
                                            count($problems)
                                        )
                                    );
                                    ?>
                                </p>
                                <ul class="pf-mcp-diag-list">
                                    <?php foreach ($problems as $check) : ?>
                                        <li>
                                            <span class="pf-diag-dot pf-diag-<?php echo esc_attr($check['state']); ?>" aria-hidden="true"></span>
                                            <strong><?php echo esc_html($check['label']); ?></strong>
                                            <span class="screen-reader-text"><?php echo esc_html($check['state']); ?></span>
                                            <span class="pf-mcp-diag-detail"><?php echo esc_html($check['detail']); ?></span>
                                            <?php if ('' !== $check['remedy']) : ?>
                                                <span class="description pf-diag-remedy"><?php echo esc_html($check['remedy']); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <details class="pf-mcp-advanced">
                                <summary><?php echo esc_html__('Advanced diagnostics', 'pageforge'); ?></summary>

                                <table class="pf-mcp-diagnostics widefat striped">
                                    <caption class="screen-reader-text"><?php echo esc_html__('All PageForge MCP diagnostic checks', 'pageforge'); ?></caption>
                                    <thead>
                                        <tr>
                                            <th scope="col"><?php echo esc_html__('Check', 'pageforge'); ?></th>
                                            <th scope="col"><?php echo esc_html__('Result', 'pageforge'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($diagnostics as $check) : ?>
                                        <tr>
                                            <th scope="row">
                                                <span class="pf-diag-dot pf-diag-<?php echo esc_attr($check['state']); ?>" aria-hidden="true"></span>
                                                <?php echo esc_html($check['label']); ?>
                                                <span class="screen-reader-text"><?php echo esc_html($check['state']); ?></span>
                                            </th>
                                            <td>
                                                <?php echo esc_html($check['detail']); ?>
                                                <?php if ('' !== $check['remedy']) : ?>
                                                    <p class="description pf-diag-remedy"><?php echo esc_html($check['remedy']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <table class="pf-mcp-meta">
                                    <tr><th scope="row"><?php echo esc_html__('Protocol', 'pageforge'); ?></th><td><?php echo esc_html(Mcp_Server::PROTOCOL_VERSION); ?></td></tr>
                                    <tr><th scope="row"><?php echo esc_html__('Resource', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::resource_identifier()); ?></code></td></tr>
                                    <tr><th scope="row"><?php echo esc_html__('Protected resource metadata', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::protected_resource_metadata_url()); ?></code></td></tr>
                                    <tr><th scope="row"><?php echo esc_html__('Authorization server metadata', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::wellknown_as_url()); ?></code></td></tr>
                                    <tr><th scope="row"><?php echo esc_html__('Authorization endpoint', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::authorize_url()); ?></code></td></tr>
                                    <tr><th scope="row"><?php echo esc_html__('Token endpoint', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::token_url()); ?></code></td></tr>
                                </table>
                            </details>
                        </section>
                    </main>

                    <?php
                    /*
                     * The right column was empty on this screen, which both
                     * wasted half the width and left the setup instructions
                     * buried in a modal. It now carries contextual help that
                     * changes with the selected client, using the existing
                     * PageForge card/section classes rather than a new design
                     * system.
                     */
                    ?>
                    <aside class="pf-page-aside">
                        <div class="pf-card pf-mcp-help" id="pf-mcp-help">
                            <div class="pf-mcp-help-default" id="pf-mcp-help-default">
                                <h3><?php echo esc_html__('Connect an AI Client', 'pageforge'); ?></h3>
                                <p class="description">
                                    <?php echo esc_html__('Connect ChatGPT, Claude or another MCP-compatible AI client to securely inspect PageForge.', 'pageforge'); ?>
                                </p>
                                <p class="description">
                                    <?php echo esc_html__('Choose a client on the left to see its setup steps here.', 'pageforge'); ?>
                                </p>

                                <div class="pf-mcp-help-clients">
                                    <span class="pf-mcp-help-chip">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/icon-chatgpt.png'); ?>" width="16" height="16" alt="" />
                                        <?php echo esc_html__('ChatGPT', 'pageforge'); ?>
                                    </span>
                                    <span class="pf-mcp-help-chip">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/icon-claude.png'); ?>" width="16" height="16" alt="" />
                                        <?php echo esc_html__('Claude', 'pageforge'); ?>
                                    </span>
                                    <span class="pf-mcp-help-chip">
                                        <img src="<?php echo esc_url(PAGEFORGE_URL . 'assets/mcp/mcp.svg'); ?>" width="16" height="16" alt="" />
                                        <?php echo esc_html__('IDEs & other clients', 'pageforge'); ?>
                                    </span>
                                </div>

                                <p class="description pf-mcp-help-note">
                                    <?php echo esc_html__('PageForge Free lets an AI client read, analyse and preview. It never lets one change your site.', 'pageforge'); ?>
                                </p>
                            </div>

                            <div class="pf-mcp-help-detail" id="pf-mcp-help-detail" hidden>
                                <div class="pf-mcp-help-head">
                                    <img id="pf-mcp-help-icon" src="" width="20" height="20" alt="" />
                                    <h3 id="pf-mcp-help-title"></h3>
                                </div>
                                <p class="description" id="pf-mcp-help-intro"></p>
                                <ol class="pf-mcp-steps" id="pf-mcp-help-steps"></ol>
                                <p class="description pf-mcp-help-note" id="pf-mcp-help-caveat"></p>
                                <p>
                                    <button type="button" class="button-link" id="pf-mcp-help-back">
                                        <?php echo esc_html__('← All clients', 'pageforge'); ?>
                                    </button>
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>

        <?php
        /*
         * Two dialogs, not one. The previous single dialog said "Connection
         * created" and showed a raw token in an unexplained textarea for every
         * client, including the two that cannot use a token at all.
         */
        ?>
        <div id="pf-mcp-oauth-dialog" class="pf-mcp-dialog" hidden>
            <div class="pf-mcp-dialog-box" role="dialog" aria-modal="true" aria-labelledby="pf-mcp-oauth-title" tabindex="-1">
                <div class="pf-mcp-dialog-head">
                    <img id="pf-mcp-oauth-icon" src="" width="28" height="28" alt="" />
                    <h3 id="pf-mcp-oauth-title"><?php echo esc_html__('Set up connector', 'pageforge'); ?></h3>
                </div>
                <p class="description" id="pf-mcp-oauth-intro"></p>

                <table class="pf-mcp-meta">
                    <tr>
                        <th scope="row"><?php echo esc_html__('MCP server URL', 'pageforge'); ?></th>
                        <td>
                            <code id="pf-mcp-oauth-endpoint"><?php echo esc_html(Oauth::resource_identifier()); ?></code>
                            <button type="button" class="button button-small" id="pf-mcp-copy-endpoint" data-pf-copy-target="pf-mcp-oauth-endpoint"><?php echo esc_html__('Copy', 'pageforge'); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Authentication', 'pageforge'); ?></th>
                        <td><?php echo esc_html__('OAuth 2.1 (discovered automatically)', 'pageforge'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Requested access', 'pageforge'); ?></th>
                        <td>
                            <?php
                            $advertised_scopes = array_map([Mcp_Scopes::class, 'label'], Mcp_Scopes::advertised());
                            echo esc_html(implode(', ', $advertised_scopes));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('PageForge readiness', 'pageforge'); ?></th>
                        <td><span id="pf-mcp-oauth-readiness"><?php echo esc_html__('Checking…', 'pageforge'); ?></span></td>
                    </tr>
                </table>

                <ol class="pf-mcp-steps" id="pf-mcp-oauth-steps"></ol>

                <p class="description" id="pf-mcp-oauth-caveat"></p>

                <div class="pf-mcp-dialog-actions">
                    <button type="button" class="button" id="pf-mcp-oauth-test"><?php echo esc_html__('Test configuration', 'pageforge'); ?></button>
                    <a class="button" id="pf-mcp-oauth-docs" href="#" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Setup guide', 'pageforge'); ?></a>
                    <button type="button" class="button button-primary pf-mcp-dialog-close"><?php echo esc_html__('Done', 'pageforge'); ?></button>
                </div>
            </div>
        </div>

        <div id="pf-mcp-token-dialog" class="pf-mcp-dialog" hidden>
            <div class="pf-mcp-dialog-box" role="dialog" aria-modal="true" aria-labelledby="pf-mcp-dialog-title" tabindex="-1">
                <h3 id="pf-mcp-dialog-title"><?php echo esc_html__('Personal access token created', 'pageforge'); ?></h3>
                <p class="pf-mcp-warn"><?php echo esc_html__('Copy this token now. PageForge stores only a hash of it and cannot show it again.', 'pageforge'); ?></p>

                <div class="pf-mcp-token-row">
                    <label class="screen-reader-text" for="pf-mcp-token-value"><?php echo esc_html__('Personal access token', 'pageforge'); ?></label>
                    <input type="text" id="pf-mcp-token-value" class="pf-mcp-token-input code" readonly />
                    <button type="button" class="button button-primary" id="pf-mcp-copy-token"><?php echo esc_html__('Copy token', 'pageforge'); ?></button>
                </div>

                <table class="pf-mcp-meta">
                    <tr><th scope="row"><?php echo esc_html__('Label', 'pageforge'); ?></th><td id="pf-mcp-token-label"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Scopes', 'pageforge'); ?></th><td id="pf-mcp-token-scopes"></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Expires', 'pageforge'); ?></th><td id="pf-mcp-token-expiry"><?php echo esc_html__('Never (revoke it manually when finished)', 'pageforge'); ?></td></tr>
                </table>

                <h4 class="pf-mcp-subhead"><?php echo esc_html__('Client configuration', 'pageforge'); ?></h4>
                <div class="pf-mcp-config-tabs" id="pf-mcp-config-tabs" role="tablist"></div>
                <pre id="pf-mcp-config-snippet" class="pf-mcp-config-snippet" tabindex="0"></pre>

                <div class="pf-mcp-dialog-actions">
                    <button type="button" class="button" id="pf-mcp-copy-config"><?php echo esc_html__('Copy configuration', 'pageforge'); ?></button>
                    <button type="button" class="button button-primary pf-mcp-dialog-close" id="pf-mcp-dialog-close"><?php echo esc_html__('Done', 'pageforge'); ?></button>
                </div>
            </div>
        </div>

        <div id="pf-mcp-client-dialog" class="pf-mcp-dialog" hidden>
            <div class="pf-mcp-dialog-box" role="dialog" aria-modal="true" aria-labelledby="pf-mcp-client-title" tabindex="-1">
                <h3 id="pf-mcp-client-title"><?php echo esc_html__('OAuth client created', 'pageforge'); ?></h3>
                <p class="pf-mcp-warn"><?php echo esc_html__('The client secret is shown once. PageForge stores only a hash of it.', 'pageforge'); ?></p>
                <p class="description"><?php echo esc_html__('Paste these into the connector that asked for them. They are not an API key.', 'pageforge'); ?></p>

                <div class="pf-mcp-token-row">
                    <label class="screen-reader-text" for="pf-mcp-client-id"><?php echo esc_html__('Client ID', 'pageforge'); ?></label>
                    <input type="text" id="pf-mcp-client-id" class="pf-mcp-token-input code" readonly />
                    <button type="button" class="button" data-pf-copy-input="pf-mcp-client-id"><?php echo esc_html__('Copy ID', 'pageforge'); ?></button>
                </div>
                <div class="pf-mcp-token-row">
                    <label class="screen-reader-text" for="pf-mcp-client-secret"><?php echo esc_html__('Client secret', 'pageforge'); ?></label>
                    <input type="text" id="pf-mcp-client-secret" class="pf-mcp-token-input code" readonly />
                    <button type="button" class="button button-primary" data-pf-copy-input="pf-mcp-client-secret"><?php echo esc_html__('Copy secret', 'pageforge'); ?></button>
                </div>

                <table class="pf-mcp-meta">
                    <tr><th scope="row"><?php echo esc_html__('Redirect URI', 'pageforge'); ?></th><td><code id="pf-mcp-client-redirect"></code></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Authorization endpoint', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::authorize_url()); ?></code></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Token endpoint', 'pageforge'); ?></th><td><code><?php echo esc_html(Oauth::token_url()); ?></code></td></tr>
                </table>

                <div class="pf-mcp-dialog-actions">
                    <button type="button" class="button button-primary pf-mcp-dialog-close"><?php echo esc_html__('Done', 'pageforge'); ?></button>
                </div>
            </div>
        </div>

        <div class="screen-reader-text" role="status" aria-live="polite" id="pf-mcp-live"></div>
        <?php
    }

	    // ---------- Checkout Embed (Freemius) ----------
    private static function count_generated_posts(): int {
        $generated_meta_query = ['relation' => 'OR'];
        foreach (Compatibility::meta_keys('generated') as $key) {
            $generated_meta_query[] = [
                'key'     => $key,
                'compare' => 'EXISTS',
            ];
        }
        $generated_meta_query[] = [
            'key'     => '_pageforge_vars',
            'compare' => 'EXISTS',
        ];
        $generated_meta_query[] = [
            'key'     => '_pageforge_city',
            'compare' => 'EXISTS',
        ];

        $q = new \WP_Query([
                'post_type'      => array_values(array_unique(array_merge(
                    Compatibility::generated_post_types(),
                    ['page', 'post']
                ))),
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => $generated_meta_query,
            ]);

            return (int) $q->found_posts;
        }

        private static function count_published_posts(array $post_types): int {
            $count = 0;
            foreach ($post_types as $post_type) {
                $counts = \wp_count_posts($post_type);
                if ($counts && isset($counts->publish)) {
                    $count += (int) $counts->publish;
                    continue;
                }

                global $wpdb;
                if (!isset($wpdb->posts) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
                    continue;
                }

                $query = $wpdb->prepare(
                    "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                    $post_type,
                    'publish'
                );
                $count += (int) $wpdb->get_var($query);
            }
            return $count;
        }

	    private static function checkout_snippet() {
	        // WordPress.org build: no checkout embed.
	        return;
	    }
}

// Hooks for template meta
\add_action('add_meta_boxes', [Admin::class, 'register_template_metabox']);
foreach (Compatibility::template_post_types() as $pageforge_template_post_type) {
    \add_action('save_post_' . $pageforge_template_post_type, [Admin::class, 'save_template_meta']);
}
unset($pageforge_template_post_type);
\add_action('add_meta_boxes', [Admin::class, 'register_seo_metabox']);
\add_action('save_post', [Admin::class, 'save_seo_meta']);

