<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_beaverbuilder extends wps_ic_integrations {

    public function is_active() {
        return defined('FL_BUILDER_VERSION');
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    public function add_admin_hooks() {
        return [
            // fl_builder_cache_cleared is owned by wpc_beaver_cache_cleared() (warm.php): the
            // builder deleted the files every stored page links, so the purge is hard. A second
            // listener here purged soft and kept the copies serving.
            'fl_builder_before_save_layout' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($post_id = false) {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();
    }

}
