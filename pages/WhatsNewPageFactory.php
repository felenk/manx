<?php

namespace Manx;

require_once 'vendor/autoload.php';

// For TIME_ZONE
require_once 'IDateTimeProvider.php';

class WhatsNewPageFactory implements IWhatsNewPageFactory
{
    function createUrlInfo($url)
    {
        return new UrlInfo($url);
    }

    function createUrlTransfer($url)
    {
        return new UrlTransfer($url);
    }

    function getCurrentTime()
    {
        date_default_timezone_set(TIME_ZONE);
        return time();
    }
}
