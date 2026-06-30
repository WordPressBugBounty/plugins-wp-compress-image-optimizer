<?php
/**
 * PR 1 Foundation — PHPUnit test stubs
 *
 * These tests document the expected behavior of the v7.02 Foundation layer
 * for the day PHPUnit + WP test infrastructure is added to this codebase.
 * They are NOT runnable today (no `composer.json`, no `phpunit.xml`,
 * no test bootstrap exists in the repo as of v7.01.15).
 *
 * Until then, PR1_VERIFICATION.md is the executable spec — manual checks
 * via wp-cli on staging.
 *
 * To activate: add wp-env or a manual `tests/bootstrap.php` that loads
 * WordPress + the plugin file, then run `phpunit tests/v702/`.
 *
 * @package WPCompress\Tests\V702
 */

if (!class_exists('PHPUnit\Framework\TestCase')) {
    return; // gracefully no-op if PHPUnit isn't installed
}

class PR1FoundationTest extends \PHPUnit\Framework\TestCase
{
    public function tearDown(): void
    {
        delete_option('wpc_modern_delivery_v2');
        parent::tearDown();
    }

    /**
     * v2_mode() defaults to 'off' when option is unset.
     */
    public function test_v2_mode_defaults_off()
    {
        delete_option('wpc_modern_delivery_v2');
        $this->assertSame('off', \WPC_Modern_Delivery::v2_mode());
    }

    /**
     * v2_mode() returns the stored value when set to a valid state.
     */
    public function test_v2_mode_round_trip_valid_states()
    {
        foreach (['off', 'shadow', 'on'] as $valid) {
            update_option('wpc_modern_delivery_v2', $valid);
            $this->assertSame($valid, \WPC_Modern_Delivery::v2_mode(), "round-trip failed for: {$valid}");
        }
    }

    /**
     * v2_mode() defensively normalizes corrupt values to 'off'.
     */
    public function test_v2_mode_defensive_normalization()
    {
        foreach (['garbage', '', 'TRUE', 'on ', '1', null, 0, false] as $corrupt) {
            update_option('wpc_modern_delivery_v2', $corrupt);
            $this->assertSame('off', \WPC_Modern_Delivery::v2_mode(),
                'corrupt value should normalize to off: ' . var_export($corrupt, true));
        }
    }

    /**
     * v2_enabled() returns true ONLY when mode is 'on'.
     */
    public function test_v2_enabled_only_when_on()
    {
        update_option('wpc_modern_delivery_v2', 'off');
        $this->assertFalse(\WPC_Modern_Delivery::v2_enabled());

        update_option('wpc_modern_delivery_v2', 'shadow');
        $this->assertFalse(\WPC_Modern_Delivery::v2_enabled(), 'shadow should NOT enable v2 paths');

        update_option('wpc_modern_delivery_v2', 'on');
        $this->assertTrue(\WPC_Modern_Delivery::v2_enabled());
    }

    /**
     * is_cdn_mode_enabled() returns true when ic_custom_cname is set.
     */
    public function test_is_cdn_mode_enabled_via_custom_cname()
    {
        update_option('ic_custom_cname', 'my.cdn.example.com');
        update_option('ic_cdn_zone_name', '');
        $this->assertTrue(\WPC_Modern_Delivery::is_cdn_mode_enabled());
        delete_option('ic_custom_cname');
    }

    /**
     * is_cdn_mode_enabled() returns true when ic_cdn_zone_name is set.
     */
    public function test_is_cdn_mode_enabled_via_zone_name()
    {
        delete_option('ic_custom_cname');
        update_option('ic_cdn_zone_name', 'wpc-zone-123.b-cdn.net');
        $this->assertTrue(\WPC_Modern_Delivery::is_cdn_mode_enabled());
    }

    /**
     * is_cdn_mode_enabled() returns false when both options are empty.
     */
    public function test_is_cdn_mode_enabled_false_when_unconfigured()
    {
        delete_option('ic_custom_cname');
        delete_option('ic_cdn_zone_name');
        $this->assertFalse(\WPC_Modern_Delivery::is_cdn_mode_enabled());
    }

    /**
     * is_cdn_mode_enabled() trims whitespace-only values to false.
     */
    public function test_is_cdn_mode_enabled_treats_whitespace_as_unset()
    {
        update_option('ic_custom_cname', '   ');
        update_option('ic_cdn_zone_name', "\t\n");
        $this->assertFalse(\WPC_Modern_Delivery::is_cdn_mode_enabled());
        delete_option('ic_custom_cname');
        delete_option('ic_cdn_zone_name');
    }

    /**
     * maybe_create_emissions_table creates the table on first call.
     */
    public function test_maybe_create_emissions_table_creates_on_first_call()
    {
        global $wpdb;
        delete_option('wpc_emissions_table_version');
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpcompress_emissions");

        $created = \WPC_Modern_Delivery::maybe_create_emissions_table();
        $this->assertTrue($created);

        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wpcompress_emissions'");
        $this->assertNotNull($exists);
    }

    /**
     * maybe_create_emissions_table is a no-op on subsequent calls (idempotency).
     */
    public function test_maybe_create_emissions_table_idempotent()
    {
        \WPC_Modern_Delivery::maybe_create_emissions_table();
        $second_call = \WPC_Modern_Delivery::maybe_create_emissions_table();
        $this->assertFalse($second_call, 'second call should be a no-op when version matches');
    }

    /**
     * maybe_create_emissions_table re-runs when version option is cleared.
     */
    public function test_maybe_create_emissions_table_reruns_on_version_clear()
    {
        \WPC_Modern_Delivery::maybe_create_emissions_table();
        delete_option('wpc_emissions_table_version');
        $rerun = \WPC_Modern_Delivery::maybe_create_emissions_table();
        $this->assertTrue($rerun);
    }

    /**
     * Emissions table has the indexes the algorithm relies on.
     */
    public function test_emissions_table_has_required_indexes()
    {
        global $wpdb;
        \WPC_Modern_Delivery::maybe_create_emissions_table();

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}wpcompress_emissions", ARRAY_A);
        $index_names = array_unique(array_column($indexes, 'Key_name'));

        $this->assertContains('PRIMARY', $index_names);
        $this->assertContains('emission_tuple', $index_names);
        $this->assertContains('attachment_lookup', $index_names);
        $this->assertContains('ladder_analysis', $index_names);
    }

    /**
     * Emissions table UNIQUE KEY allows the upsert pattern Phase 1 needs.
     */
    public function test_emissions_table_unique_key_supports_upsert()
    {
        global $wpdb;
        \WPC_Modern_Delivery::maybe_create_emissions_table();

        $now = current_time('mysql');
        $tuple = [
            'attachment_id' => 122,
            'width' => 480,
            'format' => 'avif',
            'page_url_hash' => substr(md5('https://test.com/'), 0, 8),
            'emit_count' => 1,
            'first_seen' => $now,
            'last_seen' => $now,
        ];

        $insert1 = $wpdb->insert("{$wpdb->prefix}wpcompress_emissions", $tuple);
        $this->assertSame(1, $insert1);

        // Same tuple again should fail with duplicate key (ready for ON DUPLICATE KEY UPDATE)
        $insert2 = $wpdb->insert("{$wpdb->prefix}wpcompress_emissions", $tuple);
        $this->assertFalse($insert2);

        // Cleanup
        $wpdb->delete("{$wpdb->prefix}wpcompress_emissions", ['attachment_id' => 122]);
    }
}
