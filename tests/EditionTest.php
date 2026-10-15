<?php

use PageForge\Edition;
use PageForge\Tests\TestCase;

/**
 * Only one PageForge runtime may execute. Pro outranks Free, and no decision
 * may ever remove content.
 */
function pageforge_edition_describe(array $map): callable {
    return static function (string $plugin) use ($map): ?array {
        return $map[$plugin] ?? null;
    };
}

function test_pageforge_edition_ignores_unrelated_and_self_plugins(): void {
    $conflicts = Edition::conflicts(
        ['pageforge/pageforge.php', 'akismet/akismet.php', 'woocommerce/woocommerce.php'],
        'pageforge/pageforge.php',
        pageforge_edition_describe([])
    );

    TestCase::same([], $conflicts);
}

function test_pageforge_edition_detects_premium_sibling(): void {
    $conflicts = Edition::conflicts(
        ['pageforge/pageforge.php', 'pageforge-premium/pageforge.php'],
        'pageforge/pageforge.php',
        pageforge_edition_describe([
            'pageforge-premium/pageforge.php' => ['name' => 'PageForge (Premium)', 'edition' => Edition::PRO],
        ])
    );

    TestCase::same(['pageforge-premium/pageforge.php' => Edition::PRO], $conflicts);
}

function test_pageforge_edition_skips_candidates_that_are_not_pageforge(): void {
    // A plugin folder can start with "pageforge" without being PageForge.
    $conflicts = Edition::conflicts(
        ['pageforge/pageforge.php', 'pageforge-addons-by-someone/plugin.php'],
        'pageforge/pageforge.php',
        pageforge_edition_describe([])
    );

    TestCase::same([], $conflicts);
}

function test_pageforge_edition_path_heuristic_covers_renamed_folders(): void {
    TestCase::true(Edition::looks_like_pageforge('pageforge-premium/pageforge.php'));
    TestCase::true(Edition::looks_like_pageforge('some-folder/pageforge.php'));
    TestCase::true(Edition::looks_like_pageforge('pageforge-pro/main.php'));
    TestCase::same(false, Edition::looks_like_pageforge('akismet/akismet.php'));
    TestCase::same(false, Edition::looks_like_pageforge(''));
}

function test_pageforge_free_activation_is_blocked_while_pro_runs(): void {
    $decision = Edition::activation_decision(Edition::FREE, [
        'pageforge-premium/pageforge.php' => Edition::PRO,
    ]);

    TestCase::same('block', $decision['action']);
    TestCase::same(['pageforge-premium/pageforge.php'], $decision['plugins']);
}

function test_pageforge_pro_activation_retires_free(): void {
    $decision = Edition::activation_decision(Edition::PRO, [
        'pageforge/pageforge.php' => Edition::FREE,
    ]);

    TestCase::same('deactivate_others', $decision['action']);
    TestCase::same(['pageforge/pageforge.php'], $decision['plugins']);
}

function test_pageforge_activation_proceeds_without_conflicts(): void {
    TestCase::same('proceed', Edition::activation_decision(Edition::FREE, [])['action']);
    TestCase::same('proceed', Edition::activation_decision(Edition::PRO, [])['action']);
}

function test_pageforge_duplicate_free_copy_is_retired_by_either_edition(): void {
    $conflicts = ['pageforge-copy/pageforge.php' => Edition::FREE];

    TestCase::same('deactivate_others', Edition::activation_decision(Edition::FREE, $conflicts)['action']);
    TestCase::same('deactivate_others', Edition::runtime_decision(Edition::FREE, $conflicts)['action']);
}

function test_pageforge_free_runtime_steps_aside_for_pro(): void {
    $decision = Edition::runtime_decision(Edition::FREE, [
        'pageforge-premium/pageforge.php' => Edition::PRO,
    ]);

    TestCase::same('deactivate_self', $decision['action']);
}

function test_pageforge_pro_runtime_never_steps_aside(): void {
    $decision = Edition::runtime_decision(Edition::PRO, [
        'pageforge/pageforge.php' => Edition::FREE,
    ]);

    TestCase::same('deactivate_others', $decision['action']);
    TestCase::same('proceed', Edition::runtime_decision(Edition::PRO, [])['action']);
}
