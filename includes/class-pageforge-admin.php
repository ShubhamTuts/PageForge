<?php
namespace PageForge;

if (!defined('ABSPATH')) exit;

class Admin {
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
			'settings'    => [__('Settings', 'pageforge'), 'pageforge-settings'],
		];

		// WordPress.org build: no upsells or premium plan state.
		?>
        <div class="pf-topbar">
            <div class="pf-topbar-left">
                <div class="pf-brand">
                    <span class="pf-brand-mark" aria-hidden="true">PF</span>
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
        $tpl_count = (int) \wp_count_posts('pageforge_template')->publish;
        $pg_count  = (int) \wp_count_posts('pageforge_page')->publish;
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
		$list = \get_posts(['post_type' => 'pageforge_template', 'numberposts' => -1, 'post_status' => 'any']); ?>
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
		$tpls = \get_posts(['post_type' => 'pageforge_template', 'numberposts' => -1, 'post_status' => 'any']);
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
                                        <select id="pf-csv" class="regular-text pf-input-wide"><option value=""><?php echo esc_html__('Loading…', 'pageforge'); ?></option></select>
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
        $template_count = (int) count(\get_posts([
            'post_type'      => 'pageforge_template',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]));
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
                                        <td><input type="password" name="openai_key" value="<?php echo \esc_attr(array_get($s,'openai_key')); ?>" class="regular-text" /></td>
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
                                        <td><input type="password" name="anthropic_key" value="<?php echo \esc_attr(array_get($s,'anthropic_key')); ?>" class="regular-text" /></td>
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
                                        <td><input type="password" name="gemini_key" value="<?php echo \esc_attr(array_get($s,'gemini_key')); ?>" class="regular-text" /></td>
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
                                        <td><input type="password" name="deepseek_key" value="<?php echo \esc_attr(array_get($s,'deepseek_key')); ?>" class="regular-text" /></td>
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
                                        <td><input type="password" name="pexels_key" value="<?php echo \esc_attr(array_get($s,'pexels_key')); ?>" class="regular-text" /></td>
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
                                        <td><input type="text" name="cpt_base" value="<?php echo esc_attr(array_get($s,'cpt_base','pageforge')); ?>" class="regular-text" /> <span class="description"><?php echo esc_html__('Applies to pageforge_page URLs. Save to flush permalinks.', 'pageforge'); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('GA4 Measurement ID', 'pageforge'); ?></th>
                                        <td><input type="text" name="ga4_measurement_id" value="<?php echo esc_attr(array_get($s,'ga4_measurement_id')); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" /></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('GA4 API Secret', 'pageforge'); ?></th>
                                        <td><input type="password" name="ga4_api_secret" value="<?php echo esc_attr(array_get($s,'ga4_api_secret')); ?>" class="regular-text" /></td>
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
                            <p class="pf-note pf-mt-sm"><?php echo esc_html__('Direct license key entry from this screen can be added later once the Freemius API integration is finalized.', 'pageforge'); ?></p>
                        </div>

                        <div class="pf-card pf-mt-md">
                            <h3><?php echo esc_html__('Need Enterprise?', 'pageforge'); ?></h3>
                            <p><?php echo esc_html__('For higher volumes, custom workflows, or security reviews, talk to us about an Enterprise plan.', 'pageforge'); ?></p>
                            <a class="button button-primary" href="mailto:support@pageforge.app?subject=PageForge%20Enterprise">
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
	                        <p class="pf-page-subtitle"><?php echo esc_html__('Generate SEO meta and save to PageForge + Yoast + RankMath meta fields.', 'pageforge'); ?></p>
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
	                        <p class="pf-page-subtitle"><?php echo esc_html__('Generate a full HTML blog draft and optionally attach a featured image from Pexels.', 'pageforge'); ?></p>
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
	                                    <div class="pf-sub"><?php echo esc_html__('Provide a topic and (optionally) target keywords.', 'pageforge'); ?></div>
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
	                            <div class="pf-field-row">
	                                <div class="pf-field">
	                                    <label><?php echo esc_html__('Post Type', 'pageforge'); ?></label>
	                                    <select id="pf-blog-post-type">
	                                        <option value="post"><?php echo esc_html__('Post', 'pageforge'); ?></option>
	                                        <option value="page"><?php echo esc_html__('Page', 'pageforge'); ?></option>
	                                        <option value="pageforge_page"><?php echo esc_html__('PageForge Page', 'pageforge'); ?></option>
	                                    </select>
	                                </div>
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
	                            </div>
	                            <div class="pf-actions">
	                                <button class="button button-primary" id="pf-ai-blog-generate" <?php disabled(!$ai_context['has_key']); ?>><?php echo esc_html__('Generate Draft', 'pageforge'); ?></button>
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
         \add_meta_box('pageforge_template_meta', __('PageForge Template Settings', 'pageforge'), [__CLASS__, 'metabox_template'], 'pageforge_template', 'normal', 'high');
     }

    public static function metabox_template($post) {
        $title_pattern = get_post_meta($post->ID, '_pageforge_title_pattern', true);
        $slug_pattern  = get_post_meta($post->ID, '_pageforge_slug_pattern', true);
        $post_type     = get_post_meta($post->ID, '_pageforge_post_type', true) ?: 'pageforge_page';
        $post_status   = get_post_meta($post->ID, '_pageforge_post_status', true) ?: '';
        $base_post_id  = (int) get_post_meta($post->ID, '_pageforge_base_post_id', true);
        $base_post_types = array_values(array_filter([
            'page',
            'post',
            'pageforge_page',
            'elementor_library',
            'et_pb_layout',
            'wp_block',
            'wp_template',
            'wp_template_part',
        ], 'post_type_exists'));
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
                <th scope="row"><?php echo esc_html__('Slug Pattern', 'pageforge'); ?></th>
                <td><input type="text" class="regular-text" name="pageforge_slug_pattern" value="<?php echo esc_attr($slug_pattern); ?>" placeholder="{service}-in-{city}-{state}"/></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Target Post Type', 'pageforge'); ?></th>
                <td>
                    <select name="pageforge_post_type">
                        <option value="pageforge_page" <?php selected($post_type,'pageforge_page'); ?>><?php echo esc_html__('PageForge Page (pageforge_page)', 'pageforge'); ?></option>
                        <option value="page" <?php selected($post_type,'page'); ?>><?php echo esc_html__('Page', 'pageforge'); ?></option>
                        <option value="post" <?php selected($post_type,'post'); ?>><?php echo esc_html__('Post', 'pageforge'); ?></option>
                    </select>
                </td>
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
                <th scope="row"><?php echo esc_html__('Base Layout from Existing Page or Template', 'pageforge'); ?></th>
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
                        <?php esc_html_e('Pick a page, post, Elementor template, Divi layout, or reusable block to clone its layout, or enter an ID manually.', 'pageforge'); ?>
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
         \update_post_meta($post_id, '_pageforge_title_pattern', \sanitize_text_field(isset($_POST['pageforge_title_pattern']) ? wp_unslash($_POST['pageforge_title_pattern']) : ''));
         \update_post_meta($post_id, '_pageforge_slug_pattern', \sanitize_text_field(isset($_POST['pageforge_slug_pattern']) ? wp_unslash($_POST['pageforge_slug_pattern']) : ''));
         \update_post_meta($post_id, '_pageforge_post_type', \sanitize_text_field(isset($_POST['pageforge_post_type']) ? wp_unslash($_POST['pageforge_post_type']) : 'pageforge_page'));
         \update_post_meta($post_id, '_pageforge_post_status', $post_status);
         \update_post_meta($post_id, '_pageforge_base_post_id', isset($_POST['pageforge_base_post_id']) ? absint(wp_unslash($_POST['pageforge_base_post_id'])) : 0);
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

         \update_post_meta($post_id, '_pageforge_meta_title', $meta_title);
         \update_post_meta($post_id, '_pageforge_meta_description', $meta_desc);
         \update_post_meta($post_id, '_pageforge_focus_keyword', $focus_kw);

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

	    // ---------- Checkout Embed (Freemius) ----------
        private static function count_generated_posts(): int {
            $q = new \WP_Query([
                'post_type'      => ['pageforge_page', 'page', 'post'],
                'post_status'    => ['publish', 'draft', 'pending', 'private'],
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'OR',
                    [
                        'key'     => '_pageforge_vars',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => '_pageforge_city',
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            return (int) $q->found_posts;
        }

	    private static function checkout_snippet() {
	        // WordPress.org build: no checkout embed.
	        return;
	    }
}

// Hooks for template meta
\add_action('add_meta_boxes', [Admin::class, 'register_template_metabox']);
\add_action('save_post_pageforge_template', [Admin::class, 'save_template_meta']);
\add_action('add_meta_boxes', [Admin::class, 'register_seo_metabox']);
\add_action('save_post', [Admin::class, 'save_seo_meta']);

