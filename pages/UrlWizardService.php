<?php

/*

1. Enter URL and submit to wizard for analysis.
2. Wizard does the following:
   a. Look at prefix of URL and try to associate a mirror or site with
      the URL.
   b. Look at directory components of the URL and try to match them
      against short names for companies to identify the company.
   c. Look at the prefix of the last component of the URL and attempt to
      extract a proposed part number
   d. Fetch document and compute size and MD5 (requires PECL http)
   e. Extract a proposed title from the last component of the URL
   f. Identify a possible new site if URL doesn't match any known site
      or mirror.
3. Show all guesses at information for editing before submitting a
   real change.

*/

require_once 'CurlApi.php';
require_once 'Manx.php';
require_once 'Searcher.php';
require_once 'ServicePageBase.php';
require_once 'Site.php';
require_once 'UrlInfo.php';
require_once 'UrlInfoFactory.php';
require_once 'UrlMetaData.php';

use Pimple\Container;

class UrlWizardService extends ServicePageBase
{
    /** @var array */
    private $_sites;
    /** @var IUrlInfoFactory */
    private $_urlInfoFactory;

    public function __construct(Container $config)
    {
        parent::__construct($config);
        $this->_urlInfoFactory = $config['urlInfoFactory'];
    }

    private function determineData()
    {
        $url = $this->param('url');
        $urlInfo = $this->_urlInfoFactory->createUrlInfo($url);
        $size = $urlInfo->size();
        if ($size === false)
        {
            $data['valid'] = false;
            return $data;
        }

        $data['url'] = $url;
        $data['mirror_url'] = '';
        $data['size'] = $size;
        $data['valid'] = true;
        $this->_sites = $this->_db->getSites();
        $data['site'] = $this->determineSiteFromUrl($data);
        $data['company'] = -1;
        $data['part'] = '';
        $data['pub_date'] = '';
        $data['title'] = '';
        $data['format'] = '';
        if ($this->siteIsBitSavers($data))
        {
            $this->determineBitSaversData($data);
        }
        else if ($this->siteIsChiClassicComp($data))
        {
            $this->determineChiClassicCompData($data);
        }
        else
        {
            $this->determineUrlData($data);
        }
        $this->determineUrlExists($data);
        return $data;
    }

    private function determineUrlExists(&$data)
    {
        $row = $this->_db->copyExistsForUrl($data['url']);
        if ($row)
        {
            $data['exists'] = true;
            $data['company'] = $row['ph_company'];
            $data['pub_id'] = $row['ph_pub'];
            $data['title'] = $row['ph_title'];
        }
    }

    private function siteIsBitSavers($data)
    {
        return $this->siteMatchesId($data, Site::BitSavers);
    }

    private function siteIsChiClassicComp($data)
    {
        return $this->siteMatchesId($data, Site::ChiClassicComp);
    }

    private function siteMatchesId($data, $siteId)
    {
        return array_key_exists('site_id', $data['site'])
            && $data['site']['site_id'] == $siteId;
    }

    private function determineUrlData(&$data)
    {
        $url = $data['url'];
        $matches = array();
        if (substr($url, strlen($url) - 4, 4) == '.tgz')
        {
            $url = substr($url, 0, strlen($url) - 4);
        }
        if (1 != preg_match('|^.*/([^/]+)\.([^./]+)$|', $url, $matches))
        {
            return;
        }
        list($data['pub_date'], $fileBase) = UrlMetaData::extractPubDate($matches[1]);
        $data['title'] = UrlMetaData::titleForFileBase($fileBase);
        $data['format'] = $this->_db->getFormatForExtension($matches[2]);
    }

    private function findSiteById($id)
    {
        if ($id != -1)
        {
            foreach ($this->_sites as $site)
            {
                if ($site['site_id'] == $id)
                {
                    return $site;
                }
            }
        }
        return array();
    }

    private function determineSiteFromMirrorUrl(&$data)
    {
        $matchingPrefix = '';
        $matchingSite = -1;
        $originalPrefix = '';
        foreach ($this->_db->getMirrors() as $mirror)
        {
            $mirrorBase = $mirror['copy_stem'];
            if (substr($data['url'], 0, strlen($mirrorBase)) == $mirrorBase)
            {
                if (strlen($mirrorBase) > strlen($matchingPrefix))
                {
                    $matchingPrefix = $mirrorBase;
                    $matchingSite = $mirror['site'];
                    $originalPrefix = $mirror['original_stem'];
                }
            }
        }
        if ($matchingSite != -1)
        {
            $data['mirror_url'] = $data['url'];
            $data['url'] = $originalPrefix . substr($data['url'], strlen($matchingPrefix));
        }
        return $this->findSiteById($matchingSite);
    }

