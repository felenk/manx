<?php

require_once 'cron/BitSaversCleaner.php';
require_once 'pages/IFile.php';
require_once 'pages/IManx.php';
require_once 'pages/IManxDatabase.php';
require_once 'pages/IUser.php';
require_once 'pages/IWhatsNewIndex.php';

use Pimple\Container;

class TestBitSaversCleaner extends PHPUnit\Framework\TestCase
{
    /** @var Container */
    private $_config;

    /** @var IManxDatabase */
    private $_db;
    /** @var IManx */
    private $_manx;
    /** @var IUrlInfo */
    private $_urlInfo;
    /** @var IWhatsNewPageFactory */
    private $_factory;
    /** @var ILogger */
    private $_logger;
    /** @var BitSaversCleaner */
    private $_cleaner;
    /** @var IWhatsNewIndex */
    private $_whatsNewIndex;

    protected function setUp()
    {
        $this->_urlInfo = $this->createMock(IUrlInfo::class);
        $this->_factory = $this->createMock(IWhatsNewPageFactory::class);
        $this->_logger = $this->createMock(ILogger::class);

        $this->_db = $this->createMock(IManxDatabase::class);
        $this->_manx = $this->createMock(IManx::class);
        $this->_manx->expects($this->atLeast(1))->method('getDatabase')->willReturn($this->_db);
        $this->_whatsNewIndex = $this->createMock(IWhatsNewIndex::class);
        $this->_urlMetaData = $this->createMock(IUrlMetaData::class);
        $config = new Container();
        $config['manx'] = $this->_manx;
        $config['logger'] = $this->_logger;
        $config['whatsNewPageFactory'] = $this->_factory;
        $config['whatsNewIndex'] = $this->_whatsNewIndex;
        $config['fileSystem'] = $this->createMock(IFileSystem::class);
        $config['urlMetaData'] = $this->_urlMetaData;
        $this->_config = $config;
        $this->_cleaner = new BitSaversCleaner($this->_config);
    }

    public function testNonExistentPathsAreRemoved()
    {
        $this->_db->expects($this->once())->method('getAllSiteUnknownPaths')
            ->with('bitsavers')
            ->willReturn( array( array('id' => 1, 'path' => 'foo/path.pdf') ) );
        $this->_db->expects($this->once())->method('removeSiteUnknownPathById')
            ->with('bitsavers', 1);
        $this->_urlInfo->expects($this->once())->method('exists')->willReturn(false);
        $this->_factory->expects($this->once())->method('createUrlInfo')
            ->with('http://bitsavers.trailing-edge.com/pdf/foo/path.pdf')
            ->willReturn($this->_urlInfo);

        $this->_cleaner->removeNonExistentUnknownPaths();
    }

    public function testExistingPathsAreKept()
    {
        $this->_db->expects($this->once())->method('getAllSiteUnknownPaths')
            ->willReturn(array(
                array('id' => 1, 'path' => 'foo/path.pdf')
            ));
        $this->_urlInfo->expects($this->once())->method('exists')->willReturn(true);
        $this->_factory->expects($this->once())->method('createUrlInfo')->willReturn($this->_urlInfo);

        $this->_cleaner->removeNonExistentUnknownPaths();
    }

    public function testPathsEscapeSpecialChars()
    {
        $this->_db->expects($this->once())->method('getAllSiteUnknownPaths')
            ->willReturn(array(
                array('id' => 1, 'path' => 'foo/path#1.pdf')
            ));
        $this->_urlInfo->expects($this->once())->method('exists')->willReturn(true);
        $this->_factory->expects($this->once())->method('createUrlInfo')
            ->with('http://bitsavers.trailing-edge.com/pdf/foo/path%231.pdf')
            ->willReturn($this->_urlInfo);

        $this->_cleaner->removeNonExistentUnknownPaths();
    }

    public function testMovedFilesAreUpdated()
    {
        $md5 = '37e10bd2e8da6bd96eb3a72feeea56ee';
        $this->_db->expects($this->once())->method('getPossiblyMovedSiteUnknownPaths')
            ->with('bitsavers')
            ->willReturn( array(
                array('path' => 'hp/newDir/foo.pdf', 'path_id' => 16,
                    'url' => 'http://bitsavers.org/pdf/hp/foo.pdf', 'copy_id' => 10, 'md5' => $md5)
            ));
        $this->_db->expects($this->once())->method('siteFileMoved')
            ->with('bitsavers', 10, 16, 'http://bitsavers.org/pdf/hp/newDir/foo.pdf');
        $this->_urlInfo->expects($this->once())->method('md5')->willReturn($md5);
        $this->_factory->expects($this->once())->method('createUrlInfo')
            ->with('http://bitsavers.trailing-edge.com/pdf/hp/newDir/foo.pdf')
            ->willReturn($this->_urlInfo);

        $this->_cleaner->updateMovedFiles();
    }

