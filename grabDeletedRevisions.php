<?php
/**
 * Maintenance script to grab revisions from a wiki and import it to another wiki.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

require_once 'includes/TextGrabber.php';

class GrabDeletedRevisions extends TextGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Grab revisions from an external wiki and import it into one of ours.\n" .
			"Don't use this on a large wiki unless you absolutely must; it will be incredibly slow." );
		$this->addOption( 'arvstart', 'Timestamp at which to continue, useful to grab new revisions', false, true );
		$this->addOption( 'arvend', 'Timestamp at which to end', false, true );
		$this->addOption( 'adrcontinue', 'The adrcontinue param for API', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );
/*
		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics|namespacealiases'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$grabFromAllNamespaces = false;
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			foreach ( $textNamespaces as $idx => $ns ) {
				# Ignore special
				if ( $ns < 0 || !isset( $siteinfo['namespaces'][$ns] ) ) {
					unset( $textNamespaces[$idx] );
				}
			}
			$textNamespaces = array_values( $textNamespaces );
		} else {
			# List all existing NS, in case unused ones cause errors when using the dump
			$grabFromAllNamespaces = true;
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces' );
		}
*/
		$grabFromAllNamespaces = true;
		$textNamespaces = explode( '|', '0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|274|275|710|711|828|829|2300|2301|2302|2303' );
		if ( $grabFromAllNamespaces ) {
			# Get list of live pages from namespaces and continue from there
			$revCount = $siteinfo['statistics']['edits'] ?? 114514;
			$this->output( "Generating revision list from all namespaces - $revCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating revision list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$arvstart = $this->getOption( 'arvstart' );
		$arvend = $this->getOption( 'arvend' );

		$pageCount = $this->processRevisionsFromNamespaces( implode( '|', $textNamespaces ), $arvstart - 1, $arvend );
		$this->output( "\nDone - updated $pageCount total pages.\n" );
		# Done.
	}

	protected function checkRevisionExists( $revId ) {
		$arvRow = $this->dbw->newSelectQueryBuilder()
			->select( 'ar_rev_id' )
			->from( 'archive' )
			->where( [ 'ar_rev_id' => $revId ] )
			->caller( __METHOD__ )->fetchField();

		if ( $arvRow ) {
			return 'archive';
		}

		$revRow = $this->dbw->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->where( [ 'rev_id' => $revId ] )
			->caller( __METHOD__ )->fetchField();

		return $revRow ? 'revision' : false;
	}

	/**
	 * Grabs all revisions from a given namespace
	 *
	 * @param string $ns Namespaces to process, separate by '|'.
	 * @param string $arvstart Timestamp to start from (optional).
	 * @param string $arvend Timestamp to end with (optional).
	 * @return int Number of pages processed.
	 */
	protected function processRevisionsFromNamespaces( $ns, $arvstart = null, $arvend = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );

		$params = [
			'list' => 'alldeletedrevisions',
			'adrlimit' => 'max',
			'adrdir' => 'newer', // Grab old revisions first
			'adrprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size|sha1',
			'adrnamespace' => $ns,
			'adrslots' => 'main',
			'formatversion' => 2,
		];
		if ( $arvstart ) {
			$params['arvstart'] = $arvstart;
		}
		if ( $arvend ) {
			$params['arvend'] = $arvend;
		}
		if ( $this->hasOption( 'adrcontinue' ) ) {
			$params['adrcontinue'] = $this->getOption( 'adrcontinue' );
		}

		$pageMap = [];
		$revisionCount = 0;
		$misserModeCount = 0;
		$lastTimestamp = '';
		while ( true ) {
			$result = $this->bot->query( $params );

			$pages = $result['query']['alldeletedrevisions'];
			// Deal with misser mode
			if ( $pages ) {
				$misserModeCount = $resultsCount = 0;
				foreach ( $pages as $pageInfo ) {
					$pageDBKey = $this->sanitiseTitle( $pageInfo['ns'], $pageInfo['title'] );
					$pageIdent = PageIdentityValue::localIdentity(
						$pageInfo['pageid'], $pageInfo['ns'], $pageDBKey
					);
					foreach ( $pageInfo['revisions'] as $revision ) {
						$existsTable = $this->checkRevisionExists( $revision['revid'] );
						if ( $existsTable ) {
							$this->output( "revid {$revision['revid']} is already in the $existsTable table, skipped.\n" );
							continue;
						}
						$this->insertArchivedRevision( $revision, $pageIdent );
						$resultsCount++;
						$lastTimestamp = $revision['timestamp'];
					}
					$pageMap[$pageInfo['pageid']] = true;
				}
				$revisionCount += $resultsCount;
				$this->output( "$resultsCount/$revisionCount, arvstart: $lastTimestamp\n" );
			} else {
				$misserModeCount++;
				$this->output( "No result in this query due to misser mode.\n" );
				// Just in case if too far to scroll
				if ( $lastTimestamp && $misserModeCount % 10 === 0 ) {
					$this->output( "Last arvstart: $lastTimestamp\n" );
				}
			}
			if ( !isset( $result['continue'] ) ) {
				break;
			}

			// Add continuation parameters
			$params = array_merge( $params, $result['continue'] );
		}

		$this->output( "$revisionCount revisions processed in namespace $ns.\n" );

		return count( $pageMap );
	}
}

$maintClass = GrabDeletedRevisions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