    private function determineSiteFromUrl(&$data)
    {
        $url = $data['url'];
        $matchingPrefix = '';
        $matchingSite = array();
        $components = parse_url($url);
        foreach ($this->_sites as $site)
        {
            $siteBase = $site['copy_base'];
            if (strlen($siteBase) == 0)
            {
                $siteBase = $site['url'];
            }
            $siteComponents = parse_url($siteBase);
            if (UrlMetaData::urlComponentsMatch($components, $siteComponents))
            {
                if (strlen($siteBase) > strlen($matchingPrefix))
                {
                    $matchingPrefix = $siteBase;
                    $matchingSite = $site;
                }
            }
        }
        if (count($matchingSite) == 0)
        {
            return $this->determineSiteFromMirrorUrl($data);
        }
        $siteComponents = parse_url($matchingSite['url']);
        $components['host'] = $siteComponents['host'];
        $data['url'] = self::buildUrl($components);
        return $matchingSite;
    }

    private static function buildUrl($components)
    {
        return sprintf("%s://%s%s", $components['scheme'], $components['host'], $components['path']);
    }

    private function determineSiteData($siteName, $companyComponent, &$data)
    {
        $url = $data['url'];
        $urlComponents = parse_url($url);
        $dirs = explode('/', $urlComponents['path']);
        $companyDir = $dirs[$companyComponent];

        $company = $this->_db->getCompanyForSiteDirectory($siteName, $companyDir);
        $data['company'] = $company;
        $data['site_company_directory'] = $companyDir;

        $fileName = array_pop($dirs);
        $dotPos = strrpos($fileName, '.');
        if ($dotPos === false)
        {
            $fileBase = $fileName;
            $extension = '';
        }
        else
        {
            $fileParts = explode('.', $fileName);
            $extension = array_pop($fileParts);
            $fileBase = implode('.', $fileParts);
        }
        list($data['pub_date'], $fileBase) = UrlMetaData::extractPubDate($fileBase);
        $parts = explode('_', $fileBase);
        if (count($parts) > 1)
        {
            if (1 == preg_match('/[0-9][0-9]+/', $parts[0]))
            {
                $data['part'] = array_shift($parts);
            }
            $data['pubs'] = $this->_db->getPublicationsForPartNumber($data['part'], $data['company']);
            $data['title'] = UrlMetaData::titleForFileBase(implode(' ', $parts));
        }
        else
        {
            $data['pubs'] = $this->findPublicationsForKeywords($company, array($fileBase));
            $data['title'] = UrlMetaData::titleForFileBase($fileBase);
        }
        $data['format'] = $this->_db->getFormatForExtension($extension);
    }

    private function determineBitSaversData(&$data)
    {
        $this->determineSiteData('bitsavers', 2, $data);
    }

    private function determineChiClassicCompData(&$data)
    {
        $this->determineSiteData('ChiClassicComp', 4, $data);
    }

    private static function emptyStringIfNull($str)
    {
        return is_null($str) ? '' : $str;
    }

    private function accumulatePublicationsForKeywords($company, $keywords, $online)
    {
        $data = array();
        foreach ($this->_db->searchForPublications($company, $keywords, $online) as $row)
        {
            $pubId = $row['pub_id'];
            if (!array_key_exists($pubId, $data))
            {
                $data[$pubId] = array('pub_id' => $row['pub_id'],
                    'ph_part' => self::emptyStringIfNull($row['ph_part']),
                    'ph_revision' => self::emptyStringIfNull($row['ph_revision']),
                    'ph_title' => $row['ph_title']);
            }
        }
        return $data;
    }

    private function findPublicationsForKeywords($company, $keywords)
    {
        return $this->mergePubs(
            $this->accumulatePublicationsForKeywords($company, $keywords, false),
            $this->accumulatePublicationsForKeywords($company, $keywords, true));
    }

    private function mergePubs($left, $right)
    {
        foreach (array_keys($right) as $pubId)
        {
            if (!array_key_exists($pubId, $left))
            {
                $left[$pubId] = $right[$pubId];
            }
        }
        return $left;
    }

    private function findPublications()
    {
        $company = $this->param('company');
        $ignoredWords = array();
        $keywords = Searcher::filterSearchKeywords($this->param('keywords'), $ignoredWords);
        if (count($keywords))
        {
            $data = $this->findPublicationsForKeywords($company, $keywords);
            $filtered = Searcher::filterSearchKeywords($keywords[0], $ignoredWords);
            if (count($filtered))
            {
                $data = $this->mergePubs($data, $this->findPublicationsForKeywords($company, $filtered));
            }
            $data = array_values($data);
            usort($data, array('UrlWizardService', 'comparePublications'));
        }
        else
        {
            $data = array();
        }
        return $data;
    }

    protected function renderJsonResponse($data)
    {
        $this->header("Content-Type: application/json; charset=utf-8");
        print json_encode($data);
    }

    protected function header($field)
    {
        header($field);
    }

    protected function methodDispatch()
    {
        $method = $this->param('method');
        if ($method == 'url-lookup')
        {
            $this->renderJsonResponse($this->determineData());
            return true;
        }
        else if ($method == 'pub-search')
        {
            $this->renderJsonResponse($this->findPublications());
            return true;
        }

        return false;
    }
}