    public function testRemoveUnknownPathsWithCopy()
    {
        $this->_db->expects($this->once())->method('removeUnknownPathsWithCopy');
        $this->_logger->expects($this->once())->method('log');

        $this->_cleaner->removeUnknownPathsWithCopy();
    }

    private function bitsaversMetaData($siteId, $companyId, $url)
    {
        return [
            'url' => $url,
            'mirror_url' => '',
            'size' => 5555,
            'valid' => true,
            'site' => [
                'site_id' => $siteId,
                'name' => 'bitsavers',
                'url' => 'http://bitsavers.org',
                'description' => '',
                'copy_base' => 'http://bitsavers.org/pdf/',
                'low' => 'N',
                'live' => 'Y',
                'display_order' => 1
            ],
            'company' => $companyId,
            'part' => 'EK-3333-01',
            'pub_date' => '1977-02',
            'title' => 'Jumbotron Users Guide',
            'format' => 'PDF',
            'site_company_directory' => 'dec',
            'pubs' => [],
        ];
    }

    private function stockPubData()
    {
        return [
            'pub_type' => 'D',
            'alt_part' => '',
            'revision' => '',
            'keywords' => '',
            'notes' => '',
            'abstract' => '',
            'languages' => ''
        ];
    }

    private function stockCopyData()
    {
        return [
            'notes' => '',
            'md5' => '',
            'credits' => '',
            'amend_serial' => ''
        ];
    }

    public function testIngest()
    {
        $companyId = 13;
        $siteId = 3;
        $url = 'http://bitsavers.org/pdf/dec/foo/EK-3333-01_Jumbotron_Users_Guide_Feb1977.pdf';
        $pathRows = DatabaseTester::createResultRowsForColumns(['site_id', 'company_id', 'url'],
            [
                [$siteId, $companyId, $url ]
            ]);
        $data = $this->bitsaversMetaData($siteId, $companyId, $url);
        $pubData = $this->stockPubData();
        $copyData = $this->stockCopyData();
        $user = $this->createMock(IUser::class);
        $this->_manx->expects($this->once())->method('getUserFromSession')->willReturn($user);
        $this->_urlMetaData->expects($this->once())->method('determineData')->with($url)->willReturn($data);
        $this->_db->expects($this->once())->method('getUnknownPathsForCompanies')->willReturn($pathRows);
        $pubId = 23;
        $this->_manx->expects($this->once())->method('addPublication')
            ->with($user, $companyId, $data['part'], $data['pub_date'],
                $data['title'], $pubData['pub_type'], $pubData['alt_part'], $pubData['revision'],
                $pubData['keywords'], $pubData['notes'], $pubData['abstract'], $pubData['languages'])
            ->willReturn($pubId);
        $this->_db->expects($this->once())->method('addCopy')->with($pubId, $data['format'], $siteId, $url,
                $copyData['notes'], $data['size'], $copyData['md5'], $copyData['credits'], $copyData['amend_serial']);
        $this->_logger->expects($this->once())->method('log');

        $this->_cleaner->ingest();
    }

    public function testIngestCopyExists()
    {
        $companyId = 13;
        $siteId = 3;
        $url = 'http://bitsavers.org/pdf/dec/foo/EK-3333-01_Jumbotron_Users_Guide_Feb1977.pdf';
        $pathRows = DatabaseTester::createResultRowsForColumns(['site_id', 'company_id', 'url'],
            [
                [$siteId, $companyId, $url ]
            ]);
        $data = $this->bitsaversMetaData($siteId, $companyId, $url);
        $data['exists'] = true;
        $pubId = 23;
        $data['ph_pub'] = $pubId;
        $pubData = $this->stockPubData();
        $copyData = $this->stockCopyData();
        $user = $this->createMock(IUser::class);
        $this->_manx->expects($this->once())->method('getUserFromSession')->willReturn($user);
        $this->_urlMetaData->expects($this->once())->method('determineData')->with($url)->willReturn($data);
        $this->_db->expects($this->once())->method('getUnknownPathsForCompanies')->willReturn($pathRows);
        $this->_manx->expects($this->never())->method('addPublication');
        $this->_db->expects($this->never())->method('addCopy');
        $this->_logger->expects($this->never())->method('log');

        $this->_cleaner->ingest();
    }

    public function testUpdateWhatsNewNotNewer()
    {
        $this->_whatsNewIndex->expects($this->once())->method('needIndexByDateFile')->willReturn(false);
        $this->_whatsNewIndex->expects($this->never())->method('getIndexByDateFile');
        $this->_whatsNewIndex->expects($this->never())->method('parseIndexByDateFile');

        $this->_cleaner->updateWhatsNewIndex();
    }

    public function testUpdateWhatsNew()
    {
        $this->_whatsNewIndex->expects($this->once())->method('needIndexByDateFile')->willReturn(true);
        $this->_whatsNewIndex->expects($this->once())->method('getIndexByDateFile');
        $this->_whatsNewIndex->expects($this->once())->method('parseIndexByDateFile');
        $this->_logger->expects($this->once())->method('log');

        $this->_cleaner->updateWhatsNewIndex();
    }
}
