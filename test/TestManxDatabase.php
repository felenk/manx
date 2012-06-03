<?php
	require_once 'PHPUnit/Framework.php';
	require_once 'ManxDatabase.php';
	require_once 'test/FakeDatabase.php';
	require_once 'test/FakeStatement.php';

	class TestManxDatabase extends PHPUnit_Framework_TestCase
	{
		private $_db;
		private $_manxDb;
		private $_statement;

		public function testConstruct()
		{
			$this->createInstance();
			$this->assertTrue(!is_null($this->_manxDb) && is_object($this->_manxDb));
		}

		public function testGetDocumentCount()
		{
			$query = "SELECT COUNT(*) FROM `PUB`";
			$this->configureCountForQuery(2, $query);
			$count = $this->_manxDb->getDocumentCount();
			$this->assertCountForQuery(2, $count, $query);
		}

		public function testGetOnlineDocumentCount()
		{
			$query = "SELECT COUNT(DISTINCT `pub`) FROM `COPY`";
			$this->configureCountForQuery(12, $query);
			$count = $this->_manxDb->getOnlineDocumentCount();
			$this->assertCountForQuery(12, $count, $query);
		}

		public function testGetSiteCount()
		{
			$query = "SELECT COUNT(*) FROM `SITE`";
			$this->configureCountForQuery(43, $query);
			$count = $this->_manxDb->getSiteCount();
			$this->assertCountForQuery(43, $count, $query);
		}

		public function testGetSiteList()
		{
			$this->createInstance();
			$query = "SELECT `url`,`description`,`low` FROM `SITE` WHERE `live`='Y' ORDER BY `siteid`";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
					array('url', 'description', 'low'),
					array(array('http://www.dec.com', 'DEC', false), array('http://www.hp.com', 'HP', true))));
			$this->_db->queryFakeResultsForQuery[$query] = $this->_statement;
			$sites = $this->_manxDb->getSiteList();
			$this->assertQueryCalledForSql($query);
			$this->assertEquals(2, count($sites));
			$this->assertColumnValuesForRows($sites, 'url', array('http://www.dec.com', 'http://www.hp.com'));
		}

		public function testGetCompanyList()
		{
			$this->createInstance();
			$query = "SELECT `id`,`name` FROM `COMPANY` WHERE `display` = 'Y' ORDER BY `sort_name`";
			$expected = array(
					array('id' => 1, 'name' => "DEC"),
					array('id' => 2, 'name' => "HP"));
			$this->configureStatementFetchAllResults($query, $expected);
			$companies = $this->_manxDb->getCompanyList();
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($expected, $companies);
		}

		public function testGetDisplayLanguage()
		{
			$this->createInstance();
			$query = "SELECT IF(LOCATE(';',`eng_lang_name`),LEFT(`eng_lang_name`,LOCATE(';',`eng_lang_name`)-1),`eng_lang_name`) FROM `LANGUAGE` WHERE `lang_alpha_2`='fr'";
			$this->configureStatementFetchResult($query, 'French');
			$display = $this->_manxDb->getDisplayLanguage('fr');
			$this->assertQueryCalledForSql($query);
			$this->assertEquals('French', $display);
		}

		public function testGetOSTagsForPub()
		{
			$this->createInstance();
			$query = "SELECT `tag_text` FROM `TAG`,`PUBTAG` WHERE `TAG`.`id`=`PUBTAG`.`tag` AND `TAG`.`class`='os' AND `pub`=5";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(array('tag_text'),
					array(array('RSX-11M Version 4.0'), array('RSX-11M-PLUS Version 2.0'))));
			$tags = $this->_manxDb->getOSTagsForPub(5);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($tags, array('RSX-11M Version 4.0', 'RSX-11M-PLUS Version 2.0'));
		}

		public function testGetAmendmentsForPub()
		{
			$this->createInstance();
			$query = "SELECT `ph_company`,`ph_pub`,`ph_part`,`ph_title`,`ph_pubdate` "
				. "FROM `PUB` JOIN `PUBHISTORY` ON `pub_id` = `ph_pub` WHERE `ph_amend_pub`=3 ORDER BY `ph_amend_serial`";
			$pubId = 3;
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
					array('ph_company', 'ph_pub', 'ph_part', 'ph_title', 'ph_pubdate'),
					array(array(1, 4496, 'DEC-15-YWZA-DN1', 'DDT (Dynamic Debugging Technique) Utility Program', '1970-04'),
						array(1, 3301, 'DEC-15-YWZA-DN3', 'SGEN System Generator Utility Program', '1970-09'))));
			$amendments = $this->_manxDb->getAmendmentsForPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertArrayHasLength($amendments, 2);
			$this->assertColumnValuesForRows($amendments, 'ph_pub', array(4496, 3301));
		}

		public function testGetLongDescriptionForPubDoesNothing()
		{
			$this->createInstance();
			$pubId = 3;
			$query = "SELECT 'html_text' FROM `LONG_DESC` WHERE `pub`=3 ORDER BY `line`";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(array('html_text'),
					array(array('<p>This is paragraph one.</p>'), array('<p>This is paragraph two.</p>'))));
			$longDescription = $this->_manxDb->getLongDescriptionForPub($pubId);
			$this->assertFalse($this->_db->queryCalled);
			/*
			TODO: LONG_DESC table missing
			$this->assertQueryCalledForSql($query);
			$this->assertEquals(array('<p>This is paragraph one.</p>', '<p>This is paragraph two.</p>'), $longDescription);
			*/
		}

		public function testGetCitationsForPub()
		{
			$this->createInstance();
			$pubId = 72;
			$query = 'SELECT `ph_company`,`ph_pub`,`ph_part`,`ph_title` '
				. 'FROM `CITEPUB` `C`'
				. ' JOIN `PUB` ON (`C`.`pub`=`pub_id` AND `C`.`mentions_pub`=72)'
				. ' JOIN `PUBHISTORY` ON `pub_history`=`ph_id`';
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
					array('ph_company', 'ph_pub', 'ph_part', 'ph_title'),
					array(array(1, 123, 'EK-306AA-MG-001', 'KA655 CPU System Maintenance'))));
			$citations = $this->_manxDb->getCitationsForPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertArrayHasLength($citations, 1);
			$this->assertEquals('EK-306AA-MG-001', $citations[0]['ph_part']);
		}

		public function testGetTableOfContentsForPubFullContents()
		{
			$this->createInstance();
			$pubId = 123;
			$query = "SELECT `level`,`label`,`name` FROM `TOC` WHERE `pub`=123 ORDER BY `line`";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
					array('level', 'label', 'name'),
					array(
						array(1, 'Chapter 2', 'Configuration'),
						array(2, '2.4', 'DSSI Configuration'),
						array(3, '2.4.4', 'DSSI Cabling'),
						array(4, '2.4.4.1', 'DSSI Bus Termination and Length'),
						array(1, 'Appendix C', 'Related Documentation'))));
			$toc = $this->_manxDb->getTableOfContentsForPub($pubId, true);
			$this->assertQueryCalledForSql($query);
			$this->assertArrayHasLength($toc, 5);
			$this->assertColumnValuesForRows($toc, 'label',
				array('Chapter 2', '2.4', '2.4.4', '2.4.4.1', 'Appendix C'));
		}

		public function testGetTableOfContentsForPubAbbreviatedContents()
		{
			$this->createInstance();
			$pubId = 123;
			$query = "SELECT `level`,`label`,`name` FROM `TOC` WHERE `pub`=123 AND `level` < 2 ORDER BY `line`";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
					array('level', 'label', 'name'),
					array(
						array(1, 'Chapter 1', 'KA655 CPU and Memory Subsystem'),
						array(1, 'Chapter 2', 'Configuration'),
						array(1, 'Chapter 3', 'KA655 Firmware'),
						array(1, 'Chapter 4', 'Troubleshooting and Diagnostics'),
						array(1, 'Appendix A', 'Configuring the KFQSA'),
						array(1, 'Appendix B', 'KA655 CPU Address Assignments'),
						array(1, 'Appendix C', 'Related Documentation'))
				));
			$toc = $this->_manxDb->getTableOfContentsForPub($pubId, false);
			$this->assertQueryCalledForSql($query);
			$this->assertArrayHasLength($toc, 7);
			$this->assertColumnValuesForRows($toc, 'label',
				array('Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 4', 'Appendix A', 'Appendix B', 'Appendix C'));
		}

		public function testGetMirrorsForCopy()
		{
			$this->createInstance();
			$copyId = 7165;
			$query = "SELECT REPLACE(`url`,`original_stem`,`copy_stem`) AS `mirror_url`"
					. " FROM `COPY` JOIN `mirror` ON `COPY`.`site`=`mirror`.`site`"
					. " WHERE `copyid`=7165 ORDER BY `rank` DESC";
			$expected = array('http://bitsavers.trailing-edge.com/pdf/dec/vax/655/EK-306A-MG-001_655Mnt_Mar89.pdf',
				'http://www.bighole.nl/pub/mirror/www.bitsavers.org/pdf/dec/vax/655/EK-306A-MG-001_655Mnt_Mar89.pdf',
				'http://www.textfiles.com/bitsavers/pdf/dec/vax/655/EK-306A-MG-001_655Mnt_Mar89.pdf',
				'http://computer-refuge.org/bitsavers/pdf/dec/vax/655/EK-306A-MG-001_655Mnt_Mar89.pdf',
				'http://www.mirrorservice.org/sites/www.bitsavers.org/pdf/dec/vax/655/EK-306A-MG-001_655Mnt_Mar89.pdf');
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(array('mirror_url'),
					array(array($expected[0]), array($expected[1]), array($expected[2]), array($expected[3]), array($expected[4]))));
			$mirrors = $this->_manxDb->getMirrorsForCopy($copyId);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($expected, $mirrors);
		}

		public function testGetAmendedPub()
		{
			$this->createInstance();
			$pubId = 17970;
			$amendSerial = 7;
			$query = sprintf("SELECT `ph_company`,`pub_id`,`ph_part`,`ph_title`,`ph_pubdate`"
						. " FROM `PUB` JOIN `PUBHISTORY` ON `pub_history`=`ph_id`"
						. " WHERE `ph_amend_pub`=%d AND `ph_amend_serial`=%d", $pubId, $amendSerial);
			$expected = array('ph_company' => 7, 'pub_id' => 57, 'ph_part' => 'AB81-14G',
					'ph_title' => 'Honeywell Publications Catalog Addendum G', 'ph_pubdate' => '1984-02');
			$this->configureStatementFetchResult($query, $expected);
			$amended = $this->_manxDb->getAmendedPub($pubId, $amendSerial);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($expected, $amended);
		}

		public function testGetCopiesForPub()
		{
			$this->createInstance();
			$pubId = 123;
			$query = "SELECT `format`,`COPY`.`url`,`notes`,`size`,"
				. "`SITE`.`name`,`SITE`.`url` AS `site_url`,`SITE`.`description`,"
				. "`SITE`.`copy_base`,`SITE`.`low`,`COPY`.`md5`,`COPY`.`amend_serial`,"
				. "`COPY`.`credits`,`copyid`"
				. " FROM `COPY`,`SITE`"
				. " WHERE `COPY`.`site`=`SITE`.`siteid` AND `pub`=123"
				. " ORDER BY `SITE`.`display_order`,`SITE`.`siteid`";
			$this->configureStatementFetchAllResults($query,
				FakeDatabase::createResultRowsForColumns(
				array('format', 'url', 'notes', 'size', 'name', 'site_url', 'description', 'copy_base', 'low', 'md5', 'amend_serial', 'credits', 'copyid'),
				array(
					array('PDF', 'http://bitsavers.org/pdf/honeywell/AB81-14_PubsCatalog_May83.pdf', NULL, 25939827, 'bitsavers', 'http://bitsavers.org/', "Al Kossow's Bitsavers", 'http://bitsavers.org/pdf/', 'N', '0f91ba7f8d99ce7a9b57f9fdb07d3561', 7, NULL, 10277)
					)));
			$copies = $this->_manxDb->getCopiesForPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertArrayHasLength($copies, 1);
			$this->assertEquals('http://bitsavers.org/pdf/honeywell/AB81-14_PubsCatalog_May83.pdf', $copies[0]['url']);
		}

		public function testGetDetailsForPub()
		{
			$this->createInstance();
			$pubId = 3;
			$query = 'SELECT `pub_id`, `COMPANY`.`name`, '
				. 'IFNULL(`ph_part`, "") AS `ph_part`, `ph_pubdate`, '
				. '`ph_title`, `ph_abstract`, '
				. 'IFNULL(`ph_revision`, "") AS `ph_revision`, `ph_ocr_file`, '
				. '`ph_cover_image`, `ph_lang`, `ph_keywords` '
				. 'FROM `PUB` '
				. 'JOIN `PUBHISTORY` ON `pub_history`=`ph_id` '
				. 'JOIN `COMPANY` ON `ph_company`=`COMPANY`.`id` '
				. 'WHERE 1=1 AND `pub_id`=3';
			$rows = FakeDatabase::createResultRowsForColumns(
				array('pub_id', 'name', 'ph_part', 'ph_pubdate', 'ph_title', 'ph_abstract', 'ph_revision', 'ph_ocr_file', 'ph_cover_image', 'ph_lang', 'ph_keywords'),
				array(array(3, 'Digital Equipment Corporation', 'AA-K336A-TK', NULL, 'GIGI/ReGIS Handbook', NULL, '', NULL, 'gigi_regis_handbook.png', '+en', 'VK100')));
			$this->configureStatementFetchResult($query, $rows[0]);
			$details = $this->_manxDb->getDetailsForPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($rows[0], $details);
		}

		public function testNormalizePartNumberNotString()
		{
			$this->assertEquals('', ManxDatabase::normalizePartNumber(array()));
		}

		public function testNormalizePartNumberLowerCase()
		{
			$this->assertEquals('UC', ManxDatabase::normalizePartNumber('uc'));
		}

		public function testNormalizePartNumberNonAlphaNumeric()
		{
			$this->assertEquals('UC122', ManxDatabase::normalizePartNumber(' !u,c,1,2,2 ,./<>?;' . "'" . ':"[]{}\\|`~!@#$%^&*()'));
		}

		public function testNormalizePartNumberLetterOhIsZero()
		{
			$this->assertEquals('UC1220', ManxDatabase::normalizePartNumber(' !u,c,1,2,2,o ,./<>?;' . "'" . ':"[]{}\\|`~!@#$%^&*()'));
		}

		public function testCleanSqlWordNotString()
		{
			$this->assertEquals('', ManxDatabase::cleanSqlWord(array()));
		}

		public function testCleanSqlWordNoSpecials()
		{
			$this->assertEquals('cleanWord', ManxDatabase::cleanSqlWord('cleanWord'));
		}

		public function testCleanSqlWordPercent()
		{
			$this->assertEquals('percent\\%Word', ManxDatabase::cleanSqlWord('percent%Word'));
		}

		public function testCleanSqlWordQuote()
		{
			$this->assertEquals("quote\\'Word", ManxDatabase::cleanSqlWord("quote'Word"));
		}

		public function testCleanSqlWordUnderline()
		{
			$this->assertEquals('underline\\_Word', ManxDatabase::cleanSqlWord('underline_Word'));
		}

		public function testCleanSqlWordBackslash()
		{
			$this->assertEquals('backslash\\\\Word', ManxDatabase::cleanSqlWord('backslash\\Word'));
		}

		public function testMatchClauseForSearchWordsSingleKeyword()
		{
			$clause = ManxDatabase::matchClauseForSearchWords(array('terminal'));
			$this->assertEquals(" AND ((`ph_title` LIKE '%terminal%' OR `ph_keywords` LIKE '%terminal%' "
				. "OR `ph_match_part` LIKE '%TERMINAL%' OR `ph_match_alt_part` LIKE '%TERMINAL%'))", $clause);
		}

		public function testMatchClauseForMultipleKeywords()
		{
			$clause = ManxDatabase::matchClauseForSearchWords(array('graphics', 'terminal'));
			$this->assertEquals(" AND ((`ph_title` LIKE '%graphics%' OR `ph_keywords` LIKE '%graphics%' "
				. "OR `ph_match_part` LIKE '%GRAPHICS%' OR `ph_match_alt_part` LIKE '%GRAPHICS%') "
				. "AND (`ph_title` LIKE '%terminal%' OR `ph_keywords` LIKE '%terminal%' "
				. "OR `ph_match_part` LIKE '%TERMINAL%' OR `ph_match_alt_part` LIKE '%TERMINAL%'))", $clause);
		}

		public function testSearchForPublications()
		{
			$this->createInstance();
			$rows = array(
				array('pub_id' => 1, 'ph_part' => '', 'ph_title' => '', 'pub_has_online_copies' => '',
					'ph_abstract' => '', 'pub_has_toc' => '', 'pub_superseded' => '',
					'ph_pubdate' => '', 'ph_revision' => '', 'ph_company' => '', 'ph_alt_part' => '',
					'ph_pubtype' => '')
				);
			$keywords = array('graphics', 'terminal');
			$matchClause = ManxDatabase::matchClauseForSearchWords($keywords);
			$company = 1;
			$query = "SELECT `pub_id`, `ph_part`, `ph_title`,"
				. " `pub_has_online_copies`, `ph_abstract`, `pub_has_toc`,"
				. " `pub_superseded`, `ph_pubdate`, `ph_revision`,"
				. " `ph_company`, `ph_alt_part`, `ph_pubtype` FROM `PUB`"
				. " JOIN `PUBHISTORY` ON `pub_history` = `ph_id`"
				. " WHERE `pub_has_online_copies` $matchClause"
				. " AND `ph_company`=$company"
				. " ORDER BY `ph_sort_part`, `ph_pubdate`, `pub_id`";
			$this->configureStatementFetchAllResults($query, $rows);
			$pubs = $this->_manxDb->searchForPublications($company, $keywords, true);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($rows, $pubs);
		}

		public function testGetPublicationsSupersededByPub()
		{
			$this->createInstance();
			$pubId = 6105;
			$query = sprintf('SELECT `ph_company`,`ph_pub`,`ph_part`,`ph_title` FROM `SUPERSESSION`' .
				' JOIN `PUB` ON (`old_pub`=`pub_id` AND `new_pub`=%d)' .
				' JOIN `PUBHISTORY` ON `pub_history`=`ph_id`', $pubId);
			$rows = array(array('ph_company' => 1, 'ph_pub' => 23, 'ph_part' => 'EK-11024-TM-PRE', 'ph_title' => 'PDP-11/24 System Technical Manual'));
			$this->configureStatementFetchAllResults($query, $rows);
			$pubs = $this->_manxDb->getPublicationsSupersededByPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($rows, $pubs);
		}

		public function testGetPublicationsSupersedingPub()
		{
			$this->createInstance();
			$pubId = 23;
			$query = sprintf('SELECT `ph_company`,`ph_pub`,`ph_part`,`ph_title` FROM `SUPERSESSION`'
				. ' JOIN `PUB` ON (`new_pub`=`pub_id` AND `old_pub`=%d)'
				. ' JOIN `PUBHISTORY` ON `pub_history`=`ph_id`', $pubId);
			$rows = array(array('ph_company' => 1, 'ph_pub' => 6105, 'ph_part' => 'EK-11024-TM-001', 'ph_title' => 'PDP-11/24 System Technical Manual'));
			$this->configureStatementFetchAllResults($query, $rows);
			$pubs = $this->_manxDb->getPublicationsSupersedingPub($pubId);
			$this->assertQueryCalledForSql($query);
			$this->assertEquals($rows, $pubs);
		}

		private function assertColumnValuesForRows($rows, $column, $values)
		{
			$this->assertEquals(count($rows), count($values), "different number of expected values from the number of rows");
			$i = 0;
			foreach ($rows as $row)
			{
				$this->assertTrue(array_key_exists($column, $row), sprintf("row doesn't contain key '%s'", $column));
				$this->assertEquals($row[$column], $values[$i], "expected value doesn't match value in column");
				++$i;
			}
		}

		private function assertArrayHasLength($value, $length)
		{
			$this->assertTrue(is_array($value));
			$this->assertEquals($length, count($value));
		}

		private function createInstance()
		{
			$this->_db = new FakeDatabase();
			$this->_manxDb = ManxDatabase::getInstanceForDatabase($this->_db);
			$this->_statement = new FakeStatement();
		}

		private function configureCountForQuery($expectedCount, $query)
		{
			$this->createInstance();
			$this->configureStatementFetchResult($query, array($expectedCount));
		}

		private function assertCountForQuery($expectedCount, $count, $query)
		{
			$this->assertQueryCalledForSql($query);
			$this->assertTrue($this->_statement->fetchCalled);
			$this->assertEquals($expectedCount, $count);
		}

		private function assertQueryCalledForSql($sql)
		{
			$this->assertTrue($this->_db->queryCalled);
			$this->assertEquals($sql, $this->_db->queryLastStatement);
		}

		private function configureStatementFetchResult($query, $result)
		{
			$this->_statement->fetchFakeResult = $result;
			$this->_db->queryFakeResultsForQuery[$query] = $this->_statement;
		}

		private function configureStatementFetchAllResults($query, $results)
		{
			$this->_statement->fetchAllFakeResult = $results;
			$this->_db->queryFakeResultsForQuery[$query] = $this->_statement;
		}
	}
?>
