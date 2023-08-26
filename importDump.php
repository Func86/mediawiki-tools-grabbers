<?php
/**
 * Maintenance script to grab revisions from a wiki and import it to another wiki.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Settings\SettingsBuilder;

require_once 'includes/TextGrabber.php';
require_once 'includes/WikiImporter.php';

class ImportDump extends TextGrabber {

	/** @var int */
	public $reportingInterval = 100;
	/** @var int */
	public $pageCount = 0;
	/** @var int */
	public $revCount = 0;
	/** @var bool */
	public $dryRun = false;
	/** @var array|false */
	public $nsFilter = false;
	/** @var resource|false */
	public $stderr;
	/** @var float */
	protected $startTime;

	public function __construct() {
		parent::__construct();
		$gz = in_array( 'compress.zlib', stream_get_wrappers() )
			? 'ok'
			: '(disabled; requires PHP zlib module)';
		$bz2 = in_array( 'compress.bzip2', stream_get_wrappers() )
			? 'ok'
			: '(disabled; requires PHP bzip2 module)';

		$this->addDescription(
			<<<TEXT
This script reads pages from an XML file as produced from Special:Export or
dumpBackup.php, and saves them into the current wiki.

Compressed XML files may be read directly:
  .gz $gz
  .bz2 $bz2
  .7z (if 7za executable is in PATH)

Note that for very large data sets, importDump.php may be slow; there are
alternate methods which can be much faster for full site restoration:
<https://www.mediawiki.org/wiki/Manual:Importing_XML_dumps>
TEXT
		);
		$this->stderr = fopen( "php://stderr", "wt" );
		$this->addOption( 'report',
			'Report position and speed after every n pages processed', false, true );
		$this->addOption( 'namespaces',
			'Import only the pages from namespaces belonging to the list of ' .
			'pipe-separated namespace names or namespace indexes', false, true );
		$this->addOption( 'dry-run', 'Parse dump without actually importing pages' );
		$this->addOption( 'debug', 'Output extra verbose debug information' );
		$this->addOption( 'skip-to', 'Start from nth page by skipping first n-1 pages', false, true );
		$this->addArg( 'file', 'Dump file to import [else use stdin]', false );
	}

	public function finalSetup( SettingsBuilder $settingsBuilder = null ) {
		parent::finalSetup( $settingsBuilder );
		SevenZipStream::register();
	}

	public function execute() {
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			$this->fatalError( "Wiki is in read-only mode; you'll need to disable it for import to work." );
		}
		parent::execute();

		$this->reportingInterval = intval( $this->getOption( 'report', 100 ) );
		if ( !$this->reportingInterval ) {
			// avoid division by zero
			$this->reportingInterval = 100;
		}

		$this->dryRun = $this->hasOption( 'dry-run' );
		$this->uploads = $this->hasOption( 'uploads' );

		if ( $this->hasOption( 'namespaces' ) ) {
			$this->setNsfilter( explode( '|', $this->getOption( 'namespaces' ) ) );
		}

		if ( $this->hasArg( 0 ) ) {
			$this->importFromFile( $this->getArg( 0 ) );
		} else {
			$this->importFromStdin();
		}

		$this->output( "Done!\n" );
		$this->output( "You might want to run rebuildrecentchanges.php to regenerate RecentChanges,\n" );
		$this->output( "and initSiteStats.php to update page and revision counts\n" );
	}

	private function importFromFile( $filename ) {
		if ( preg_match( '/\.gz$/', $filename ) ) {
			$filename = 'compress.zlib://' . $filename;
		} elseif ( preg_match( '/\.bz2$/', $filename ) ) {
			$filename = 'compress.bzip2://' . $filename;
		} elseif ( preg_match( '/\.7z$/', $filename ) ) {
			$filename = 'mediawiki.compress.7z://' . $filename;
		}

		$file = fopen( $filename, 'rt' );
		if ( $file === false ) {
			$this->fatalError( error_get_last()['message'] ?? 'Could not open file' );
		}

		return $this->importFromHandle( $file );
	}

	private function importFromStdin() {
		$file = fopen( 'php://stdin', 'rt' );
		if ( self::posix_isatty( $file ) ) {
			$this->maybeHelp( true );
		}

		return $this->importFromHandle( $file );
	}

	private function importFromHandle( $handle ) {
		$this->startTime = microtime( true );

		$source = new ImportStreamSource( $handle );
		$importer = new WikiImportHandler( $source );

		if ( $this->hasOption( 'debug' ) ) {
			$importer->setDebug( true );
		}
		if ( $this->hasOption( 'skip-to' ) ) {
			$nthPage = (int)$this->getOption( 'skip-to' );
			$importer->setPageOffset( $nthPage );
			$this->pageCount = $nthPage - 1;
		}
		$importer->setPageCallback( [ $this, 'handlePage' ] );
		$importer->setNoticeCallback( static function ( $msg, $params ) {
			echo wfMessage( $msg, $params )->text() . "\n";
		} );
		$this->importCallback = $importer->setRevisionCallback(
			[ $this, 'handleRevision' ] );
		$this->importCallback = $importer->setPageOutCallback(
			[ $this, 'handlePageOut' ] );

		return $importer->doImport();
	}

	private function setNsfilter( array $namespaces ) {
		if ( count( $namespaces ) == 0 ) {
			$this->nsFilter = false;

			return;
		}
		$this->nsFilter = array_unique( array_map( [ $this, 'getNsIndex' ], $namespaces ) );
	}

	private function getNsIndex( $namespace ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$result = $contLang->getNsIndex( $namespace );
		if ( $result !== false ) {
			return $result;
		}
		$ns = intval( $namespace );
		if ( strval( $ns ) === $namespace && $contLang->getNsText( $ns ) !== false ) {
			return $ns;
		}
		$this->fatalError( "Unknown namespace text / index specified: $namespace" );
	}

	/**
	 * @param int $ns
	 * @return bool
	 */
	private function skippedNamespace( $ns ) {
		return is_array( $this->nsFilter ) && !in_array( $ns, $this->nsFilter );
	}

	public function handlePage( $pageInfo ) {
		if ( $this->skippedNamespace( (int)$pageInfo['ns'] ) ) {
			return false;
		}

		$this->pageCount++;
	}

	private function report( $final = false ) {
		if ( $final xor ( $this->pageCount % $this->reportingInterval == 0 ) ) {
			$this->showReport();
		}
	}

	private function showReport() {
		if ( !$this->mQuiet ) {
			$delta = microtime( true ) - $this->startTime;
			if ( $delta ) {
				$rate = sprintf( "%.2f", $this->pageCount / $delta );
				$revrate = sprintf( "%.2f", $this->revCount / $delta );
			} else {
				$rate = '-';
				$revrate = '-';
			}
			# Logs dumps don't have page tallies
			if ( $this->pageCount ) {
				$this->progress( "$this->pageCount ($rate pages/sec $revrate revs/sec)" );
			} else {
				$this->progress( "$this->revCount ($revrate revs/sec)" );
			}
		}
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
	}

	private function progress( $string ) {
		fwrite( $this->stderr, $string . "\n" );
	}

	public function handleRevision( array $pageInfo, array $revisionInfo ) {
		$this->revCount++;
		$this->report();

		if ( !$this->dryRun ) {
			return $this->insertRevision( $pageInfo, $revisionInfo );
		}
	}

	private function insertRevision( array $pageInfo, array $revisionInfo ) {
		$user = $revisionInfo['contributor'];
		$userName = $user['username'] ?? $user['ip'] ?? '';
		$revision = [
			'revid' => $revisionInfo['id'],
			'parentid' => $revisionInfo['parentid'] ?? 0,
			'minor' => $revisionInfo['minor'] ?? null,
			'user' => $userName,
			'userid' => $user['id'] ?? 0,
			'timestamp' => $revisionInfo['timestamp'],
			'sha1' => $revisionInfo['sha1'],
			'contentmodel' => $revisionInfo['model'],
			'contentformat' => $revisionInfo['format'] ?? null,
			'*' => $revisionInfo['text'],
			'comment' => $revisionInfo['comment'] ?? '',
			'texthidden' => $revisionInfo['deleted']['text'] ?: null,
			'userhidden' => $revisionInfo['deleted']['contributor'] ?: null,
			'commenthidden' => $revisionInfo['deleted']['comment'] ?: null,
		];

		$pageIdent = PageIdentityValue::localIdentity(
			$pageInfo['id'], $pageInfo['ns'],
			$this->sanitiseTitle( $pageInfo['ns'], $pageInfo['title'] )
		);
		return $this->processRevision( $revision, $pageInfo['id'], $pageIdent );
	}

	public function handlePageOut( array $pageInfo, array $revisionInfo ) {
		$pageID = $pageInfo['id'];
		$page_e = [
			'namespace' => $pageInfo['ns'],
			'title' => $this->sanitiseTitle( $pageInfo['ns'], $pageInfo['title'] ),
			'is_redirect' => ( isset( $pageInfo['redirect'] ) ? 1 : 0 ),
			'is_new' => 0,
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => strlen( $revisionInfo['text'] ),
			'latest' => $revisionInfo['id'],
			'content_model' => null
		];
		# We kind of need this to resume...
		// $this->output( "Title: {$page_e['title']} in namespace {$page_e['namespace']}\n" );
		// $title = Title::makeTitle( $page_e['namespace'], $page_e['title'] );

		if ( isset( $revisionInfo['model'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			# $defaultModel = ContentHandler::getDefaultModelFor( $title );
			$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()
				->getNamespaceContentModel( $pageInfo['ns'] ) || CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $revisionInfo['model'] ) {
				$page_e['content_model'] = $revisionInfo['model'];
			}
		}

		# Check if page is present
		$pageIsPresent = false;
		$pageRow = $this->dbw->selectRow(
			'page',
			[ 'page_latest', 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageID ],
			__METHOD__
		);
		if ( $pageRow ) {
			$pageLatest = $pageRow->page_latest;
			$pageIsPresent = true;
		}

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		if ( !$pageIsPresent ||
			$pageRow->page_namespace != $page_e['namespace'] ||
			$pageRow->page_title != $page_e['title']
		) {
			$conflictingPageID = $this->getPageID( $page_e['namespace'], $page_e['title'] );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $page_e['namespace'], $page_e['title'], $pageID, $conflictingPageID );
				# The conflicting page can be deleted, e.g. moving over a redirect page.
				$pageLatest = $this->dbw->selectField(
					'page',
					'page_latest',
					[ 'page_id' => $pageID ],
					__METHOD__
				);
				$pageIsPresent = $pageLatest !== false;
			}
		}

		$insert_fields = [
			'page_namespace' => $page_e['namespace'],
			'page_title' => $page_e['title'],
			'page_is_redirect' => $page_e['is_redirect'],
			'page_is_new' => $page_e['is_new'],
			'page_random' => $page_e['random'],
			'page_touched' => $page_e['touched'],
			'page_latest' => $page_e['latest'],
			'page_len' => $page_e['len'],
			'page_content_model' => $page_e['content_model']
		];
		if ( !$pageIsPresent ) {
			# insert if not present
			$this->output( "Inserting page entry $pageID\n" );
			$insert_fields['page_id'] = $pageID;
			$this->dbw->insert(
				'page',
				$insert_fields,
				__METHOD__
			);
		} elseif ( (int)$pageLatest < (int)$revisionInfo['id'] ) {
			# update existing
			$this->output( "Updating page entry $pageID\n" );
			$this->dbw->update(
				'page',
				$insert_fields,
				[ 'page_id' => $pageID ],
				__METHOD__
			);
		} else {
			// $this->output( "No need to update page entry for $pageID\n" );
		}
	}
}

$maintClass = ImportDump::class;
require_once RUN_MAINTENANCE_IF_MAIN;
