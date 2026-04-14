<?php

trait wps_ic_agency_trait
{

    public function isAgencyPortal()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            self::$api_key = $this->extractApiKey();
            return true;
        }

        return false;
    }

    public function extractApiKey()
    {
        $uri = $_SERVER['REQUEST_URI'];
        if (preg_match('#^/view-site/([a-f0-9]+)/?$#', $uri, $matches)) {
            $key = $matches[1];
            return $key;
        }

        return false;
    }

}
